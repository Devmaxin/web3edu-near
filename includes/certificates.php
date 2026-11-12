<?php
/**
 * Web3edu — Certificate Management System v2
 *
 * Core library for the flexible, multi-type certificate system. Include
 * this where needed (config.php + gamification.php should already be
 * loaded). Every function here is safe to call multiple times.
 *
 * WHAT LIVES HERE
 *  - Certificate ID generation (WEB3-2026-000012 style, per type+year)
 *  - Template resolution + rendering (builder mode and custom-HTML mode)
 *  - issueCertificateV2()      — the one true issuance path
 *  - evaluateCertificateRules() — the automation engine (quiz score, XP
 *    level, badge earned, premium purchased, custom event)
 *  - Verify / revoke / reissue / regenerate / delete admin actions
 *  - Background upload (PNG/JPG/SVG) with SVG sanitization
 *
 * BACKWARD COMPATIBILITY
 *  includes/gamification.php still exposes issueCertificate($userId,
 *  $issuableType, $issuableId) for guide completions — it now delegates
 *  to issueCertificateV2() under the hood. Nothing that already calls it
 *  needs to change.
 */

// ============================================================
// CERTIFICATE TYPES
// ============================================================

function getCertificateTypes(bool $activeOnly = true): array
{
    $db = getDB();
    $sql = "SELECT * FROM certificate_types WHERE deleted_at IS NULL";
    if ($activeOnly) $sql .= " AND is_active = 1";
    $sql .= " ORDER BY sort_order ASC, label ASC";
    return $db->query($sql)->fetchAll();
}

