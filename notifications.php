<?php
require_once __DIR__ . '/includes/config.php';
requireLogin();
requireProfileComplete();

$pageTitle = 'Notifications';
$db = getDB();
$userId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    $annStmt = $db->prepare("SELECT link FROM notifications WHERE user_id=? AND is_read=0 AND type='announcement'");
    $annStmt->execute([$userId]);
    $annLinks = $annStmt->fetchAll(PDO::FETCH_COLUMN);

    $db->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0")->execute([$userId]);

    if ($annLinks) {
        $insertRead = $db->prepare("INSERT IGNORE INTO user_announcement_reads (user_id, announcement_id) VALUES (?,?)");
        foreach ($annLinks as $link) {
            if (preg_match('/[?&]ann=(\d+)/', $link, $m)) {
                $insertRead->execute([$userId, (int)$m[1]]);
            }
        }
    }
    header('Location: ' . SITE_URL . '/notifications.php'); exit;
}
if (isset($_GET['read']) && intval($_GET['read'])) {
    $notifId = intval($_GET['read']);
    $rowStmt = $db->prepare("SELECT type, link FROM notifications WHERE id=? AND user_id=?");
    $rowStmt->execute([$notifId, $userId]);
    $notifRow = $rowStmt->fetch();

    $db->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([$notifId, $userId]);

    if ($notifRow && $notifRow['type'] === 'announcement' && !empty($notifRow['link'])
        && preg_match('/[?&]ann=(\d+)/', $notifRow['link'], $m)) {
        $db->prepare("INSERT IGNORE INTO user_announcement_reads (user_id, announcement_id) VALUES (?,?)")
           ->execute([$userId, (int)$m[1]]);
    }

    $redirect = $_GET['link'] ?? SITE_URL . '/notifications.php';
    header('Location: ' . $redirect); exit;
}

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;
$filterType = trim($_GET['type'] ?? '');
$search = trim($_GET['q'] ?? '');

$typeFilterClause = '';
$typeFilterParams = [$userId];
if ($filterType === 'unread') {
    $typeFilterClause = ' AND is_read = 0';
} elseif ($filterType !== '') {
    $typeFilterClause = ' AND type = ?';
    $typeFilterParams[] = $filterType;
}
if ($search !== '') {
    $typeFilterClause .= ' AND (title LIKE ? OR body LIKE ?)';
    $like = '%' . $search . '%';
    array_push($typeFilterParams, $like, $like);
}

$total = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=?" . $typeFilterClause);
$total->execute($typeFilterParams);
$pages = max(1, (int)ceil($total->fetchColumn() / $perPage));

