<?php
/**
 * Web3Edu v2 — shared header/nav.
 * Assumes includes/config.php is already loaded by the calling page.
 * Reuses existing helpers as-is: isLoggedIn(), isPremium(), getCurrentUser(), avatarUrl().
 */
require_once __DIR__ . '/check_maintenance.php';
require_once __DIR__ . '/gamification.php';
require_once __DIR__ . '/bounties.php'; // needed by the profile dropdown's Host Profile links below
$currentUser = isLoggedIn() ? getCurrentUser() : null;

// Daily login streak + XP — fires at most once per calendar day per user.
// updateStreak() is itself idempotent (checks users.last_activity_date), and
// awardXp()'s dedupe uses today's date as the reference id so a page reload
// never double-awards. Centralized here (not per-feature) so every logged-in
// page view anywhere on the site counts as "active today", matching the
// existing streak engine's original lesson-completion-only behavior but
// widened to the whole site per Phase 3's "daily login" requirement.
if ($currentUser) {
    updateStreak((int)$currentUser['id']);
    awardXp((int)$currentUser['id'], 'daily_login', 'login_day', (int)date('Ymd'));
}

$currentPath = basename($_SERVER['PHP_SELF']);
$navLinks = [
    'index.php'    => 'Home',
    'about.php'    => 'About',
    'learn.php'    => 'Learn',
    'opportunities.php' => 'Opportunities',
    'videos.php'   => 'Videos',
    'airdrops.php' => 'Airdrops',
    'ai-tutor.php' => 'AI Tutor',
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) . ' — Web3Edu' : 'Web3Edu — Learn Web3 & Blockchain' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">

<!-- v2 design system -->
<script>window.SITE_URL = <?= json_encode(SITE_URL) ?>;</script>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/tokens.css">
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/components.css">
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/layout.css">

<!-- TEMPORARY: legacy stylesheet, still required by pages not yet migrated to v2.
     Remove once every page in the rebuild plan has been converted. -->
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/main.css">

<link rel="icon" type="image/png" href="<?= SITE_URL ?>/assets/images/favicon.png">
<link rel="apple-touch-icon" href="<?= SITE_URL ?>/assets/images/favicon.png">

<?php if (!empty($extraCss)): foreach ((array)$extraCss as $css): ?>
<?php
  // Cache-bust every stylesheet with its on-disk modification time, so a
  // deployed CSS change is guaranteed to be re-fetched instead of served
  // from a stale browser (or CDN) cache — the exact same class of "looks
  // unchanged after I redeployed" issue we hit earlier with a JS file,
  // just for CSS. $css is normally SITE_URL + a local /assets/... path;
  // map it back to the on-disk file to read its mtime, falling back to
  // the plain URL untouched if that mapping ever fails (e.g. a genuinely
  // external stylesheet URL).
  $cssOut = htmlspecialchars($css);
  if (str_starts_with($css, SITE_URL)) {
      $localPath = __DIR__ . '/..' . substr($css, strlen(SITE_URL));
      $mtime = @filemtime($localPath);
      if ($mtime) {
          $cssOut .= (str_contains($css, '?') ? '&' : '?') . 'v=' . $mtime;
      }
  }
?>
<link rel="stylesheet" href="<?= $cssOut ?>">
<?php endforeach; endif; ?>

<!-- Mobile audit pass (320–768px). Loaded last so its @media (max-width) rules
     reliably win the cascade. Every rule in this file is gated behind a
     max-width query, so desktop (>=900px) is never affected. -->
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/mobile-fixes.css">
</head>
<body>

