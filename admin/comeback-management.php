<?php
/**
 * Admin — Comeback & Inactivity Management
 * Manages comeback_rules (Phase 2 migration) — each row configures BOTH
 * the proactive inactivity nudge email at that threshold (template_key)
 * AND the reward paid out if/when the user actually returns
 * (xp_reward/freeze_reward). See includes/gamification.php
 * evaluateComebackBonus() and includes/notifications/InactivityRunner.php
 * for the logic that reads this table — nothing here recalculates
 * anything, it only edits the config those already-built systems read.
 */
$adminTitle = 'Comeback & Inactivity';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/gamification.php';

$db  = getDB();
$tab = $_GET['tab'] ?? 'rules';
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'save_rule') {
        $editId       = intval($_POST['id'] ?? 0);
        $days         = intval($_POST['days_threshold'] ?? 0);
        $label        = trim($_POST['label'] ?? '');
        $basis        = in_array($_POST['basis'] ?? '', ['learning_activity', 'login'], true) ? $_POST['basis'] : 'learning_activity';
        $templateKey  = trim($_POST['template_key'] ?? '') ?: null;
        $xpReward     = max(0, intval($_POST['xp_reward'] ?? 0));
        $freezeReward = max(0, intval($_POST['freeze_reward'] ?? 0));
        $badgeId      = intval($_POST['badge_id'] ?? 0) ?: null;
        $active       = isset($_POST['is_active']) ? 1 : 0;

        if ($days > 0 && $label !== '') {
            if ($editId) {
                $db->prepare("UPDATE comeback_rules SET days_threshold=?, label=?, basis=?, template_key=?, xp_reward=?, freeze_reward=?, badge_id=?, is_active=? WHERE id=?")
                   ->execute([$days, $label, $basis, $templateKey, $xpReward, $freezeReward, $badgeId, $active, $editId]);
            } else {
                $db->prepare("INSERT INTO comeback_rules (days_threshold, label, basis, template_key, xp_reward, freeze_reward, badge_id, is_active) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$days, $label, $basis, $templateKey, $xpReward, $freezeReward, $badgeId, $active]);
            }
            $msg = 'Rule saved.';
        } else {
            $err = 'Days threshold and label are required.';
        }
    }

    if ($postAction === 'toggle_rule') {
        $db->prepare("UPDATE comeback_rules SET is_active = NOT is_active WHERE id=?")->execute([intval($_POST['id'] ?? 0)]);
        header('Location: ?tab=rules'); exit;
    }

    // Deliberately no hard-delete: this table is the audit trail's join
    // target (inactivity_reminder_log.rule_id / comeback_reward_log.rule_id
    // both FK here), so deleting a rule that already has history would
    // orphan or cascade-delete real reward records. Deactivate instead.
}

