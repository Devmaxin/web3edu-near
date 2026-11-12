<?php
/**
 * Admin — Streak Management
 * Reuses the existing streak engine as-is (users.current_streak_days /
 * longest_streak_days / last_activity_date, streak_milestones,
 * updateStreak() in includes/gamification.php). This page only adds a
 * missing admin UI over data/logic that already exists — no new streak
 * calculation logic lives here.
 */
$adminTitle = 'Streak Management';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/gamification.php';

$db = getDB();
$tab = $_GET['tab'] ?? 'users';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'grant_freeze') {
        $targetUserId = intval($_POST['user_id'] ?? 0);
        $amount = max(1, intval($_POST['amount'] ?? 1));
        if ($targetUserId > 0) {
            grantStreakFreeze($targetUserId, $amount, 'admin_grant', 'Granted by admin #' . (int)($_SESSION['admin_id'] ?? 0));
            $msg = "Granted {$amount} streak freeze(s).";
        } else {
            $err = 'Select a user first.';
        }
    }

    if ($postAction === 'save_milestone') {
        $editId = intval($_POST['id'] ?? 0);
        $days   = intval($_POST['days_required'] ?? 0);
        $bonus  = intval($_POST['xp_bonus'] ?? 0);
        $badgeId = intval($_POST['badge_id'] ?? 0) ?: null;
        $active = isset($_POST['is_active']) ? 1 : 0;

        if ($days > 0) {
            if ($editId) {
                $db->prepare("UPDATE streak_milestones SET days_required=?, xp_bonus=?, badge_id=?, is_active=? WHERE id=?")
                   ->execute([$days, $bonus, $badgeId, $active, $editId]);
            } else {
                $db->prepare("INSERT IGNORE INTO streak_milestones (days_required, xp_bonus, badge_id, is_active) VALUES (?,?,?,?)")
                   ->execute([$days, $bonus, $badgeId, $active]);
            }
            $msg = 'Milestone saved.';
        } else {
            $err = 'Days Required must be greater than 0.';
        }
    }

    if ($postAction === 'delete_milestone') {
        $db->prepare("DELETE FROM streak_milestones WHERE id=?")->execute([intval($_POST['id'] ?? 0)]);
        header('Location: ?tab=milestones'); exit;
    }
}