<header class="site-nav" id="siteHeader">
  <div class="container site-nav-inner">
    <a href="<?= SITE_URL ?>/index.php" class="site-logo">
      <img src="<?= SITE_URL ?>/assets/images/logo-wordmark.png" alt="Web3Edu" class="site-logo-word">
    </a>

    <nav class="site-nav-links" id="mainNav">
      <?php foreach ($navLinks as $href => $label): ?>
        <a href="<?= SITE_URL ?>/<?= $href ?>" class="<?= $currentPath === $href ? 'active' : '' ?>"><?= $label ?></a>
      <?php endforeach; ?>
      <div class="site-nav-links-mobile-extra">
        <?php if ($currentUser): ?>
          <a href="<?= SITE_URL ?>/dashboard.php">Dashboard</a>
          <a href="<?= SITE_URL ?>/profile.php">Profile</a>
          <?php if (!isPremium()): ?><a href="<?= SITE_URL ?>/upgrade.php" style="color:var(--accent-bright)">Upgrade to Premium</a><?php endif; ?>
          <a href="<?= SITE_URL ?>/logout.php">Logout</a>
        <?php else: ?>
          <a href="<?= SITE_URL ?>/login.php">Log in</a>
          <a href="<?= SITE_URL ?>/register.php">Start free</a>
        <?php endif; ?>
      </div>
    </nav>

    <div class="site-nav-actions">
      <button class="icon-btn" id="themeToggle" aria-label="Toggle dark and light theme">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
      </button>

      <?php if ($currentUser):
        $unreadStmt = getDB()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
        $unreadStmt->execute([$currentUser['id']]);
        $unreadCount = (int)$unreadStmt->fetchColumn();
      ?>
        <div class="dropdown" id="notifDropdown">
          <button class="icon-btn notif-btn" id="notifBtnTrigger" aria-label="Notifications<?= $unreadCount > 0 ? ", {$unreadCount} unread" : '' ?>" aria-haspopup="true" aria-expanded="false">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
            <?php if ($unreadCount > 0): ?>
              <span class="notif-dot" id="notifDotIndicator" style="width:auto;height:16px;min-width:16px;border-radius:9px;padding:0 4px;display:flex;align-items:center;justify-content:center;font-size:0.62rem;font-weight:700;color:#fff;top:-2px;right:-2px">
                <?= $unreadCount > 9 ? '9+' : $unreadCount ?>
              </span>
            <?php endif; ?>
          </button>
          <div class="dropdown-menu" id="notifPreviewMenu" style="width:320px;max-height:400px;overflow-y:auto">
            <div style="padding:10px 14px;display:flex;justify-content:space-between;align-items:center;font-size:0.78rem;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border)">
              <span>Notifications</span>
              <?php if ($unreadCount > 0): ?>
              <form method="POST" action="<?= SITE_URL ?>/notifications.php" style="margin:0">
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" style="background:none;border:none;color:var(--primary-bright);font-size:0.72rem;font-weight:600;cursor:pointer;padding:0">Mark all read</button>
              </form>
              <?php endif; ?>
            </div>
            <div id="notifPreviewList" style="padding:6px"></div>
            <a href="<?= SITE_URL ?>/notifications.php" class="dropdown-item" style="text-align:center;font-size:0.8rem;color:var(--primary-bright);border-top:1px solid var(--border)">View All →</a>
          </div>
        </div>
        <script>
        (function () {
            const btn = document.getElementById('notifBtnTrigger');
            const menu = document.getElementById('notifPreviewMenu');
            const list = document.getElementById('notifPreviewList');
            if (!btn || !menu) return;
            let loaded = false;
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const isOpen = menu.classList.toggle('open');
                btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                if (isOpen && !loaded) {
                    loaded = true;
                    fetch(window.SITE_URL + '/api/notifications-preview.php').then(r => r.json()).then(data => {
                        if (!data.success) return;
                        if (data.notifications.length === 0) {
                            list.innerHTML = '<div style="padding:16px;text-align:center;font-size:0.8rem;color:var(--text-muted)">No notifications yet</div>';
                            return;
                        }
                        const esc = (s) => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
                        list.innerHTML = data.notifications.map(n => {
                            // Goes through the same ?read=ID&link=... route as the full
                            // Notifications page, so opening it here also marks it read
                            // (previously this linked straight to n.link and never did).
                            const readUrl = window.SITE_URL + '/notifications.php?read=' + n.id + '&link=' + encodeURIComponent(n.link);
                            return `
                            <a href="${esc(readUrl)}" style="display:block;padding:8px 10px;border-radius:8px;font-size:0.82rem;color:inherit;${n.is_read ? '' : 'background:rgba(139,92,246,0.08)'}">
                                <div style="font-weight:600;${n.is_read ? '' : 'color:var(--primary-bright)'}">${esc(n.title)}</div>
                                <div style="color:var(--text-muted);font-size:0.72rem;margin-top:2px">${esc(n.time_ago)}</div>
                            </a>`;
                        }).join('');
                    });
                }
            });
            document.addEventListener('click', () => menu.classList.remove('open'));
            menu.addEventListener('click', e => e.stopPropagation());
        })();
        </script>
      <?php endif; ?>

      <?php if ($currentUser): ?>
        <div class="dropdown" id="profileDropdown">
          <button class="profile-trigger" id="userMenuTrigger" aria-haspopup="true" aria-label="Open profile menu" aria-expanded="false">
            <img src="<?= htmlspecialchars(avatarUrl($currentUser['avatar'] ?? '')) ?>" alt="" class="profile-avatar">
          </button>
          <div class="dropdown-menu" id="userDropdown">
            <a class="dropdown-item" href="<?= SITE_URL ?>/dashboard.php">Dashboard</a>
            <a class="dropdown-item" href="<?= SITE_URL ?>/profile.php">Profile</a>
            <a class="dropdown-item" href="<?= SITE_URL ?>/bounty-profile.php">My Bounty Profile</a>
            <?php
              $__navHost = getBountyHost((int)$currentUser['id']);
              if ($__navHost): ?>
                <a class="dropdown-item" href="<?= SITE_URL ?>/host-profile.php">My Host Profile</a>
                <?php if ($__navHost['status'] === 'approved'): ?>
                  <a class="dropdown-item" href="<?= SITE_URL ?>/bounty-host-dashboard.php">Host Dashboard</a>
                  <a class="dropdown-item" href="<?= SITE_URL ?>/bounty-host.php">Create Hosted Bounty</a>
                <?php endif; ?>
            <?php else: ?>
                <a class="dropdown-item" href="<?= SITE_URL ?>/become-a-host.php">Become a Host</a>
            <?php endif; ?>
            <a class="dropdown-item" href="<?= SITE_URL ?>/bounty-submissions.php">My Submissions</a>
            <a class="dropdown-item" href="<?= SITE_URL ?>/bookmarks.php">Bookmarks</a>
            <a class="dropdown-item" href="<?= SITE_URL ?>/certificates.php">Certificates</a>
            <?php if (!isPremium()): ?>
              <a class="dropdown-item" href="<?= SITE_URL ?>/upgrade.php" style="color:var(--accent-bright)">Upgrade to Premium</a>
            <?php endif; ?>
            <a class="dropdown-item" href="<?= SITE_URL ?>/profile.php?tab=settings">Settings</a>
            <a class="dropdown-item" href="<?= SITE_URL ?>/logout.php">Logout</a>
          </div>
        </div>
      <?php else: ?>
        <a href="<?= SITE_URL ?>/login.php" class="btn btn-ghost btn-sm">Log in</a>
        <a href="<?= SITE_URL ?>/register.php" class="btn btn-primary btn-sm">Start free</a>
      <?php endif; ?>

      <button class="hamburger" id="hamburger" aria-label="Open menu" aria-expanded="false">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>
</header>

<?php if ($flash = getFlash()): ?>
  <div class="flash-message flash-<?= htmlspecialchars($flash['type']) ?>" id="flashMsg">
    <span><?= htmlspecialchars($flash['message']) ?></span>
    <button onclick="this.parentElement.remove()" aria-label="Dismiss">✕</button>
  </div>
<?php endif; ?>

<main class="site-main">
