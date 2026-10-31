<?php
require_once __DIR__ . '/EmailLayout.php';
require_once __DIR__ . '/ResendProvider.php';

/** Returns the active email provider. Swap here if a second provider is added. */
function getEmailProvider(): EmailProviderInterface
{
    return new ResendProvider();
}

/**
 * Email configuration getters — used by ResendProvider and the admin
 * Email Settings page. Each reads site_settings first (editable in
 * Admin Settings without a code deploy) and falls back to the original
 * includes/config.php constants, so nothing breaks for an install that
 * hasn't touched the new settings yet.
 */
function getEmailFromName(): string    { return getSetting('email_from_name', EMAIL_FROM_NAME); }
function getEmailFromAddress(): string { return getSetting('email_from_address', EMAIL_FROM_ADDRESS); }
function getEmailReplyTo(): string     { return getSetting('email_reply_to', ''); }
function getResendApiKey(): string     { return getSetting('resend_api_key', RESEND_API_KEY); }

/**
 * Public entry point used throughout the app (subscriptions.php, auth flows,
 * guide completion, certificate issuance, etc). Looks up the template by
 * key, renders placeholders, and ENQUEUES it — this function never sends
 * synchronously by default, so a slow/down email provider never blocks a
 * user request.
 *
 * The queue is drained in one of three ways (all share the exact same
 * email_queue table and processEmailQueue() logic, so nothing about the
 * queue architecture itself changes):
 *   1. The Smart Internal Queue Runner — an opportunistic background pass
 *      that fires from normal page loads at most once every 60s. This is
 *      the default on InfinityFree, where external cron can't reach
 *      cron/email-cron.php. See includes/email/EmailQueueRunner.php.
 *   2. A real external cron job hitting cron/email-cron.php (works once
 *      you're on a host with real cron, e.g. Hostinger).
 *   3. $immediate = true below, for the handful of user-facing emails
 *      that should feel instant (password reset, welcome, password
 *      changed, payment confirmation, certificate issued). This still
 *      queues the row first — it just also attempts to send that one row
 *      right away, in the same request, so the user doesn't have to wait
 *      up to 60s for the background runner. If the send fails or the
 *      provider is briefly down, the row simply stays 'pending' and gets
 *      picked up by the normal background runner/cron like any other
 *      queued email — no email is ever lost or double-sent.
 */
function deliverEmailTemplate(string $templateKey, string $toEmail, array $variables = [], bool $immediate = false): bool
{
    // Queuing an email is a side effect of whatever the caller is really doing
    // (creating a bounty, approving a payment, etc). It must never be able to
    // turn that caller's request into a fatal error — so the lookup + insert
    // are wrapped and any failure here is logged and swallowed, not thrown.
    // Notification Preferences gate (admin/settings.php) — only applies to
    // template_keys that appear in notificationEventCatalog(); every other
    // template (password reset, welcome, certificates, etc.) is unaffected.
    // Checked here, at the one function every email send path goes
    // through, rather than in each caller.
    if (!isNotificationEmailEnabled($templateKey)) {
        return false;
    }

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM email_templates WHERE template_key = ? AND is_active = 1");
        $stmt->execute([$templateKey]);
        $template = $stmt->fetch();

        if (!$template) {
            error_log("[Email] Template '{$templateKey}' not found or inactive — skipping send to {$toEmail}");
            return false;
        }

        $db->prepare("INSERT INTO email_queue (to_email, template_key, variables, status) VALUES (?, ?, ?, 'pending')")
           ->execute([$toEmail, $templateKey, json_encode($variables)]);
    } catch (\Throwable $e) {
        error_log("[Email] Failed to queue '{$templateKey}' for {$toEmail}: " . $e->getMessage());
        return false;
    }

    if ($immediate) {
        $queueId = (int)$db->lastInsertId();
        // Best-effort, synchronous send of just this one row. Wrapped so
        // that any provider hiccup never turns into a fatal error on what
        // is often a redirect-then-render page (register, forgot password,
        // etc) — the row is already safely queued regardless of outcome.
        try {
            sendQueuedEmailNow($queueId);
        } catch (\Throwable $e) {
            error_log("[Email] Immediate send for queue #{$queueId} ({$templateKey}) failed, will retry via background runner: " . $e->getMessage());
        }
    }

    return true;
}

/** Replaces {{placeholder}} tokens in a string with the given variables. Unknown placeholders are left as-is. */
function renderTemplateString(string $template, array $variables): string
{
    return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($matches) use ($variables) {
        $key = $matches[1];
        return array_key_exists($key, $variables) ? htmlspecialchars((string)$variables[$key], ENT_QUOTES) : $matches[0];
    }, $template);
}

/**
 * Sends a single already-queued row and updates its status accordingly.
 * Shared by processEmailQueue() (batch path) and sendQueuedEmailNow()
 * (immediate path) so both paths behave identically — one code path for
 * "what does sending a queued email actually do", regardless of what
 * triggered it. Returns true only if the row was sent successfully.
 */
