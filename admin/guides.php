<?php
/**
 * Admin — Guide Management
 * Full CRUD: create, edit, delete guides + drag-and-drop lesson ordering
 */
$adminTitle = 'Guide Management';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/certificates.php';

$db     = getDB();
$action = $_GET['action'] ?? 'list';
$id     = intval($_GET['id'] ?? 0);
$msg    = '';
$err    = '';

// ── Helper: rebuild sort_order from submitted array ──────────────────────────
function saveGuideLessons(PDO $db, int $guideId, array $lessonIds, array $xpByLesson = []): void {
    $db->prepare("DELETE FROM guide_path_lessons WHERE guide_id = ?")->execute([$guideId]);
    foreach ($lessonIds as $i => $lid) {
        $lid = intval($lid);
        if ($lid > 0) {
            $xpRaw = $xpByLesson[$lid] ?? '';
            $xp = ($xpRaw === '' || $xpRaw === null) ? null : max(0, intval($xpRaw));
            $db->prepare("INSERT IGNORE INTO guide_path_lessons (guide_id,lesson_id,sort_order,xp_reward) VALUES (?,?,?,?)")
               ->execute([$guideId, $lid, $i + 1, $xp]);
        }
    }
}

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    // CREATE
    if ($postAction === 'create') {
        $title  = trim($_POST['title'] ?? '');
        $desc   = trim($_POST['description'] ?? '');
        $diff   = in_array($_POST['difficulty']??'', ['Beginner','Intermediate','Advanced']) ? $_POST['difficulty'] : 'Beginner';
        $active = isset($_POST['is_active']) ? 1 : 0;
        $rawSlug = trim($_POST['slug'] ?? '');
        $slug   = $rawSlug !== '' ? preg_replace('/[^a-z0-9-]/', '', strtolower(str_replace(' ', '-', $rawSlug))) : '';
        if ($slug === '') $slug = preg_replace('/[^a-z0-9-]/', '', strtolower(str_replace(' ', '-', $title)));

        if (!$title) {
            $err = 'Title is required.';
        } else {
            // Unique slug
            $check = $db->prepare("SELECT id FROM guide_paths WHERE slug = ?");
            $check->execute([$slug]);
            if ($check->fetch()) $slug .= '-' . time();

            $db->prepare("INSERT INTO guide_paths (title,description,slug,difficulty,is_active) VALUES (?,?,?,?,?)")
               ->execute([$title, $desc, $slug, $diff, $active]);
            $newId = (int)$db->lastInsertId();

            saveGuideLessons($db, $newId, $_POST['lesson_ids'] ?? [], $_POST['lesson_xp'] ?? []);
            $msg = 'Guide created.';
            $action = 'edit';
            $id = $newId;
        }
    }

    // UPDATE
    if ($postAction === 'update' && $id) {
        $title  = trim($_POST['title'] ?? '');
        $desc   = trim($_POST['description'] ?? '');
        $diff   = in_array($_POST['difficulty']??'', ['Beginner','Intermediate','Advanced']) ? $_POST['difficulty'] : 'Beginner';
        $active = isset($_POST['is_active']) ? 1 : 0;
        $rawSlug = trim($_POST['slug'] ?? '');
        $slug   = $rawSlug !== '' ? preg_replace('/[^a-z0-9-]/', '', strtolower(str_replace(' ', '-', $rawSlug))) : '';

        if (!$title) {
            $err = 'Title is required.';
        } else {
            // Check slug uniqueness excluding self
            if ($slug !== '') {
                $ck = $db->prepare("SELECT id FROM guide_paths WHERE slug = ? AND id != ?");
                $ck->execute([$slug, $id]);
                if ($ck->fetch()) $err = 'That slug is already taken.';
            }
            if (!$err) {
                $certTemplateId = intval($_POST['certificate_template_id'] ?? 0) ?: null;
                $certEnabled = isset($_POST['certificate_enabled']) ? 1 : 0;
                $minPct = max(1, min(100, intval($_POST['min_completion_percent'] ?? 100)));
                $minQuizScore = ($_POST['min_quiz_score'] ?? '') !== '' ? max(0, min(100, intval($_POST['min_quiz_score']))) : null;
                $requireFinalQuiz = isset($_POST['require_final_quiz']) ? 1 : 0;
                $requireProject = isset($_POST['require_project']) ? 1 : 0;
                $requireAssessment = isset($_POST['require_assessment']) ? 1 : 0;
                $requirePremium = isset($_POST['require_premium']) ? 1 : 0;
                $allowRedownload = isset($_POST['allow_redownload']) ? 1 : 0;
                $expiresDays = ($_POST['certificate_expires_days'] ?? '') !== '' ? max(1, intval($_POST['certificate_expires_days'])) : null;

                $db->prepare("
                    UPDATE guide_paths SET
                        title=?, description=?, slug=?, difficulty=?, is_active=?,
                        certificate_template_id=?, certificate_enabled=?, min_completion_percent=?,
                        min_quiz_score=?, require_final_quiz=?, require_project=?, require_assessment=?,
                        require_premium=?, allow_redownload=?, certificate_expires_days=?
                    WHERE id=?
                ")->execute([
                    $title, $desc, $slug, $diff, $active,
                    $certTemplateId, $certEnabled, $minPct,
                    $minQuizScore, $requireFinalQuiz, $requireProject, $requireAssessment,
                    $requirePremium, $allowRedownload, $expiresDays,
                    $id,
                ]);
                saveGuideLessons($db, $id, $_POST['lesson_ids'] ?? [], $_POST['lesson_xp'] ?? []);
                $msg = 'Guide saved.';
            }
        }
    }

    // DELETE
    if ($postAction === 'delete' && $id) {
        $db->prepare("DELETE FROM guide_paths WHERE id = ?")->execute([$id]);
        header('Location: ' . SITE_URL . '/admin/guides.php?deleted=1'); exit;
    }

}