$stmt = $db->prepare("SELECT * FROM notifications WHERE user_id=?" . $typeFilterClause . " ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($typeFilterParams);
$notifications = $stmt->fetchAll();

// Distinct types this user actually has, for the filter tabs — never shows
// an empty/dead filter option.
$typesPresent = $db->prepare("SELECT DISTINCT type FROM notifications WHERE user_id=? ORDER BY type ASC");
$typesPresent->execute([$userId]);
$typesPresent = $typesPresent->fetchAll(PDO::FETCH_COLUMN);

/**
 * Notification type → [icon svg, label, color class].
 * Prefix-matched so future notification types (ai_*, airdrop_*, blog_*)
 * still resolve to a sensible icon/label without code changes.
 */
function notifTypeMeta(string $type): array {
    $icons = [
        'check'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        'award'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"/><path d="M15.48 13.34 17 21l-5-3-5 3 1.52-7.66"/></svg>',
        'star'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
        'crown'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m2 20 2-9 5 4 3-8 3 8 5-4 2 9Z"/><path d="M2 20h20"/></svg>',
        'clock'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        'lock'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
        'x'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        'bot'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>',
        'gift'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C11 3 12 8 12 8"/><path d="M16.5 8a2.5 2.5 0 0 0 0-5C13 3 12 8 12 8"/></svg>',
        'blog'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
        'megaphone' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>',
        'flame'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
        'trend'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>',
        'bell'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>',
    ];

    return match (true) {
        $type === 'guide_completed'                 => [$icons['check'],     'Guide Completed',   'notif-icon-success'],
        $type === 'certificate_issued'               => [$icons['award'],     'Certificate Earned', 'notif-icon-magenta'],
        $type === 'badge_earned'                      => [$icons['star'],      'Badge Unlocked',     'notif-icon-warning'],
        $type === 'premium_purchase'
            || $type === 'premium_renewed'            => [$icons['crown'],     'Premium',            'notif-icon-success'],
        $type === 'premium_expiring'                  => [$icons['clock'],     'Premium',            'notif-icon-warning'],
        $type === 'premium_expired'                   => [$icons['lock'],      'Premium',            'notif-icon-danger'],
        $type === 'subscription_cancelled'            => [$icons['x'],         'Premium',            'notif-icon-danger'],
        $type === 'level_up'                          => [$icons['trend'],     'Progress',           'notif-icon-success'],
        $type === 'streak_milestone'                  => [$icons['flame'],     'Progress',           'notif-icon-warning'],
        $type === 'admin_message'                     => [$icons['megaphone'], 'System',             'notif-icon-neutral'],
        $type === 'announcement'                      => [$icons['megaphone'], 'Announcement',       'notif-icon-magenta'],
        str_starts_with($type, 'airdrop')             => [$icons['gift'],      'Airdrop',            'notif-icon-magenta'],
        str_starts_with($type, 'ai')                  => [$icons['bot'],       'AI Tutor',           ''],
        str_starts_with($type, 'blog')                => [$icons['blog'],      'Blog',               ''],
        $type === 'bounty_winner'                     => [$icons['award'],     'Bounty Won',         'notif-icon-success'],
        $type === 'bounty_thanks'                     => [$icons['bell'],      'Bounty',             ''],
        $type === 'bounty_published'                  => [$icons['trend'],     'Bounty',             'notif-icon-success'],
        $type === 'bounty_expiring'                   => [$icons['clock'],     'Bounty',             'notif-icon-warning'],
        $type === 'bounty_approved'                   => [$icons['check'],     'Bounty',             'notif-icon-success'],
        $type === 'bounty_rejected'                   => [$icons['x'],         'Bounty',             'notif-icon-danger'],
        $type === 'bounty_cancelled'                  => [$icons['x'],         'Bounty',             'notif-icon-danger'],
        $type === 'bounty_payment_approved'           => [$icons['check'],     'Payment',            'notif-icon-success'],
        $type === 'bounty_payment_rejected'           => [$icons['x'],         'Payment',            'notif-icon-danger'],
        str_starts_with($type, 'host_profile_approved')        => [$icons['check'], 'Host Profile',   'notif-icon-success'],
        str_starts_with($type, 'host_profile_request_changes') => [$icons['clock'], 'Host Profile',   'notif-icon-warning'],
        str_starts_with($type, 'host_profile_rejected')        => [$icons['x'],     'Host Profile',   'notif-icon-danger'],
        str_starts_with($type, 'host_profile_suspended')       => [$icons['x'],     'Host Profile',   'notif-icon-danger'],
        str_starts_with($type, 'submission_received')          => [$icons['check'], 'Submission',     ''],
        str_starts_with($type, 'submission_under_review')      => [$icons['clock'], 'Submission',     'notif-icon-warning'],
        str_starts_with($type, 'submission_cancelled')         => [$icons['x'],     'Submission',     'notif-icon-danger'],
        $type === 'project_submitted'                 => [$icons['check'],     'Project',            ''],
        default                                        => [$icons['bell'],     'System',             'notif-icon-neutral'],
    };
}

$extraCss = [SITE_URL . '/assets/css/notifications.css'];
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header container">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <div class="section-label">Stay updated</div>
      <h1 class="page-header-title">Notifications</h1>
    </div>
    <form method="POST"><input type="hidden" name="action" value="mark_all_read"><button type="submit" class="btn btn-secondary btn-sm">Mark all read</button></form>
  </div>
</div>

<div class="container" style="max-width:720px;padding-top:0">
  <form method="GET" style="margin-bottom:16px">
    <?php if ($filterType !== ''): ?><input type="hidden" name="type" value="<?= sanitize($filterType) ?>"><?php endif; ?>
    <input type="search" name="q" class="form-input" placeholder="Search notifications…" value="<?= sanitize($search) ?>">
  </form>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px">
    <a href="?<?= $search !== '' ? 'q=' . urlencode($search) . '&' : '' ?>" class="btn btn-<?= $filterType===''?'primary':'ghost' ?> btn-sm">All</a>
    <a href="?type=unread<?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" class="btn btn-<?= $filterType==='unread'?'primary':'ghost' ?> btn-sm">Unread</a>
    <?php foreach ($typesPresent as $t): [, $tLabel] = notifTypeMeta($t); ?>
    <a href="?type=<?= urlencode($t) ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" class="btn btn-<?= $filterType===$t?'primary':'ghost' ?> btn-sm"><?= sanitize($tLabel) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="container section" style="padding-top:0;max-width:720px">
  <?php if (empty($notifications)): ?>
    <div class="learn-empty">
      <?php if ($search !== '' || $filterType !== ''): ?>
        <h3>No matching notifications</h3>
        <p>Try a different search term or clear the filter.</p>
      <?php else: ?>
        <h3>No notifications yet</h3>
        <p>Guide updates, badges, and account activity will show up here.</p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="notif-list">
      <?php foreach ($notifications as $n):
        [$icon, $label, $colorClass] = notifTypeMeta($n['type']);
        $isUnread = !$n['is_read'];
      ?>
        <a href="<?= SITE_URL ?>/notifications.php?read=<?= $n['id'] ?>&link=<?= urlencode($n['link'] ?: SITE_URL.'/notifications.php') ?>"
           class="notif-card<?= $isUnread ? ' is-unread' : '' ?>">
          <div class="notif-icon <?= $colorClass ?>"><?= $icon ?></div>
          <div class="notif-body">
            <div class="notif-top-row">
              <div class="notif-title-group">
                <span class="notif-title"><?= htmlspecialchars($n['title']) ?></span>
                <span class="notif-type-tag"><?= htmlspecialchars($label) ?></span>
              </div>
              <?php if ($isUnread): ?><span class="notif-dot-unread" aria-label="Unread"></span><?php endif; ?>
            </div>
            <?php if ($n['body']): ?><div class="notif-desc"><?= htmlspecialchars($n['body']) ?></div><?php endif; ?>
            <div class="notif-meta-row">
              <span><?= timeAgo($n['created_at']) ?></span>
              <?php if ($isUnread): ?><span class="badge badge-accent">New</span><?php endif; ?>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
    <div class="pagination" style="margin-top:var(--space-6);justify-content:center">
      <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a href="?page=<?= $i ?><?= $filterType !== '' ? '&type=' . urlencode($filterType) : '' ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