function sendQueuedEmailRow(array $row, PDO $db, EmailProviderInterface $provider): bool
{
    $tplStmt = $db->prepare("SELECT * FROM email_templates WHERE template_key = ? AND is_active = 1");
    $tplStmt->execute([$row['template_key']]);
    $template = $tplStmt->fetch();

    if (!$template) {
        $db->prepare("UPDATE email_queue SET status='failed', last_error='Template no longer active', attempts=attempts+1 WHERE id=?")
           ->execute([$row['id']]);
        return false;
    }

    $vars    = json_decode($row['variables'] ?? '{}', true) ?: [];
    $subject = renderTemplateString($template['subject'], $vars);
    $inner   = renderTemplateString($template['html_body'], $vars);
    $html    = renderEmailLayout($inner, $subject);

    $result = $provider->send($row['to_email'], $subject, $html);

    if ($result['success']) {
        $db->prepare("UPDATE email_queue SET status='sent', sent_at=NOW(), attempts=attempts+1 WHERE id=?")->execute([$row['id']]);
        return true;
    }

    $newAttempts = (int)$row['attempts'] + 1;
    $newStatus = $newAttempts >= 3 ? 'failed' : 'pending';
    $db->prepare("UPDATE email_queue SET status=?, attempts=?, last_error=? WHERE id=?")
       ->execute([$newStatus, $newAttempts, $result['error'], $row['id']]);
    error_log("[Email] Send failed to {$row['to_email']} ({$row['template_key']}): {$result['error']}");
    return false;
}

/**
 * Drains the email queue. Sends pending rows via the active provider,
 * retries up to 3 times, then marks failed. Returns
 * ['sent' => int, 'failed' => int].
 *
 * Called from two places (see deliverEmailTemplate() docblock above):
 *   - the Smart Internal Queue Runner (includes/email/EmailQueueRunner.php),
 *     opportunistically from normal page loads, at most once every 60s —
 *     this is the default on InfinityFree, since external cron can't reach
 *     cron/email-cron.php there,
 *   - cron/email-cron.php directly, once real external cron is available
 *     (e.g. after moving to Hostinger).
 * Both only ever select status='pending' rows, so even if both ran in the
 * same request, no email would be sent twice.
 */
function processEmailQueue(int $batchSize = 25): array
{
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM email_queue WHERE status = 'pending' AND attempts < 3 ORDER BY created_at ASC LIMIT ?");
    $stmt->bindValue(1, $batchSize, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $provider = getEmailProvider();
    $sent = 0; $failed = 0;

    foreach ($rows as $row) {
        if (sendQueuedEmailRow($row, $db, $provider)) {
            $sent++;
        } else {
            $failed++;
        }
    }

    return ['sent' => $sent, 'failed' => $failed];
}

/**
 * Sends exactly one queued row right now, synchronously. Used by
 * deliverEmailTemplate($immediate = true) so the handful of time-sensitive
 * user-facing emails (password reset, welcome, password changed, payment
 * confirmation, certificate issued) feel instant instead of waiting for
 * the next background runner pass (up to 60s) or external cron run.
 *
 * Only touches the one row passed in — never pulls in other pending rows —
 * so this can never turn into an accidental mini batch-run.
 */
function sendQueuedEmailNow(int $queueId): bool
{
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM email_queue WHERE id = ? AND status = 'pending' AND attempts < 3");
    $stmt->execute([$queueId]);
    $row = $stmt->fetch();

    // Already sent, already failed out, or otherwise gone (e.g. picked up
    // by the background runner a split second earlier) — nothing to do.
    if (!$row) return false;

    return sendQueuedEmailRow($row, $db, getEmailProvider());
}

/**
 * Send a single test email immediately (bypasses the queue) — used by the
 * admin Email Templates page's "Test Email" button for instant feedback.
 */
function sendTestEmail(string $templateKey, string $toEmail, array $variables = []): array
{
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM email_templates WHERE template_key = ?");
    $stmt->execute([$templateKey]);
    $template = $stmt->fetch();
    if (!$template) return ['success' => false, 'error' => 'Template not found.'];

    $subject = '[TEST] ' . renderTemplateString($template['subject'], $variables);
    $inner   = renderTemplateString($template['html_body'], $variables);
    $html    = renderEmailLayout($inner, $subject);

    return getEmailProvider()->send($toEmail, $subject, $html);
}

/**
 * Config-level test email for the admin Email Settings page — checks
 * that From Name/Email, Reply-To, and the Resend API key actually work,
 * independent of any stored template. Bypasses both the queue and the
 * template lookup (unlike sendTestEmail() above, which tests one chosen
 * template); reuses the same branded layout rather than a second one.
 */
function sendConfigTestEmail(string $toEmail): array
{
    $subject = 'Web3Edu Email Configuration Test';
    $html = renderEmailLayout('<p>Your email configuration is working correctly.</p>', $subject);
    return getEmailProvider()->send($toEmail, $subject, $html);
}