function getCertificateType(int $id): ?array
{
    $stmt = getDB()->prepare("SELECT * FROM certificate_types WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function getCertificateTypeByKey(string $key): ?array
{
    $stmt = getDB()->prepare("SELECT * FROM certificate_types WHERE type_key = ? AND deleted_at IS NULL");
    $stmt->execute([$key]);
    return $stmt->fetch() ?: null;
}

// ============================================================
// CERTIFICATE ID GENERATION — e.g. WEB3-2026-000012
// ============================================================

/**
 * Atomically reserves the next sequence number for {prefix}-{year} and
 * returns the formatted certificate code. Uses INSERT ... ON DUPLICATE KEY
 * UPDATE so two simultaneous issuances never collide (MySQL row lock).
 */
function generateCertificateCode(string $prefix = 'WEB3'): string
{
    $db = getDB();
    $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $prefix)) ?: 'WEB3';
    $year = date('Y');
    $seqKey = $prefix . '-' . $year;

    $db->prepare("
        INSERT INTO certificate_sequences (seq_key, next_value) VALUES (?, 1)
        ON DUPLICATE KEY UPDATE next_value = next_value + 1
    ")->execute([$seqKey]);

    $stmt = $db->prepare("SELECT next_value FROM certificate_sequences WHERE seq_key = ?");
    $stmt->execute([$seqKey]);
    $n = (int)$stmt->fetchColumn();

    $code = $seqKey . '-' . str_pad((string)$n, 6, '0', STR_PAD_LEFT);

    // Extremely defensive: if this code somehow already exists (e.g. manual
    // data import), keep bumping until it's free rather than ever issuing a
    // duplicate certificate_code.
    $chk = $db->prepare("SELECT id FROM certificates WHERE certificate_code = ?");
    while (true) {
        $chk->execute([$code]);
        if (!$chk->fetch()) break;
        $db->prepare("UPDATE certificate_sequences SET next_value = next_value + 1 WHERE seq_key = ?")->execute([$seqKey]);
        $stmt->execute([$seqKey]);
        $n = (int)$stmt->fetchColumn();
        $code = $seqKey . '-' . str_pad((string)$n, 6, '0', STR_PAD_LEFT);
    }

    return $code;
}

// ============================================================
// TEMPLATES
// ============================================================

function getCertificateTemplates(?int $typeId = null, bool $activeOnly = false): array
{
    $db = getDB();
    $sql = "SELECT ct.*, cty.label AS type_label,
                   (SELECT COUNT(*) FROM certificates WHERE template_id = ct.id) AS issued_count
            FROM certificate_templates ct
            LEFT JOIN certificate_types cty ON cty.id = ct.certificate_type_id
            WHERE ct.deleted_at IS NULL";
    $params = [];
    if ($typeId) { $sql .= " AND ct.certificate_type_id = ?"; $params[] = $typeId; }
    if ($activeOnly) $sql .= " AND ct.is_active = 1";
    $sql .= " ORDER BY ct.is_default DESC, ct.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getCertificateTemplate(int $id): ?array
{
    $stmt = getDB()->prepare("SELECT * FROM certificate_templates WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Default template for a type, falling back to the global default (is_default=1, any type). */
function getDefaultTemplateForType(?int $typeId): ?array
{
    $db = getDB();
    if ($typeId) {
        $stmt = $db->prepare("SELECT * FROM certificate_templates WHERE certificate_type_id = ? AND is_default = 1 AND is_active = 1 AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$typeId]);
        if ($row = $stmt->fetch()) return $row;

        // No type-specific default — any active template of this type
        $stmt = $db->prepare("SELECT * FROM certificate_templates WHERE certificate_type_id = ? AND is_active = 1 AND deleted_at IS NULL ORDER BY created_at ASC LIMIT 1");
        $stmt->execute([$typeId]);
        if ($row = $stmt->fetch()) return $row;
    }
    $row = $db->query("SELECT * FROM certificate_templates WHERE is_default = 1 AND is_active = 1 AND deleted_at IS NULL LIMIT 1")->fetch();
    return $row ?: null;
}

// ============================================================
// BACKGROUNDS — PNG / JPG / SVG upload with SVG sanitization
// ============================================================

/**
 * Handles a certificate background upload. Unlike handleImageUpload()
 * (raster-only), this also accepts SVG. Returns the new
 * certificate_backgrounds.id on success, or null (with $errors populated)
 * on failure.
 */
function handleCertificateBackgroundUpload(string $inputName, array &$errors, ?int $adminId = null): ?int
{
    if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES[$inputName];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Upload error: ' . uploadErrorMessage($file['error']);
        return null;
    }
    if ($file['size'] > MAX_FILE_SIZE) {
        $errors[] = 'Background image must be under 5MB.';
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];

    // finfo sometimes reports SVGs as text/plain or text/html — sniff the
    // raw content for an <svg tag as a fallback before rejecting.
    if (!isset($allowed[$mime])) {
        $head = file_get_contents($file['tmp_name'], false, null, 0, 2048);
        if ($head !== false && stripos($head, '<svg') !== false) {
            $mime = 'image/svg+xml';
        }
    }

    if (!isset($allowed[$mime])) {
        $errors[] = 'Only PNG, JPG, and SVG backgrounds are allowed.';
        return null;
    }

    $ext = $allowed[$mime];
    $isSvg = $ext === 'svg';
    $dir = UPLOAD_PATH . 'certificates/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = 'bg_' . uniqid('', true) . '.' . $ext;
    $destPath = $dir . $filename;

    if ($isSvg) {
        $svg = file_get_contents($file['tmp_name']);
        if ($svg === false) { $errors[] = 'Could not read the uploaded SVG.'; return null; }
        $svg = sanitizeSvgBackground($svg);
        if (file_put_contents($destPath, $svg) === false) {
            $errors[] = 'Failed to save uploaded background.';
            return null;
        }
    } else {
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            $errors[] = 'Failed to save uploaded background.';
            return null;
        }
    }

    $relPath = 'certificates/' . $filename;

    $db = getDB();
    $db->prepare("INSERT INTO certificate_backgrounds (name, path, mime_type, is_svg, uploaded_by) VALUES (?,?,?,?,?)")
       ->execute([pathinfo($file['name'], PATHINFO_FILENAME), $relPath, $mime, $isSvg ? 1 : 0, $adminId]);

    return (int)$db->lastInsertId();
}

/**
 * Strips <script> tags, on*="" event-handler attributes, and javascript:
 * URLs from an uploaded SVG before it's saved and served inline as a
 * certificate background. SVG is XML with a scripting model, so this is
 * mandatory before letting admins upload arbitrary SVG files.
 */
function sanitizeSvgBackground(string $svg): string
{
    $svg = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $svg);
    $svg = preg_replace('#\son\w+\s*=\s*"[^"]*"#i', '', $svg);
    $svg = preg_replace("#\son\w+\s*=\s*'[^']*'#i", '', $svg);
    $svg = preg_replace('#(href|xlink:href)\s*=\s*"javascript:[^"]*"#i', '', $svg);
    $svg = preg_replace('#<!DOCTYPE[^>]*>#i', '', $svg);
    $svg = preg_replace('#<!ENTITY[^>]*>#i', '', $svg); // block XXE-style entity abuse
    return $svg;
}

function getCertificateBackground(int $id): ?array
{
    $stmt = getDB()->prepare("SELECT * FROM certificate_backgrounds WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function getCertificateBackgrounds(): array
{
    return getDB()->query("SELECT * FROM certificate_backgrounds WHERE deleted_at IS NULL ORDER BY created_at DESC")->fetchAll();
}

// ============================================================
// PLACEHOLDERS + RENDERING
// ============================================================

/**
 * Builds the full placeholder set for a certificate. $cert is a row from
 * certificates (joined or plain), $extra can override/add values
 * (e.g. ['guide_name' => ..., 'score' => 80]).
 */
function buildCertificatePlaceholders(array $cert, array $template, array $extra = []): array
{
    $username = $extra['username'] ?? ($cert['username'] ?? '');
    $vars = [
        'username'           => $username,
        'full_name'          => $extra['full_name'] ?? $username, // no separate full-name field on users
        'certificate_title'  => $extra['certificate_title'] ?? ($cert['achievement_title'] ?? $template['name'] ?? 'Certificate'),
        'guide_name'         => $extra['guide_name'] ?? ($extra['certificate_title'] ?? ''),
        'course_name'        => $extra['course_name'] ?? ($extra['certificate_title'] ?? ''),
        'learning_path'      => $extra['learning_path'] ?? ($extra['certificate_title'] ?? ''),
        'issue_date'         => date($template['issue_date_format'] ?? 'F j, Y', strtotime($cert['issued_at'] ?? 'now')),
        'completion_date'    => date($template['issue_date_format'] ?? 'F j, Y', strtotime($extra['completion_date'] ?? ($cert['issued_at'] ?? 'now'))),
        'certificate_id'     => $cert['certificate_code'] ?? '',
        'certificate_code'   => $cert['certificate_code'] ?? '', // legacy alias
        'date'               => date($template['issue_date_format'] ?? 'F j, Y', strtotime($cert['issued_at'] ?? 'now')), // legacy alias
        'title'              => $extra['certificate_title'] ?? ($cert['achievement_title'] ?? ''), // legacy alias
        'user_name'          => $username, // legacy alias
        'site_name'          => SITE_NAME,
        'site_url'           => SITE_URL,
        'verification_url'   => certificateVerifyUrl($cert['certificate_code'] ?? ''),
        'score'              => isset($extra['score']) ? (string)$extra['score'] : '',
    ];
    return array_merge($vars, $extra);
}

function certificateVerifyUrl(string $code): string
{
    return SITE_URL . '/certificate/verify.php?id=' . urlencode($code);
}

/** Public QR-code image URL for a verification link. No server-side dependency. */
function certificateQrUrl(string $verifyUrl, int $size = 200): string
{
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&data=' . urlencode($verifyUrl);
}

/**
 * Renders the certificate body HTML for a template + placeholder set.
 *  - render_mode = 'custom_html' -> renderTemplateString(html_template, vars)
 *    (fully backward compatible with pre-v2 templates)
 *  - render_mode = 'builder' (default) -> a clean, print-ready layout built
 *    from the structured fields (subtitle, body_text, footer_text,
 *    signatures, QR code).
 */
function renderCertificateBody(array $template, array $vars): string
{
    if (($template['render_mode'] ?? 'builder') === 'custom_html' && !empty($template['html_template'])) {
        return renderTemplateString($template['html_template'], $vars);
    }

    $title    = renderTemplateString($template['name'] ?? 'Certificate of Completion', $vars);
    $subtitle = renderTemplateString($template['subtitle'] ?? 'This certifies that', $vars);
    $body     = renderTemplateString(
        $template['body_text'] ?? 'has successfully completed {{certificate_title}}',
        $vars
    );
    $footer   = renderTemplateString($template['footer_text'] ?? '', $vars);
    $showQr   = !empty($template['show_qr']);
    $verifyUrl = !empty($template['verification_url_override'])
        ? renderTemplateString($template['verification_url_override'], $vars)
        : ($vars['verification_url'] ?? '');
    $logo = !empty($template['site_logo_override']) ? imageUrl($template['site_logo_override']) : (SITE_URL . '/assets/images/logo.png');

    ob_start();
    ?>
    <div class="cert-builder-layout">
      <div class="cert-builder-inner">
        <img class="cert-logo" src="<?= htmlspecialchars($logo, ENT_QUOTES) ?>" alt="" onerror="this.style.display='none'">
        <div class="cert-title"><?= $title ?></div>
        <div class="cert-subtitle"><?= $subtitle ?></div>
        <div class="cert-recipient"><?= htmlspecialchars($vars['full_name'] ?? $vars['username'] ?? '', ENT_QUOTES) ?></div>
        <div class="cert-body"><?= nl2br($body) ?></div>

        <div class="cert-meta-row">
          <div class="cert-meta-item"><span class="cert-meta-label">Issued</span><span class="cert-meta-value"><?= htmlspecialchars($vars['issue_date'] ?? '', ENT_QUOTES) ?></span></div>
          <div class="cert-meta-item"><span class="cert-meta-label">Certificate ID</span><span class="cert-meta-value cert-mono"><?= htmlspecialchars($vars['certificate_id'] ?? '', ENT_QUOTES) ?></span></div>
        </div>

        <div class="cert-sign-row">
          <?php if (!empty($template['signature1_name'])): ?>
          <div class="cert-sign">
            <div class="cert-sign-line"></div>
            <div class="cert-sign-name"><?= htmlspecialchars($template['signature1_name'], ENT_QUOTES) ?></div>
            <div class="cert-sign-title"><?= htmlspecialchars($template['signature1_title'] ?? '', ENT_QUOTES) ?></div>
          </div>
          <?php endif; ?>

          <?php if ($showQr && $verifyUrl): ?>
          <div class="cert-qr">
            <img src="<?= htmlspecialchars(certificateQrUrl($verifyUrl), ENT_QUOTES) ?>" alt="Verify" width="84" height="84">
            <div class="cert-qr-label">Scan to verify</div>
          </div>
          <?php endif; ?>

          <?php if (!empty($template['signature2_name'])): ?>
          <div class="cert-sign">
            <div class="cert-sign-line"></div>
            <div class="cert-sign-name"><?= htmlspecialchars($template['signature2_name'], ENT_QUOTES) ?></div>
            <div class="cert-sign-title"><?= htmlspecialchars($template['signature2_title'] ?? '', ENT_QUOTES) ?></div>
          </div>
          <?php endif; ?>
        </div>

        <?php if ($footer): ?><div class="cert-footer"><?= $footer ?></div><?php endif; ?>
        <div class="cert-url"><?= htmlspecialchars(SITE_URL, ENT_QUOTES) ?></div>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

/** Full-page CSS for the builder layout — used by certificate-view.php and certificate/verify.php. */
function certificateBuilderCss(): string
{
    return <<<CSS
.cert-builder-layout { padding: 56px 48px; font-family: Georgia, 'Times New Roman', serif; text-align: center; color: #1f1530; position: relative; }
.cert-builder-inner { max-width: 720px; margin: 0 auto; border: 2px solid #7C3AED; padding: 40px 32px; border-radius: 6px; background: rgba(255,255,255,0.82); }
.cert-logo { width: 46px; height: 46px; object-fit: contain; margin-bottom: 10px; }
.cert-title { font-size: 2.1rem; font-weight: 700; color: #4C1D95; letter-spacing: 0.02em; margin-bottom: 6px; }
.cert-subtitle { font-size: 1rem; color: #52466b; margin-bottom: 14px; }
.cert-recipient { font-size: 1.7rem; font-weight: 700; font-family: 'Space Grotesk', Georgia, serif; margin-bottom: 14px; border-bottom: 1px solid #d8cdf0; display: inline-block; padding-bottom: 6px; min-width: 320px; }
.cert-body { font-size: 1rem; line-height: 1.6; color: #362a4d; margin-bottom: 22px; }
.cert-meta-row { display: flex; justify-content: center; gap: 40px; margin-bottom: 26px; font-size: 0.82rem; }
.cert-meta-label { display: block; text-transform: uppercase; letter-spacing: 0.08em; color: #8b7ba8; font-size: 0.68rem; margin-bottom: 2px; }
.cert-meta-value { font-weight: 600; }
.cert-mono { font-family: 'JetBrains Mono', monospace; }
.cert-sign-row { display: flex; justify-content: center; align-items: flex-end; gap: 48px; margin-bottom: 18px; flex-wrap: wrap; }
.cert-sign { min-width: 140px; }
.cert-sign-line { border-top: 1.5px solid #4C1D95; margin-bottom: 6px; width: 140px; }
.cert-sign-name { font-weight: 700; font-size: 0.88rem; }
.cert-sign-title { font-size: 0.72rem; color: #8b7ba8; }
.cert-qr { display: flex; flex-direction: column; align-items: center; gap: 4px; }
.cert-qr-label { font-size: 0.65rem; color: #8b7ba8; }
.cert-footer { font-size: 0.78rem; color: #8b7ba8; margin-top: 6px; }
.cert-url { font-size: 0.7rem; color: #b3a6d1; margin-top: 10px; letter-spacing: 0.04em; }
@media (max-width: 640px) {
  .cert-builder-layout { padding: 28px 16px; }
  .cert-builder-inner { padding: 24px 16px; }
  .cert-title { font-size: 1.5rem; }
  .cert-recipient { font-size: 1.25rem; min-width: 0; }
  .cert-sign-row { gap: 24px; }
}
CSS;
}

// ============================================================
// ISSUANCE
// ============================================================

/**
 * Queue a freshly inserted certificate for NEAR issuance (admin dashboard).
 *
 * Must never break normal certificate issuance, but must not fail silently
 * either: queueCertificateForNear() logs database errors itself. A skip (NEAR
 * off, learner has no linked wallet yet, already queued) is a normal outcome;
 * the admin backfill on /admin/near-issuance.php picks up skipped certificates.
 */
function nearQueueNewCertificate(int $certId, string $source): void
{
    try {
        require_once __DIR__ . '/near-issuance.php';
        $r = \Web3Edu\Near\Issuance\queueCertificateForNear($certId, $source);
        if ($r['status'] === 'error') {
            error_log("[web3edu-near] certificate {$certId} issued but NEAR queue insert failed (source={$source})");
        }
    } catch (\Throwable $e) {
        error_log("[web3edu-near] NEAR queue hook crashed for certificate {$certId} (source={$source}): " . $e->getMessage());
    }
}

/**
 * The one true issuance path. Every certificate — guide completion, quiz
 * mastery, badge earned, XP level, premium purchase, or manual admin
 * award — flows through here.
 *
 * Idempotent per (user, issuable_type, issuable_id): calling it again for
 * the same trio just returns the existing certificate code.
 */
function issueCertificateV2(
    int $userId,
    string $issuableType,
    int $issuableId,
    int $certificateTypeId,
    ?int $templateId = null,
    ?int $ruleId = null,
    array $vars = [],
    ?int $expiresInDays = null
): ?string {
    $db = getDB();

    $exists = $db->prepare("SELECT certificate_code FROM certificates WHERE user_id=? AND issuable_type=? AND issuable_id=? AND is_revoked=0");
    $exists->execute([$userId, $issuableType, $issuableId]);
    if ($existingCode = $exists->fetchColumn()) return $existingCode;

    if (!$templateId) {
        $tpl = getDefaultTemplateForType($certificateTypeId);
        $templateId = $tpl['id'] ?? null;
    }
    if (!$templateId) return null; // no template configured anywhere — nothing to issue against

    $type = getCertificateType($certificateTypeId);
    $prefix = $type['id_prefix'] ?? 'WEB3';
    $code = generateCertificateCode($prefix);

    $achievementTitle = $vars['certificate_title'] ?? ($vars['guide_name'] ?? ($vars['title'] ?? 'Certificate'));
    $expiresAt = $expiresInDays ? date('Y-m-d H:i:s', strtotime("+{$expiresInDays} days")) : null;
    $metadata = json_encode(array_diff_key($vars, array_flip(['certificate_title'])), JSON_UNESCAPED_SLASHES);

    $db->prepare("
        INSERT INTO certificates
            (certificate_code, user_id, issuable_type, issuable_id, certificate_type_id, rule_id, template_id, achievement_title, metadata, expires_at)
        VALUES (?,?,?,?,?,?,?,?,?,?)
    ")->execute([$code, $userId, $issuableType, $issuableId, $certificateTypeId, $ruleId, $templateId, $achievementTitle, $metadata, $expiresAt]);

    $certId = (int)$db->lastInsertId();

    nearQueueNewCertificate($certId, 'issueCertificateV2');

    Analytics::track('certificate_issued', $userId, [
        'certificate_id' => $certId, 'issuable_type' => $issuableType, 'issuable_id' => $issuableId,
        'certificate_type_id' => $certificateTypeId,
    ]);

    notifyUser($userId, 'certificate_issued', ($type['icon'] ?? '🎓') . ' Certificate Issued', "Your certificate for \"{$achievementTitle}\" is ready.", SITE_URL . '/certificates.php');

    emailUser($userId, 'certificate', [
        'title' => $achievementTitle,
        'certificate_code' => $code, 'date' => date('F j, Y'),
    ], immediate: true);

    return $code;
}

/**
 * Manually issue a certificate from the admin panel (Special Achievement,
 * Event, Community Contribution, etc — anything not driven by an
 * automatic rule). issuableId is a synthetic per-user counter so the
 * (user, issuable_type, issuable_id) uniqueness constraint never blocks a
 * second manual award of the same type to the same user.
 */
function issueManualCertificate(int $userId, int $certificateTypeId, int $templateId, string $title, array $extraVars = []): ?string
{
    $db = getDB();
    $issuableId = (int)($db->query("SELECT COALESCE(MAX(id),0)+1 FROM certificates")->fetchColumn());
    $vars = array_merge(['certificate_title' => $title], $extraVars);
    return issueCertificateV2($userId, 'manual', $issuableId, $certificateTypeId, $templateId, null, $vars);
}

// ============================================================
// AUTOMATION ENGINE — certificate_rules
// ============================================================

/**
 * Evaluates every active rule for a given trigger and issues certificates
 * for any that match. Call this from the existing trigger point right
 * after the underlying event is recorded (quiz pass, level-up, badge
 * award, premium purchase). Safe no-op if no rules exist for the event.
 *
 * $context varies by $triggerType:
 *   quiz_score      -> ['quiz_id' => int, 'score' => int, 'quiz_title' => string]
 *   xp_level         -> ['level_number' => int, 'level_title' => string]
 *   badge_earned     -> ['badge_id' => int, 'badge_name' => string]
 *   premium_active   -> [] (fires on first-time activation)
 *   custom_event     -> ['event_key' => string, ...anything]
 */
function evaluateCertificateRules(int $userId, string $triggerType, array $context = []): array
{
    $db = getDB();
    $rules = $db->prepare("SELECT * FROM certificate_rules WHERE trigger_type = ? AND is_active = 1 AND deleted_at IS NULL");
    $rules->execute([$triggerType]);
    $issued = [];

    foreach ($rules->fetchAll() as $rule) {
        $cfg = json_decode($rule['trigger_config'] ?? '{}', true) ?: [];
        if (!ruleMatchesContext($triggerType, $cfg, $context)) continue;

        // issuableId: a stable per-user, per-rule identifier so re-triggering
        // the same rule for the same user never double-issues.
        $issuableId = $rule['id'];

        $vars = ['certificate_title' => $rule['name']];
        if ($triggerType === 'quiz_score')   $vars['score'] = $context['score'] ?? null;
        if (!empty($context['quiz_title']))  $vars['certificate_title'] = $context['quiz_title'];
        if (!empty($context['level_title'])) $vars['certificate_title'] = $rule['name'] . ' — ' . $context['level_title'];
        if (!empty($context['badge_name']))  $vars['certificate_title'] = $context['badge_name'];

        $code = issueCertificateV2(
            $userId,
            $rule['issuable_type'] ?: 'rule',
            (int)$issuableId,
            (int)$rule['certificate_type_id'],
            $rule['template_id'] ? (int)$rule['template_id'] : null,
            (int)$rule['id'],
            $vars
        );
        if ($code) $issued[] = $code;
    }

    return $issued;
}

function ruleMatchesContext(string $triggerType, array $cfg, array $context): bool
{
    switch ($triggerType) {
        case 'quiz_score':
            if (!empty($cfg['quiz_id']) && (int)$cfg['quiz_id'] !== (int)($context['quiz_id'] ?? 0)) return false;
            $minScore = isset($cfg['min_score']) ? (int)$cfg['min_score'] : 0;
            return (int)($context['score'] ?? 0) >= $minScore;

        case 'xp_level':
            $minLevel = isset($cfg['level_number']) ? (int)$cfg['level_number'] : null;
            return $minLevel !== null && (int)($context['level_number'] ?? 0) >= $minLevel;

        case 'badge_earned':
            return !empty($cfg['badge_id']) && (int)$cfg['badge_id'] === (int)($context['badge_id'] ?? 0);

        case 'premium_active':
            return true; // any first-time premium activation matches

        case 'custom_event':
            return !empty($cfg['event_key']) && $cfg['event_key'] === ($context['event_key'] ?? null);

        default:
            return false;
    }
}

// ============================================================
// VERIFICATION
// ============================================================

/**
 * Looks up a certificate by code, joins template/type/user, logs the
 * verification hit, and returns a ready-to-render array (or null).
 */
function verifyCertificateByCode(string $code, bool $logHit = true): ?array
{
    $db = getDB();
    $stmt = $db->prepare("
        SELECT c.*, ct.name AS template_name, ct.render_mode, ct.html_template, ct.background_image, ct.background_id,
               ct.subtitle, ct.body_text, ct.footer_text, ct.signature1_name, ct.signature1_title,
               ct.signature2_name, ct.signature2_title, ct.issue_date_format, ct.show_qr,
               ct.verification_url_override, ct.site_logo_override, ct.orientation,
               cty.label AS type_label, cty.icon AS type_icon,
               u.username
        FROM certificates c
        INNER JOIN certificate_templates ct ON ct.id = c.template_id
        LEFT JOIN certificate_types cty ON cty.id = c.certificate_type_id
        INNER JOIN users u ON u.id = c.user_id
        WHERE c.certificate_code = ?
    ");
    $stmt->execute([$code]);
    $cert = $stmt->fetch();
    if (!$cert) return null;

    if (!empty($cert['background_id'])) {
        $bg = getCertificateBackground((int)$cert['background_id']);
        if ($bg) $cert['background_image'] = $bg['path'];
    }

    $cert['is_expired'] = !empty($cert['expires_at']) && strtotime($cert['expires_at']) < time();

    if ($logHit) {
        $db->prepare("INSERT INTO certificate_verifications (certificate_id, ip_address, user_agent) VALUES (?,?,?)")
           ->execute([$cert['id'], $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
        Analytics::track('certificate_verified', isLoggedIn() ? (int)$_SESSION['user_id'] : null, [
            'certificate_id' => (int)$cert['id'], 'certificate_owner_id' => (int)$cert['user_id'],
        ]);
    }

    return $cert;
}

function logCertificateDownload(int $certificateId, ?int $userId, string $format = 'pdf'): void
{
    $db = getDB();
    $db->prepare("INSERT INTO certificate_downloads (certificate_id, user_id, format, ip_address) VALUES (?,?,?,?)")
       ->execute([$certificateId, $userId, $format, $_SERVER['REMOTE_ADDR'] ?? null]);
    $db->prepare("UPDATE certificates SET download_count = download_count + 1, last_downloaded_at = NOW() WHERE id = ?")
       ->execute([$certificateId]);
}

// ============================================================
// ADMIN ACTIONS — revoke / reissue / regenerate / delete
// ============================================================

function revokeCertificate(int $certId, string $reason, ?int $adminId): bool
{
    $db = getDB();
    $stmt = $db->prepare("UPDATE certificates SET is_revoked=1, revoke_reason=?, revoked_at=NOW(), revoked_by=? WHERE id=?");
    return $stmt->execute([$reason, $adminId, $certId]);
}

function unrevokeCertificate(int $certId): bool
{
    $db = getDB();
    return $db->prepare("UPDATE certificates SET is_revoked=0, revoke_reason=NULL, revoked_at=NULL, revoked_by=NULL WHERE id=?")->execute([$certId]);
}

/**
 * Reissues a certificate: revokes the old one and issues a brand-new
 * certificate (new code, fresh issued_at) for the same user/issuable/type.
 * Use this for corrections (e.g. the recipient's name changed).
 */
function reissueCertificate(int $certId, ?int $adminId): ?string
{
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM certificates WHERE id = ?");
    $stmt->execute([$certId]);
    $old = $stmt->fetch();
    if (!$old) return null;

    revokeCertificate($certId, 'Reissued', $adminId);

    $type = getCertificateType((int)$old['certificate_type_id']);
    $prefix = $type['id_prefix'] ?? 'WEB3';
    $code = generateCertificateCode($prefix);

    $db->prepare("
        INSERT INTO certificates
            (certificate_code, user_id, issuable_type, issuable_id, certificate_type_id, rule_id, reissued_from_id, template_id, achievement_title, metadata, expires_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $code, $old['user_id'], $old['issuable_type'], $old['issuable_id'], $old['certificate_type_id'],
        $old['rule_id'], $certId, $old['template_id'], $old['achievement_title'], $old['metadata'], $old['expires_at'],
    ]);

    nearQueueNewCertificate((int)$db->lastInsertId(), 'reissueCertificate');

    return $code;
}

/** "Regenerate" — no data changes needed since PDFs render on the fly from live template data; just bumps updated_at so admins get a visible confirmation. */
function regenerateCertificate(int $certId): bool
{
    return getDB()->prepare("UPDATE certificates SET updated_at = NOW() WHERE id = ?")->execute([$certId]);
}

function deleteCertificatePermanently(int $certId): bool
{
    return getDB()->prepare("DELETE FROM certificates WHERE id = ?")->execute([$certId]);
}

// ============================================================
// ADMIN DASHBOARD STATS
// ============================================================

function getCertificateStats(): array
{
    $db = getDB();
    return [
        'total_issued'   => (int)$db->query("SELECT COUNT(*) FROM certificates")->fetchColumn(),
        'total_active'   => (int)$db->query("SELECT COUNT(*) FROM certificates WHERE is_revoked=0")->fetchColumn(),
        'total_revoked'  => (int)$db->query("SELECT COUNT(*) FROM certificates WHERE is_revoked=1")->fetchColumn(),
        'total_expired'  => (int)$db->query("SELECT COUNT(*) FROM certificates WHERE expires_at IS NOT NULL AND expires_at < NOW() AND is_revoked=0")->fetchColumn(),
        'total_verified' => (int)$db->query("SELECT COUNT(*) FROM certificate_verifications")->fetchColumn(),
        'total_downloads'=> (int)$db->query("SELECT COUNT(*) FROM certificate_downloads")->fetchColumn(),
        'issued_this_month' => (int)$db->query("SELECT COUNT(*) FROM certificates WHERE issued_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetchColumn(),
    ];
}
