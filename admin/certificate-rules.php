<?php
$adminTitle = 'Certificate Rules';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/certificates.php';

$db = getDB();
$action = $_GET['action'] ?? 'list';
$id = intval($_GET['id'] ?? 0);
$success = ''; $errors = [];

$triggerLabels = [
    'quiz_score'     => 'Quiz score reaches a threshold',
    'xp_level'       => 'User reaches an XP level',
    'badge_earned'   => 'A specific badge is earned',
    'premium_active' => 'Premium is purchased (first time)',
    'custom_event'   => 'Custom event (fired from code)',
];

if ($action === 'toggle' && $id) {
    $db->prepare("UPDATE certificate_rules SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
    header('Location: ' . SITE_URL . '/admin/certificate-rules.php'); exit;
}
if ($action === 'delete' && $id) {
    $db->prepare("UPDATE certificate_rules SET deleted_at=NOW() WHERE id=?")->execute([$id]);
    header('Location: ' . SITE_URL . '/admin/certificate-rules.php?deleted=1'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name       = trim($_POST['name'] ?? '');
    $typeId     = intval($_POST['certificate_type_id'] ?? 0);
    $templateId = intval($_POST['template_id'] ?? 0) ?: null;
    $trigger    = $_POST['trigger_type'] ?? '';
    $active     = isset($_POST['is_active']) ? 1 : 0;

    if (empty($name)) $errors[] = 'Rule name is required.';
    if (!$typeId) $errors[] = 'Certificate type is required.';
    if (!array_key_exists($trigger, $triggerLabels)) $errors[] = 'Choose a valid trigger.';

    $cfg = [];
    switch ($trigger) {
        case 'quiz_score':
            $cfg['quiz_id'] = intval($_POST['cfg_quiz_id'] ?? 0) ?: null;
            $cfg['min_score'] = intval($_POST['cfg_min_score'] ?? 70);
            break;
        case 'xp_level':
            $cfg['level_number'] = intval($_POST['cfg_level_number'] ?? 1);
            break;
        case 'badge_earned':
            $cfg['badge_id'] = intval($_POST['cfg_badge_id'] ?? 0);
            if (!$cfg['badge_id']) $errors[] = 'Choose a badge for this rule.';
            break;
        case 'custom_event':
            $cfg['event_key'] = trim($_POST['cfg_event_key'] ?? '');
            if (!$cfg['event_key']) $errors[] = 'Provide an event key.';
            break;
    }

    if (empty($errors)) {
        if ($id) {
            $db->prepare("UPDATE certificate_rules SET name=?, certificate_type_id=?, template_id=?, trigger_type=?, trigger_config=?, is_active=? WHERE id=?")
               ->execute([$name, $typeId, $templateId, $trigger, json_encode($cfg), $active, $id]);
            $success = 'Rule updated.';
        } else {
            $db->prepare("INSERT INTO certificate_rules (name, certificate_type_id, template_id, trigger_type, trigger_config, issuable_type, is_active) VALUES (?,?,?,?,?,?,?)")
               ->execute([$name, $typeId, $templateId, $trigger, json_encode($cfg), 'rule_' . $trigger, $active]);
            $success = 'Rule created.';
        }
        $action = 'list'; $id = 0;
    }
}

$types = getCertificateTypes(false);
$quizzes = $db->query("SELECT id, title FROM quizzes WHERE is_active=1 AND deleted_at IS NULL ORDER BY title ASC")->fetchAll();
$badges = $db->query("SELECT id, name FROM badges WHERE is_active=1 AND deleted_at IS NULL ORDER BY name ASC")->fetchAll();
$templates = $db->query("SELECT id, name, certificate_type_id FROM certificate_templates WHERE deleted_at IS NULL ORDER BY name ASC")->fetchAll();

$rules = $db->query("
    SELECT r.*, cty.label AS type_label, cty.icon AS type_icon,
           (SELECT COUNT(*) FROM certificates WHERE rule_id = r.id) AS issued_count
    FROM certificate_rules r
    LEFT JOIN certificate_types cty ON cty.id = r.certificate_type_id
    WHERE r.deleted_at IS NULL
    ORDER BY r.created_at DESC
")->fetchAll();

$editing = null; $editCfg = [];
if ($action === 'edit' && $id) {
    $stmt = $db->prepare("SELECT * FROM certificate_rules WHERE id=?"); $stmt->execute([$id]);
    $editing = $stmt->fetch();
    if ($editing) $editCfg = json_decode($editing['trigger_config'] ?? '{}', true) ?: [];
}
?>

<?php if (isset($_GET['deleted'])): ?><div class="admin-alert admin-alert-success">Rule deleted.</div><?php endif; ?>
<?php if ($success): ?><div class="admin-alert admin-alert-success"><?= sanitize($success) ?></div><?php endif; ?>
<?php if (!empty($errors)): ?><div class="admin-alert admin-alert-error"><?php foreach ($errors as $e): ?><div><?= sanitize($e) ?></div><?php endforeach; ?></div><?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0">Automatic Certificate Rules</h1>
    <a href="<?= SITE_URL ?>/admin/certificate-templates.php" class="btn-ghost btn-sm">← Templates</a>
</div>
<p style="font-size:0.84rem;color:var(--text-muted);margin-bottom:22px;max-width:680px">
    Guide-completion certificates are configured directly on each guide (Admin → Guides → edit → Certificate Settings).
    Rules here cover everything else: quiz scores, XP levels, badges, and premium purchases.
</p>

<?php if ($action === 'list'): ?>

<div style="margin-bottom:20px"><a href="?action=edit" class="btn-primary btn-sm">+ New Rule</a></div>

<div class="admin-table-wrap">
<table class="admin-table">
<thead><tr><th>Rule</th><th>Type</th><th>Trigger</th><th>Issued</th><th>Status</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($rules as $r): $cfg = json_decode($r['trigger_config'] ?? '{}', true) ?: []; ?>
<tr>
    <td style="font-weight:600"><?= sanitize($r['name']) ?></td>
    <td style="font-size:0.8rem;color:var(--text-muted)"><?= $r['type_icon'] ?? '' ?> <?= sanitize($r['type_label'] ?? '—') ?></td>
    <td style="font-size:0.78rem">
        <?= sanitize($triggerLabels[$r['trigger_type']] ?? $r['trigger_type']) ?>
        <?php if ($r['trigger_type']==='quiz_score' && !empty($cfg['min_score'])): ?><br><span style="color:var(--text-muted)">≥ <?= (int)$cfg['min_score'] ?>%</span><?php endif; ?>
        <?php if ($r['trigger_type']==='xp_level' && !empty($cfg['level_number'])): ?><br><span style="color:var(--text-muted)">Level <?= (int)$cfg['level_number'] ?>+</span><?php endif; ?>
    </td>
    <td style="text-align:center;font-family:var(--font-mono)"><?= (int)$r['issued_count'] ?></td>
    <td style="text-align:center">
        <a href="?action=toggle&id=<?= $r['id'] ?>" style="padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:700;text-decoration:none;<?= $r['is_active'] ? 'background:rgba(34,197,94,0.12);color:#22c55e' : 'background:rgba(148,148,163,0.12);color:var(--text-muted)' ?>">
            <?= $r['is_active'] ? 'Active' : 'Disabled' ?>
        </a>
    </td>
    <td>
        <div style="display:flex;gap:6px">
            <a href="?action=edit&id=<?= $r['id'] ?>" class="btn-ghost btn-sm">Edit</a>
            <a href="?action=delete&id=<?= $r['id'] ?>" class="btn-danger btn-sm" onclick="return confirm('Delete this rule?')">Delete</a>
        </div>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($rules)): ?><tr><td colspan="6" class="admin-empty">No automatic rules yet.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<?php else: ?>

<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px;max-width:640px">
<form method="POST" action="<?= $id ? '?action=edit&id='.$id : '?action=edit' ?>">
    <div class="form-group">
        <label class="form-label">Rule Name</label>
        <input type="text" name="name" class="form-input" value="<?= sanitize($editing['name'] ?? '') ?>" placeholder="e.g. Quiz Mastery — Advanced Solidity" required>
    </div>

    <div class="form-group">
        <label class="form-label">Certificate Type</label>
        <select name="certificate_type_id" class="form-select" required>
            <option value="">— choose —</option>
            <?php foreach ($types as $ty): ?>
            <option value="<?= $ty['id'] ?>" <?= (int)($editing['certificate_type_id'] ?? 0)===(int)$ty['id']?'selected':'' ?>><?= $ty['icon'] ?> <?= sanitize($ty['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label class="form-label">Template <span style="font-weight:400;color:var(--text-muted)">(optional — blank uses the type's default template)</span></label>
        <select name="template_id" class="form-select">
            <option value="">— use type default —</option>
            <?php foreach ($templates as $tp): ?>
            <option value="<?= $tp['id'] ?>" <?= (int)($editing['template_id'] ?? 0)===(int)$tp['id']?'selected':'' ?>><?= sanitize($tp['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label class="form-label">Award this certificate when…</label>
        <select name="trigger_type" id="triggerSelect" class="form-select" required onchange="toggleTriggerFields()">
            <option value="">— choose a trigger —</option>
            <?php foreach ($triggerLabels as $key => $label): ?>
            <option value="<?= $key ?>" <?= ($editing['trigger_type'] ?? '')===$key?'selected':'' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div id="cfg_quiz_score" class="trigger-cfg" style="display:none">
        <div class="form-group">
            <label class="form-label">Quiz <span style="font-weight:400;color:var(--text-muted)">(blank = any quiz)</span></label>
            <select name="cfg_quiz_id" class="form-select">
                <option value="">— any quiz —</option>
                <?php foreach ($quizzes as $q): ?>
                <option value="<?= $q['id'] ?>" <?= (int)($editCfg['quiz_id'] ?? 0)===(int)$q['id']?'selected':'' ?>><?= sanitize($q['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Minimum Score (%)</label>
            <input type="number" min="0" max="100" name="cfg_min_score" class="form-input" value="<?= (int)($editCfg['min_score'] ?? 80) ?>">
        </div>
    </div>

    <div id="cfg_xp_level" class="trigger-cfg" style="display:none">
        <div class="form-group">
            <label class="form-label">Level Number Reached</label>
            <input type="number" min="1" name="cfg_level_number" class="form-input" value="<?= (int)($editCfg['level_number'] ?? 5) ?>">
        </div>
    </div>

    <div id="cfg_badge_earned" class="trigger-cfg" style="display:none">
        <div class="form-group">
            <label class="form-label">Badge</label>
            <select name="cfg_badge_id" class="form-select">
                <option value="">— choose a badge —</option>
                <?php foreach ($badges as $b): ?>
                <option value="<?= $b['id'] ?>" <?= (int)($editCfg['badge_id'] ?? 0)===(int)$b['id']?'selected':'' ?>><?= sanitize($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div id="cfg_custom_event" class="trigger-cfg" style="display:none">
        <div class="form-group">
            <label class="form-label">Event Key</label>
            <input type="text" name="cfg_event_key" class="form-input" value="<?= sanitize($editCfg['event_key'] ?? '') ?>" placeholder="e.g. hackathon_2026_winner">
            <div class="form-hint">Fire this from custom code with: <code>evaluateCertificateRules($userId, 'custom_event', ['event_key' =&gt; 'hackathon_2026_winner']);</code></div>
        </div>
    </div>

    <div id="cfg_premium_active" class="trigger-cfg" style="display:none">
        <p style="font-size:0.8rem;color:var(--text-muted)">Fires automatically the first time a user's premium subscription is activated. No extra configuration needed.</p>
    </div>

    <div class="form-group">
        <label style="display:flex;align-items:center;gap:8px;font-size:0.85rem">
            <input type="checkbox" name="is_active" <?= ($editing['is_active'] ?? 1) ? 'checked' : '' ?>> Active
        </label>
    </div>

    <div style="display:flex;gap:10px">
        <button type="submit" class="btn-primary" style="flex:1"><?= $id ? 'Save Changes' : 'Create Rule' ?></button>
        <a href="<?= SITE_URL ?>/admin/certificate-rules.php" class="btn-ghost">Cancel</a>
    </div>
</form>
</div>

<script>
function toggleTriggerFields() {
    document.querySelectorAll('.trigger-cfg').forEach(el => el.style.display = 'none');
    const val = document.getElementById('triggerSelect').value;
    const target = document.getElementById('cfg_' + val);
    if (target) target.style.display = 'block';
}
toggleTriggerFields();
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
