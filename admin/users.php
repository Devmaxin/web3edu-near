<?php
$adminTitle = 'Manage Users';

// Lightweight AJAX user search (used by admin/notifications.php's manual-send
// picker). Handled before header.php's HTML output so it can return pure JSON.
if (isset($_GET['ajax_search'])) {
    require_once __DIR__ . '/auth.php';
    requireAdmin();
    $q = trim($_GET['ajax_search']);
    header('Content-Type: application/json');
    if (strlen($q) < 2) { echo '[]'; exit; }
    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, email FROM users WHERE deleted_at IS NULL AND (username LIKE ? OR email LIKE ?) LIMIT 10");
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like]);
    echo json_encode($stmt->fetchAll());
    exit;
}

require_once __DIR__ . '/header.php';

$db = getDB();

// Toggle premium — backed by a real user_subscriptions row (see
// grantAdminPremium()/revokeAdminPremium() in includes/subscriptions.php)
// rather than just flipping the users.membership_type cache flag with
// nothing behind it. That flag is now derived, not authoritative.
if (isset($_GET['toggle_premium']) && intval($_GET['toggle_premium'])) {
    $uid = intval($_GET['toggle_premium']);
    if (userHasActivePremium($uid)) {
        revokeAdminPremium($uid);
    } else {
        grantAdminPremium($uid);
    }
    header('Location: ' . SITE_URL . '/admin/users.php');
    exit;
}

$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all';

$where  = ['1=1'];
$params = [];

if (!empty($search)) {
    $where[] = "(email LIKE ? OR username LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filter === 'premium') {
    $where[] = "membership_type = 'premium'";
} elseif ($filter === 'free') {
    $where[] = "membership_type = 'free'";
}

$sort = $_GET['sort'] ?? 'created_at';
$sortCol = in_array($sort, ['created_at', 'total_xp', 'current_level'], true) ? $sort : 'created_at';

$whereStr = implode(' AND ', $where);
$stmt = $db->prepare("SELECT * FROM users WHERE $whereStr ORDER BY $sortCol DESC");
$stmt->execute($params);
$users = $stmt->fetchAll();

// Level titles, keyed by level_number, for display next to each user's level.
$levelTitles = [];
foreach ($db->query("SELECT level_number, title FROM levels")->fetchAll() as $lv) {
    $levelTitles[(int)$lv['level_number']] = $lv['title'];
}

$totalUsers   = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$premiumUsers = $db->query("SELECT COUNT(*) FROM users WHERE membership_type='premium'")->fetchColumn();
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
    <h1 class="admin-page-title" style="margin:0">Users (<?= $totalUsers ?> total · <?= $premiumUsers ?> premium)</h1>
</div>

<!-- Search + filter -->
<div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap">
    <form style="flex:1;min-width:200px" method="GET">
        <div class="search-wrap">
            <span class="search-icon"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></span>
            <input type="text" name="search" class="search-input" placeholder="Search by email or username..." value="<?= sanitize($search) ?>">
            <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?= sanitize($filter) ?>"><?php endif; ?>
            <?php if ($sortCol !== 'created_at'): ?><input type="hidden" name="sort" value="<?= sanitize($sortCol) ?>"><?php endif; ?>
        </div>
    </form>
    <div class="tabs">
        <?php foreach (['all'=>'All','free'=>'Free','premium'=>'Premium'] as $f => $label): ?>
        <a href="?filter=<?= $f ?><?= $search?'&search='.urlencode($search):'' ?><?= $sortCol!=='created_at'?'&sort='.$sortCol:'' ?>" class="tab-btn <?= $filter===$f?'active':'' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
    <div class="tabs">
        <?php foreach (['created_at'=>'Newest','total_xp'=>'Highest XP','current_level'=>'Highest Level'] as $s => $label): ?>
        <a href="?sort=<?= $s ?><?= $filter!=='all'?'&filter='.$filter:'' ?><?= $search?'&search='.urlencode($search):'' ?>" class="tab-btn <?= $sortCol===$s?'active':'' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">
    <table class="admin-table">
        <thead><tr>
            <th>User</th><th>Username</th><th>Plan</th><th>XP</th><th>Level</th><th>Profile</th><th>Joined</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
            <td>
                <div style="display:flex;align-items:center;gap:10px">
                    <img src="<?= avatarUrl($u['avatar']) ?>" style="width:32px;height:32px;border-radius:50%;object-fit:cover;border:1px solid var(--border)" onerror="this.style.display='none'">
                    <span style="font-size:0.875rem"><?= sanitize($u['email']) ?></span>
                </div>
            </td>
            <td style="font-size:0.875rem;color:var(--text-dim)"><?= sanitize($u['username'] ?? '—') ?></td>
            <td>
                <span style="padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:700;
                    <?= $u['membership_type']==='premium'?'background:rgba(168,85,247,0.15);color:#d8b4fe':'background:rgba(255,255,255,0.05);color:var(--text-muted)' ?>">
                    <?= ucfirst($u['membership_type']) ?>
                </span>
            </td>
            <td style="font-size:0.875rem;color:var(--accent-bright, #a855f7)"><?= number_format((int)($u['total_xp'] ?? 0)) ?></td>
            <td style="font-size:0.875rem">
                L<?= (int)($u['current_level'] ?? 1) ?>
                <?php $lvNum = (int)($u['current_level'] ?? 1); if (isset($levelTitles[$lvNum])): ?>
                <span style="color:var(--text-muted);font-size:0.76rem"> · <?= sanitize($levelTitles[$lvNum]) ?></span>
                <?php endif; ?>
            </td>
            <td style="text-align:center;font-size:0.875rem"><?= $u['profile_completed'] ? '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>' : '⏳' ?></td>
            <td style="color:var(--text-muted);font-size:0.78rem"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
            <td>
                <a href="?toggle_premium=<?= $u['id'] ?>" class="<?= $u['membership_type']==='premium'?'btn-secondary':'btn-upgrade' ?>"
                   style="padding:4px 10px;font-size:0.72rem"
                   onclick="return confirm('Toggle premium for this user?')">
                    <?= $u['membership_type']==='premium'?'Remove Premium':'Grant Premium' ?>
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