if ($tab === 'milestones') {
    $milestones = $db->query("SELECT sm.*, b.name AS badge_name FROM streak_milestones sm LEFT JOIN badges b ON b.id = sm.badge_id ORDER BY sm.days_required ASC")->fetchAll();
    $badges = $db->query("SELECT id, name FROM badges WHERE is_active=1 ORDER BY name ASC")->fetchAll();
} else {
    // Leaderboard-ready: ordered by current streak, matching the existing
    // engine's own fields — no separate ranking table needed.
    $topStreaks = $db->query("
        SELECT id, username, current_streak_days, longest_streak_days, last_activity_date, streak_freeze_count
        FROM users WHERE current_streak_days > 0
        ORDER BY current_streak_days DESC LIMIT 25
    ")->fetchAll();

    // "At risk": last active yesterday — if they don't visit today, updateStreak()
    // will reset them to 1 on their next visit. Purely informational for admins.
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $atRiskStmt = $db->prepare("
        SELECT id, username, current_streak_days, last_activity_date
        FROM users WHERE last_activity_date = ? AND current_streak_days > 0
        ORDER BY current_streak_days DESC
    ");
    $atRiskStmt->execute([$yesterday]);
    $atRisk = $atRiskStmt->fetchAll();

    $avgStreak = (float)$db->query("SELECT AVG(current_streak_days) FROM users WHERE current_streak_days > 0")->fetchColumn();
    $activeStreakUsers = (int)$db->query("SELECT COUNT(*) FROM users WHERE current_streak_days > 0")->fetchColumn();
}
?>

<?php if (!empty($msg)): ?><div class="admin-alert admin-alert-success"><?= sanitize($msg) ?></div><?php endif; ?>
<?php if (!empty($err)): ?><div class="admin-alert admin-alert-error"><?= sanitize($err) ?></div><?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0">Streak Management</h1>
    <div style="display:flex;gap:8px">
        <a href="?tab=users" class="btn-<?= $tab==='users'?'primary':'ghost' ?> btn-sm">Users</a>
        <a href="?tab=milestones" class="btn-<?= $tab==='milestones'?'primary':'ghost' ?> btn-sm">Milestones</a>
    </div>
</div>

<?php if ($tab === 'milestones'): ?>

<div style="display:grid;grid-template-columns:1fr 1.4fr;gap:20px">
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;align-self:start">
        <h2 style="font-size:0.9rem;font-weight:700;margin-bottom:14px">Add Milestone</h2>
        <form method="POST">
            <input type="hidden" name="action" value="save_milestone">
            <div class="form-group"><label class="form-label">Days Required</label><input type="number" name="days_required" class="form-input" min="1" required placeholder="e.g. 7"></div>
            <div class="form-group"><label class="form-label">Bonus XP</label><input type="number" name="xp_bonus" class="form-input" min="0" value="0"></div>
            <div class="form-group">
                <label class="form-label">Badge (optional)</label>
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
            <button class="btn-primary btn-sm" type="submit" style="width:100%">Save Milestone</button>
        </form>
    </div>

    <div class="admin-table-wrap">
    <table class="admin-table">
    <thead><tr><th>Days</th><th>Bonus XP</th><th>Badge</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($milestones as $m): ?>
    <tr>
        <td style="font-weight:700">🔥 <?= (int)$m['days_required'] ?></td>
        <td><?= (int)$m['xp_bonus'] ?> XP</td>
        <td><?= sanitize($m['badge_name'] ?? '—') ?></td>
        <td><span style="padding:3px 8px;border-radius:20px;font-size:0.72rem;font-weight:700;<?= $m['is_active']?'background:rgba(11,121,88,0.12);color:#0B7958':'background:rgba(107,114,128,0.12);color:var(--text-muted)' ?>"><?= $m['is_active']?'Active':'Inactive' ?></span></td>
        <td>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this milestone?')">
                <input type="hidden" name="action" value="delete_milestone"><input type="hidden" name="id" value="<?= $m['id'] ?>">
                <button class="btn-danger btn-sm">Delete</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($milestones)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:24px">No milestones configured yet.</td></tr><?php endif; ?>
    </tbody>
    </table>
    </div>
</div>

<?php else: ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px">
    <div class="admin-stat-card"><div class="value"><?= number_format($activeStreakUsers) ?></div><div class="label">Users On a Streak</div></div>
    <div class="admin-stat-card"><div class="value"><?= number_format($avgStreak, 1) ?></div><div class="label">Average Streak (days)</div></div>
    <div class="admin-stat-card"><div class="value"><?= count($atRisk) ?></div><div class="label">Streaks At Risk Today</div></div>
</div>

<?php if (!empty($atRisk)): ?>
<div style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.3);border-radius:var(--radius-lg);padding:16px 20px;margin-bottom:20px">
    <h3 style="font-size:0.85rem;font-weight:700;margin-bottom:4px;color:#fbbf24">⚠️ At Risk Today</h3>
    <p style="font-size:0.78rem;color:var(--text-muted);margin-bottom:10px">These users were last active yesterday — their streak resets to 1 if they don't return today.</p>
    <div style="display:flex;flex-wrap:wrap;gap:8px">
        <?php foreach ($atRisk as $u): ?>
        <span style="font-size:0.78rem;padding:4px 10px;background:var(--bg-card2);border-radius:20px"><?= sanitize($u['username']) ?> (🔥<?= (int)$u['current_streak_days'] ?>)</span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="admin-table-wrap">
<table class="admin-table">
<thead><tr><th>#</th><th>User</th><th>Current Streak</th><th>Longest Streak</th><th>Last Active</th><th>Freezes</th><th>Grant Freeze</th></tr></thead>
<tbody>
<?php foreach ($topStreaks as $i => $u): ?>
<tr>
    <td style="color:var(--text-muted)"><?= $i + 1 ?></td>
    <td style="font-weight:600"><?= sanitize($u['username']) ?></td>
    <td>🔥 <?= (int)$u['current_streak_days'] ?> days</td>
    <td style="color:var(--text-muted)"><?= (int)$u['longest_streak_days'] ?> days</td>
    <td style="font-size:0.8rem;color:var(--text-muted)"><?= $u['last_activity_date'] ? date('M j, Y', strtotime($u['last_activity_date'])) : '—' ?></td>
    <td>🧊 <?= (int)$u['streak_freeze_count'] ?></td>
    <td>
        <form method="POST" style="display:flex;gap:6px;align-items:center">
            <input type="hidden" name="action" value="grant_freeze">
            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
            <input type="number" name="amount" value="1" min="1" class="form-input" style="width:60px;padding:4px 6px">
            <button class="btn-ghost btn-sm" type="submit">Grant</button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($topStreaks)): ?><tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:24px">No active streaks yet.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