// ── Load single guide for edit ────────────────────────────────────────────────
$guide       = null;
$guideLessons = [];
if (($action === 'edit') && $id) {
    $s = $db->prepare("SELECT * FROM guide_paths WHERE id = ?");
    $s->execute([$id]); $guide = $s->fetch();
    if (!$guide) { header('Location: ' . SITE_URL . '/admin/guides.php'); exit; }

    $ls = $db->prepare("
        SELECT l.id, l.title, l.duration, l.category, l.access_level, l.sort_order AS lesson_sort_order, gpl.sort_order, gpl.xp_reward
        FROM   guide_path_lessons gpl
        INNER JOIN lessons l ON l.id = gpl.lesson_id
        WHERE  gpl.guide_id = ?
        ORDER  BY gpl.sort_order ASC
    ");
    $ls->execute([$id]);
    $guideLessons = $ls->fetchAll();
}

// ── All lessons for the picker ────────────────────────────────────────────────
$allLessons = $db->query(
    "SELECT id, title, category, access_level, duration, sort_order FROM lessons ORDER BY sort_order ASC, title ASC"
)->fetchAll();

// ── All guides for list ───────────────────────────────────────────────────────
$allGuides = $db->query("
    SELECT gp.*, COUNT(gpl.lesson_id) AS lesson_count
    FROM guide_paths gp
    LEFT JOIN guide_path_lessons gpl ON gpl.guide_id = gp.id
    GROUP BY gp.id
    ORDER BY gp.id ASC
")->fetchAll();
?>

<?php if (isset($_GET['deleted'])): ?>
<div class="admin-alert admin-alert-success">Guide deleted successfully.</div>
<?php endif; ?>
<?php if ($msg): ?>
<div class="admin-alert admin-alert-success"><?= sanitize($msg) ?></div>
<?php endif; ?>
<?php if ($err): ?>
<div class="admin-alert admin-alert-error"><?= sanitize($err) ?></div>
<?php endif; ?>

<?php if ($action === 'list'): ?>
<!-- ════════════════════════════════════════════════════════════
     LIST
════════════════════════════════════════════════════════════ -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0">Guide Paths <span style="font-size:0.75rem;font-weight:400;color:var(--text-muted)">(<?= count($allGuides) ?>)</span></h1>
    <a href="?action=edit" class="btn-primary btn-sm">
        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        New Guide
    </a>
</div>

<div style="background:rgba(139,92,246,0.06);border:1px solid rgba(139,92,246,0.18);border-radius:var(--radius);padding:12px 16px;margin-bottom:22px;font-size:0.84rem;color:var(--text-muted)">
    <strong style="color:var(--primary-bright)">Guide Navigation:</strong>
    Lessons opened from a guide use the route <code style="background:rgba(139,92,246,0.1);padding:1px 6px;border-radius:4px">/guides/{id}/lesson/{lessonId}</code>
    which follows <strong>guide order</strong> — completely separate from Learn section ordering.
</div>

<?php if (empty($allGuides)): ?>
<div style="text-align:center;padding:60px;color:var(--text-muted)">
    <p>No guides yet. <a href="?action=edit" style="color:var(--primary-bright)">Create your first guide.</a></p>
</div>
<?php else: ?>
<div class="admin-table-wrap">
<table class="admin-table">
<thead><tr>
    <th>Title</th>
    <th>Difficulty</th>
    <th>Lessons</th>
    <th>Status</th>
    <th>Slug</th>
    <th>Actions</th>
</tr></thead>
<tbody>
<?php foreach ($allGuides as $g): ?>
<tr>
    <td style="font-weight:600"><?= sanitize($g['title']) ?></td>
    <td>
        <span style="padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:700;
            <?= $g['difficulty']==='Beginner'?'background:rgba(11,121,88,0.12);color:#0B7958'
               :($g['difficulty']==='Advanced'?'background:rgba(239,68,68,0.12);color:#f87171'
               :'background:rgba(245,158,11,0.12);color:#fbbf24') ?>">
            <?= sanitize($g['difficulty'] ?? 'Beginner') ?>
        </span>
    </td>
    <td style="text-align:center">
        <span style="padding:3px 10px;border-radius:20px;font-size:0.75rem;font-weight:700;background:rgba(139,92,246,0.12);color:var(--primary-bright)">
            <?= (int)$g['lesson_count'] ?>
        </span>
    </td>
    <td>
        <span style="padding:3px 8px;border-radius:20px;font-size:0.72rem;font-weight:700;
            <?= $g['is_active']?'background:rgba(11,121,88,0.12);color:#0B7958':'background:rgba(107,114,128,0.12);color:var(--text-muted)' ?>">
            <?= $g['is_active'] ? 'Active' : 'Hidden' ?>
        </span>
    </td>
    <td style="font-family:var(--font-mono);font-size:0.75rem;color:var(--text-muted)"><?= sanitize($g['slug'] ?? '') ?></td>
    <td>
        <div style="display:flex;gap:6px">
            <a href="?action=edit&id=<?= $g['id'] ?>" class="btn-ghost btn-sm">Edit</a>
            <a href="<?= SITE_URL ?>/guide.php?guide=<?= $g['id'] ?>" target="_blank" class="btn-ghost btn-sm" style="color:var(--cyan)">View</a>
            <form method="POST" action="?action=list&id=<?= $g['id'] ?>" style="display:inline">
                <input type="hidden" name="action" value="delete">
                <button class="btn-danger btn-sm" onclick="return confirm('Delete this guide? All lesson assignments will be removed.')">Delete</button>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>


<?php elseif ($action === 'edit'): ?>
<!-- ════════════════════════════════════════════════════════════
     CREATE / EDIT
════════════════════════════════════════════════════════════ -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0"><?= $id ? 'Edit Guide: ' . sanitize($guide['title'] ?? '') : 'New Guide' ?></h1>
    <a href="<?= SITE_URL ?>/admin/guides.php" class="btn-ghost btn-sm">← All Guides</a>
</div>

<form method="POST" action="?action=edit&id=<?= $id ?>">
    <input type="hidden" name="action" value="<?= $id ? 'update' : 'create' ?>">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px" class="guide-edit-grid">

        <!-- ── Left: Guide details ── -->
        <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px">
            <h2 style="font-size:0.9rem;font-weight:700;margin-bottom:18px">Guide Details</h2>

            <div class="form-group">
                <label class="form-label">Title <span style="color:var(--danger)">*</span></label>
                <input type="text" name="title" class="form-input" required
                       value="<?= sanitize($guide['title'] ?? '') ?>"
                       placeholder="e.g. Web3 Beginner Roadmap"
                       oninput="autoSlug(this)">
            </div>

            <div class="form-group">
                <label class="form-label">Slug</label>
                <input type="text" name="slug" id="slugField" class="form-input"
                       value="<?= sanitize($guide['slug'] ?? '') ?>"
                       placeholder="e.g. web3-beginner-roadmap"
                       pattern="[a-z0-9-]*"
                       title="Lowercase letters, numbers, hyphens only">
                <div class="form-hint">Used in URLs. Auto-generated from title.</div>
            </div>

            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-input" rows="3"
                          placeholder="A structured path for learning Web3 fundamentals…"><?= sanitize($guide['description'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Difficulty</label>
                <select name="difficulty" class="form-select">
                    <?php foreach (['Beginner','Intermediate','Advanced'] as $d): ?>
                    <option value="<?= $d ?>" <?= ($guide['difficulty'] ?? 'Beginner') === $d ? 'selected' : '' ?>><?= $d ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.84rem">
                    <input type="checkbox" name="is_active" value="1"
                           <?= ($guide['is_active'] ?? 1) ? 'checked' : '' ?>>
                    <span>Active (visible to users)</span>
                </label>
            </div>

            <?php if ($id): ?>
            <div style="border-top:1px solid var(--border);margin:20px 0 16px;padding-top:16px">
                <h3 style="font-size:0.85rem;font-weight:700;margin-bottom:12px">🎓 Certificate Settings</h3>

                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.84rem">
                        <input type="checkbox" name="certificate_enabled" value="1" <?= ($guide['certificate_enabled'] ?? 1) ? 'checked' : '' ?>>
                        <span>Award a certificate for this guide</span>
                    </label>
                </div>

                <div class="form-group">
                    <label class="form-label">Certificate Template</label>
                    <select name="certificate_template_id" class="form-select">
                        <option value="">— use type default —</option>
                        <?php
                        $guideTypeRow = getCertificateTypeByKey('guide_completion');
                        $guideTemplates = $db->query("SELECT id, name FROM certificate_templates WHERE deleted_at IS NULL AND is_active=1" . ($guideTypeRow ? " AND certificate_type_id=" . (int)$guideTypeRow['id'] : "") . " ORDER BY name ASC")->fetchAll();
                        foreach ($guideTemplates as $gt): ?>
                        <option value="<?= $gt['id'] ?>" <?= (int)($guide['certificate_template_id'] ?? 0) === (int)$gt['id'] ? 'selected' : '' ?>><?= sanitize($gt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Minimum Completion %</label>
                    <input type="number" min="1" max="100" name="min_completion_percent" class="form-input" value="<?= (int)($guide['min_completion_percent'] ?? 100) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Minimum Quiz Score <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
                    <input type="number" min="0" max="100" name="min_quiz_score" class="form-input" value="<?= sanitize((string)($guide['min_quiz_score'] ?? '')) ?>" placeholder="Leave blank to skip">
                    <div class="form-hint">Checked against this guide's own quiz, if it has one.</div>
                </div>

                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.84rem">
                        <input type="checkbox" name="require_final_quiz" value="1" <?= ($guide['require_final_quiz'] ?? 0) ? 'checked' : '' ?>>
                        <span>Require passing the final quiz</span>
                    </label>
                </div>

                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.84rem">
                        <input type="checkbox" name="require_project" value="1" <?= ($guide['require_project'] ?? 0) ? 'checked' : '' ?>>
                        <span>Require all attached Projects to be approved</span>
                    </label>
                    <?php if ($id): ?>
                    <div class="form-hint"><a href="guide-projects.php?guide_id=<?= (int)$id ?>" style="color:var(--primary-bright)">Manage this guide's Projects →</a></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.84rem">
                        <input type="checkbox" name="require_assessment" value="1" <?= ($guide['require_assessment'] ?? 0) ? 'checked' : '' ?>>
                        <span>Require passing the Final Assessment</span>
                    </label>
                    <?php if ($id): ?>
                    <div class="form-hint"><a href="gamification.php?tab=quizzes" style="color:var(--primary-bright)">Create a Final Assessment quiz for this guide →</a></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.84rem">
                        <input type="checkbox" name="require_premium" value="1" <?= ($guide['require_premium'] ?? 0) ? 'checked' : '' ?>>
                        <span>Require an active premium subscription</span>
                    </label>
                </div>

                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.84rem">
                        <input type="checkbox" name="allow_redownload" value="1" <?= ($guide['allow_redownload'] ?? 1) ? 'checked' : '' ?>>
                        <span>Allow re-downloading the certificate</span>
                    </label>
                </div>

                <div class="form-group">
                    <label class="form-label">Expires After <span style="font-weight:400;color:var(--text-muted)">(days, optional)</span></label>
                    <input type="number" min="1" name="certificate_expires_days" class="form-input" value="<?= sanitize((string)($guide['certificate_expires_days'] ?? '')) ?>" placeholder="Never expires">
                </div>
            </div>
            <?php else: ?>
            <p style="font-size:0.78rem;color:var(--text-muted);margin:16px 0">Save the guide first to configure its certificate settings.</p>
            <?php endif; ?>

            <button type="submit" class="btn-primary" style="width:100%">
                <?= $id ? 'Save Guide' : 'Create Guide' ?>
            </button>
        </div>

        <!-- ── Right: Lesson ordering ── -->
        <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px">

            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
                <h2 style="font-size:0.9rem;font-weight:700;margin:0">Lesson Order</h2>
                <span style="font-size:0.72rem;color:var(--text-muted)">Drag to reorder</span>
            </div>
            <p style="font-size:0.78rem;color:var(--text-muted);margin-bottom:16px">
                These are the steps users follow. Order is <strong>independent</strong> from the Learn section.
                Set a custom <strong>XP</strong> value per lesson to override the default lesson-completion reward for this guide (leave blank to use the default).
            </p>

            <!-- Current guide lessons (draggable) -->
            <div id="guideStepList" style="min-height:60px;margin-bottom:16px;display:flex;flex-direction:column;gap:6px">
                <?php foreach ($guideLessons as $gl): ?>
                <div class="guide-step-row" data-id="<?= $gl['id'] ?>"
                     style="display:flex;align-items:center;gap:10px;padding:9px 12px;background:var(--bg-card2);border:1.5px solid var(--border);border-radius:var(--radius);cursor:grab;user-select:none;transition:border-color 0.15s">
                    <svg class="drag-handle" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-muted);flex-shrink:0"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                    <div style="flex:1;min-width:0">
                        <div style="font-size:0.84rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= sanitize($gl['title']) ?></div>
                        <div style="font-size:0.7rem;color:var(--text-muted)"><?= sanitize($gl['category']) ?> · <?= sanitize($gl['duration']) ?> · <span style="text-transform:capitalize"><?= $gl['access_level'] ?></span></div>
                    </div>
                    <input type="number" min="0" name="lesson_xp[<?= $gl['id'] ?>]" placeholder="XP"
                           value="<?= $gl['xp_reward'] !== null ? (int)$gl['xp_reward'] : '' ?>"
                           title="Custom XP for this lesson in this guide (blank = default)"
                           style="width:64px;flex-shrink:0;padding:5px 6px;font-size:0.76rem;border-radius:var(--radius);border:1px solid var(--border);background:var(--bg-card);color:var(--text)">
                    <button type="button" onclick="removeStep(this)" title="Remove from guide"
                            style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:2px;flex-shrink:0;transition:color 0.15s"
                            onmouseover="this.style.color='#f87171'" onmouseout="this.style.color='var(--text-muted)'">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <?php endforeach; ?>
                <?php if (empty($guideLessons)): ?>
                <div id="emptyMsg" style="text-align:center;padding:20px;color:var(--text-muted);font-size:0.84rem;border:2px dashed var(--border);border-radius:var(--radius)">
                    No lessons added yet. Select from the list below.
                </div>
                <?php endif; ?>
            </div>

            <!-- Lesson picker -->
            <div style="border-top:1px solid var(--border);padding-top:16px">
                <label class="form-label" style="margin-bottom:8px;display:block">Add Lessons</label>
                <div class="search-wrap" style="margin-bottom:10px">
                    <span class="search-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    </span>
                    <input type="text" id="lessonSearch" class="search-input" placeholder="Filter lessons…" oninput="filterLessons(this.value)">
                </div>
                <div id="lessonPickerList" style="max-height:260px;overflow-y:auto;display:flex;flex-direction:column;gap:4px;scrollbar-width:thin;scrollbar-color:var(--border) transparent">
                    <?php
                    $assignedIds = array_column($guideLessons, 'id');
                    foreach ($allLessons as $l):
                        $already = in_array($l['id'], $assignedIds);
                    ?>
                    <div class="lesson-picker-row <?= $already ? 'already-added' : '' ?>"
                         data-title="<?= strtolower(sanitize($l['title'])) ?>"
                         data-id="<?= $l['id'] ?>"
                         style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:7px 10px;border-radius:var(--radius);background:var(--bg-card2);font-size:0.8rem;<?= $already ? 'opacity:0.4;pointer-events:none' : '' ?>">
                        <div style="min-width:0;flex:1">
                            <div style="font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= sanitize($l['title']) ?></div>
                            <div style="font-size:0.68rem;color:var(--text-muted)"><?= sanitize($l['category']) ?> · <?= sanitize($l['duration']) ?> · <span style="text-transform:capitalize"><?= $l['access_level'] ?></span></div>
                        </div>
                        <button type="button"
                                onclick="addLesson(<?= $l['id'] ?>, '<?= addslashes(sanitize($l['title'])) ?>', '<?= addslashes(sanitize($l['category'])) ?>', '<?= addslashes(sanitize($l['duration'])) ?>', '<?= $l['access_level'] ?>')"
                                style="flex-shrink:0;padding:3px 10px;border-radius:var(--radius);border:1.5px solid var(--primary);background:none;color:var(--primary-bright);font-size:0.72rem;font-weight:600;cursor:pointer;transition:all 0.15s"
                                onmouseover="this.style.background='var(--glow-soft)'" onmouseout="this.style.background='none'">
                            Add
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Hidden inputs updated by JS -->
            <div id="hiddenLessonInputs">
                <?php foreach ($guideLessons as $gl): ?>
                <input type="hidden" name="lesson_ids[]" value="<?= $gl['id'] ?>">
                <?php endforeach; ?>
            </div>
        </div>
    </div><!-- /grid -->
</form>

<!-- Preview link if editing -->
<?php if ($id): ?>
<div style="margin-top:8px;text-align:right">
    <a href="<?= SITE_URL ?>/guide.php?guide=<?= $id ?>" target="_blank"
       style="font-size:0.82rem;color:var(--primary-bright);text-decoration:none;display:inline-flex;align-items:center;gap:5px">
        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
        Preview guide →
    </a>
</div>
<?php endif; ?>

<style>
.guide-step-row.drag-over { border-color: var(--primary) !important; background: var(--glow-soft) !important; }
.guide-step-row.dragging  { opacity: 0.4; }
@media (max-width: 750px) { .guide-edit-grid { grid-template-columns: 1fr !important; } }
</style>

<script>
let userEditedSlug = <?= $id ? 'true' : 'false' ?>;

function slugify(s) {
    return s.toLowerCase().trim().replace(/[^a-z0-9\s-]/g,'').replace(/[\s-]+/g,'-').replace(/^-+|-+$/g,'');
}
function autoSlug(inp) {
    if (!userEditedSlug) document.getElementById('slugField').value = slugify(inp.value);
}
document.getElementById('slugField')?.addEventListener('input', function() {
    userEditedSlug = true;
    this.value = slugify(this.value);
});

// ── Add lesson to guide list ──────────────────────────────────────────────────
function addLesson(id, title, cat, dur, access) {
    // Hide the empty placeholder
    const emptyMsg = document.getElementById('emptyMsg');
    if (emptyMsg) emptyMsg.style.display = 'none';

    // Mark picker row as added
    const pickerRow = document.querySelector('.lesson-picker-row[data-id="'+id+'"]');
    if (pickerRow) { pickerRow.style.opacity='0.4'; pickerRow.style.pointerEvents='none'; pickerRow.classList.add('already-added'); }

    // Create step row
    const row = document.createElement('div');
    row.className = 'guide-step-row';
    row.dataset.id = id;
    row.style.cssText = 'display:flex;align-items:center;gap:10px;padding:9px 12px;background:var(--bg-card2);border:1.5px solid var(--border);border-radius:var(--radius);cursor:grab;user-select:none;transition:border-color 0.15s';
    row.innerHTML = `
        <svg class="drag-handle" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-muted);flex-shrink:0"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
        <div style="flex:1;min-width:0">
            <div style="font-size:0.84rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escHtml(title)}</div>
            <div style="font-size:0.7rem;color:var(--text-muted)">${escHtml(cat)} · ${escHtml(dur)} · ${escHtml(access)}</div>
        </div>
        <input type="number" min="0" name="lesson_xp[${id}]" placeholder="XP"
               title="Custom XP for this lesson in this guide (blank = default)"
               style="width:64px;flex-shrink:0;padding:5px 6px;font-size:0.76rem;border-radius:var(--radius);border:1px solid var(--border);background:var(--bg-card);color:var(--text)">
        <button type="button" onclick="removeStep(this)" title="Remove"
                style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:2px;flex-shrink:0;transition:color 0.15s"
                onmouseover="this.style.color='#f87171'" onmouseout="this.style.color='var(--text-muted)'">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>`;
    document.getElementById('guideStepList').appendChild(row);
    makeDraggable(row);

    // Add hidden input
    const inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = 'lesson_ids[]'; inp.value = id;
    inp.dataset.lessonId = id;
    document.getElementById('hiddenLessonInputs').appendChild(inp);
}

// ── Remove step ───────────────────────────────────────────────────────────────
function removeStep(btn) {
    const row = btn.closest('.guide-step-row');
    const lid = row.dataset.id;

    // Re-enable picker row
    const pickerRow = document.querySelector('.lesson-picker-row[data-id="'+lid+'"]');
    if (pickerRow) { pickerRow.style.opacity=''; pickerRow.style.pointerEvents=''; pickerRow.classList.remove('already-added'); }

    // Remove hidden input
    const inp = document.querySelector('#hiddenLessonInputs input[data-lesson-id="'+lid+'"]');
    if (inp) inp.remove();

    row.remove();

    // Show empty message if list is now empty
    const steps = document.querySelectorAll('#guideStepList .guide-step-row');
    if (steps.length === 0) {
        const em = document.getElementById('emptyMsg') || (() => {
            const d=document.createElement('div'); d.id='emptyMsg';
            d.style.cssText='text-align:center;padding:20px;color:var(--text-muted);font-size:0.84rem;border:2px dashed var(--border);border-radius:var(--radius)';
            d.textContent='No lessons added yet. Select from the list below.';
            return d;
        })();
        document.getElementById('guideStepList').appendChild(em);
        em.style.display = 'block';
    }
}

// ── Filter picker list ────────────────────────────────────────────────────────
function filterLessons(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.lesson-picker-row').forEach(row => {
        row.style.display = (!q || row.dataset.title.includes(q)) ? '' : 'none';
    });
}

// ── Drag-and-drop reorder ─────────────────────────────────────────────────────
let dragSrc = null;

function makeDraggable(row) {
    row.draggable = true;
    row.addEventListener('dragstart', e => {
        dragSrc = row;
        row.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
    });
    row.addEventListener('dragend', () => {
        row.classList.remove('dragging');
        document.querySelectorAll('.guide-step-row').forEach(r => r.classList.remove('drag-over'));
        syncHiddenInputs();
    });
    row.addEventListener('dragover', e => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        if (row !== dragSrc) {
            document.querySelectorAll('.guide-step-row').forEach(r => r.classList.remove('drag-over'));
            row.classList.add('drag-over');
        }
    });
    row.addEventListener('drop', e => {
        e.preventDefault();
        if (dragSrc && dragSrc !== row) {
            const list = document.getElementById('guideStepList');
            const rows = [...list.querySelectorAll('.guide-step-row')];
            const srcIdx = rows.indexOf(dragSrc);
            const tgtIdx = rows.indexOf(row);
            if (srcIdx < tgtIdx) list.insertBefore(dragSrc, row.nextSibling);
            else                  list.insertBefore(dragSrc, row);
            row.classList.remove('drag-over');
            syncHiddenInputs();
        }
    });
}

// Re-sync hidden inputs after drag
function syncHiddenInputs() {
    const container = document.getElementById('hiddenLessonInputs');
    container.innerHTML = '';
    document.querySelectorAll('#guideStepList .guide-step-row').forEach(row => {
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'lesson_ids[]'; inp.value = row.dataset.id;
        inp.dataset.lessonId = row.dataset.id;
        container.appendChild(inp);
    });
}

// Init drag on existing rows
document.querySelectorAll('.guide-step-row').forEach(makeDraggable);

// Helpers
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php endif; // action ?>

<?php require_once __DIR__ . '/footer.php'; ?>