if ($tab === 'rules') {
    $rules  = $db->query("SELECT cr.*, b.name AS badge_name FROM comeback_rules cr LEFT JOIN badges b ON b.id = cr.badge_id ORDER BY cr.days_threshold ASC")->fetchAll();
    $badges = $db->query("SELECT id, name FROM badges WHERE is_active=1 ORDER BY name ASC")->fetchAll();
} else {
    $rewardLog = $db->query("
        SELECT crl.*, u.username, cr.label, cr.days_threshold
        FROM comeback_reward_log crl
        JOIN users u ON u.id = crl.user_id
        JOIN comeback_rules cr ON cr.id = crl.rule_id
        ORDER BY crl.awarded_at DESC LIMIT 50
    ")->fetchAll();
    $reminderLog = $db->query("
        SELECT irl.*, u.username, cr.label, cr.days_threshold, cr.template_key
        FROM inactivity_reminder_log irl
        JOIN users u ON u.id = irl.user_id
        JOIN comeback_rules cr ON cr.id = irl.rule_id
        ORDER BY irl.sent_at DESC LIMIT 50
    ")->fetchAll();
}
?>

<?php if (!empty($msg)): ?><div class="admin-alert admin-alert-success"><?= sanitize($msg) ?></div><?php endif; ?>
<?php if (!empty($err)): ?><div class="admin-alert admin-alert-error"><?= sanitize($err) ?></div><?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0">Comeback &amp; Inactivity</h1>
    <div style="display:flex;gap:8px">
        <a href="?tab=rules" class="btn-<?= $tab==='rules'?'primary':'ghost' ?> btn-sm">Rules</a>
        <a href="?tab=log" class="btn-<?= $tab==='log'?'primary':'ghost' ?> btn-sm">Activity Log</a>
    </div>
</div>

<?php if ($tab === 'rules'): ?>

<div style="display:grid;grid-template-columns:1fr 1.6fr;gap:20px">
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;align-self:start">
        <h2 style="font-size:0.9rem;font-weight:700;margin-bottom:14px">Add Threshold</h2>
        <form method="POST">
            <input type="hidden" name="action" value="save_rule">
            <div class="form-group"><label class="form-label">Days Inactive</label><input type="number" name="days_threshold" class="form-input" min="1" required placeholder="e.g. 14"></div>
            <div class="form-group"><label class="form-label">Label</label><input type="text" name="label" class="form-input" required placeholder="e.g. Comeback bonus"></div>
            <div class="form-group">
                <label class="form-label">Measure inactivity by</label>
                <select name="basis" class="form-select">
                    <option value="learning_activity">Last learning activity (recommended)</option>
                    <option value="login">Last login</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Email template key (optional)</label>
                <input type="text" name="template_key" class="form-input" placeholder="e.g. inactivity_nudge_3day">
                <div class="field-hint">Must exist in Email Templates. Leave blank for a reward-only rule with no email.</div>
            </div>
            <div class="form-group"><label class="form-label">XP Reward on return</label><input type="number" name="xp_reward" class="form-input" min="0" value="0"></div>
            <div class="form-group"><label class="form-label">Streak Freeze Reward on return</label><input type="number" name="freeze_reward" class="form-input" min="0" value="0"></div>
            <div class="form-group">
                <label class="form-label">Badge on return (optional)</label>
                <select name="badge_id" class="form-select">
                    <option value="">— None —</option>
                    <?php foreach ($badges as $b): ?><option value="<?= $b['id'] ?>"><?= sanitize($b['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.84rem">
                    <input type="checkbox" name="is_active" value="1" checked> <span>Active</span>
                </label>
            </div>
            <button class="btn-primary btn-sm" type="submit" style="width:100%">Save Rule</button>
        </form>
    </div>

    <div class="admin-table-wrap">
    <table class="admin-table">
    <thead><tr><th>Days</th><th>Label</th><th>Basis</th><th>Email</th><th>XP</th><th>Freeze</th><th>Badge</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rules as $r): ?>
    <tr>
        <td style="font-weight:700"><?= (int)$r['days_threshold'] ?>d</td>
        <td><?= sanitize($r['label']) ?></td>
        <td style="font-size:0.78rem;color:var(--text-muted)"><?= $r['basis'] === 'login' ? 'Last login' : 'Learning activity' ?></td>
        <td style="font-size:0.78rem;color:var(--text-muted)"><?= $r['template_key'] ? sanitize($r['template_key']) : '— reward only —' ?></td>
        <td><?= (int)$r['xp_reward'] ?: '—' ?></td>
        <td><?= (int)$r['freeze_reward'] ?: '—' ?></td>
        <td><?= sanitize($r['badge_name'] ?? '—') ?></td>
        <td><span style="padding:3px 8px;border-radius:20px;font-size:0.72rem;font-weight:700;<?= $r['is_active']?'background:rgba(34,197,94,0.12);color:#86efac':'background:rgba(107,114,128,0.12);color:var(--text-muted)' ?>"><?= $r['is_active']?'Active':'Inactive' ?></span></td>
        <td>
            <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="toggle_rule"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button class="btn-ghost btn-sm"><?= $r['is_active'] ? 'Deactivate' : 'Activate' ?></button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($rules)): ?><tr><td colspan="9" style="text-align:center;color:var(--text-muted);padding:24px">No comeback rules configured.</td></tr><?php endif; ?>
    </tbody>
    </table>
    </div>
</div>

<?php else: ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
    <div>
        <h2 style="font-size:0.9rem;font-weight:700;margin-bottom:14px">Recent Comeback Rewards</h2>
        <div class="admin-table-wrap">
        <table class="admin-table">
        <thead><tr><th>User</th><th>Tier</th><th>XP</th><th>Freeze</th><th>Awarded</th></tr></thead>
        <tbody>
        <?php foreach ($rewardLog as $l): ?>
        <tr>
            <td style="font-weight:600"><?= sanitize($l['username']) ?></td>
            <td><?= (int)$l['days_threshold'] ?>d — <?= sanitize($l['label']) ?></td>
            <td><?= (int)$l['xp_awarded'] ?: '—' ?></td>
            <td><?= (int)$l['freeze_awarded'] ?: '—' ?></td>
            <td style="font-size:0.78rem;color:var(--text-muted)"><?= date('M j, Y', strtotime($l['awarded_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($rewardLog)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:24px">No comeback rewards awarded yet.</td></tr><?php endif; ?>
        </tbody>
        </table>
        </div>
    </div>
    <div>
        <h2 style="font-size:0.9rem;font-weight:700;margin-bottom:14px">Recent Reminder Emails</h2>
        <div class="admin-table-wrap">
        <table class="admin-table">
        <thead><tr><th>User</th><th>Tier</th><th>Sent</th></tr></thead>
        <tbody>
        <?php foreach ($reminderLog as $l): ?>
        <tr>
            <td style="font-weight:600"><?= sanitize($l['username']) ?></td>
            <td><?= (int)$l['days_threshold'] ?>d — <?= sanitize($l['label']) ?></td>
            <td style="font-size:0.78rem;color:var(--text-muted)"><?= date('M j, Y', strtotime($l['sent_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($reminderLog)): ?><tr><td colspan="3" style="text-align:center;color:var(--text-muted);padding:24px">No reminder emails sent yet.</td></tr><?php endif; ?>
        </tbody>
        </table>
        </div>
    </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
