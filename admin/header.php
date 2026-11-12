<?php
require_once __DIR__ . '/auth.php';
requireAdmin();
$admin = getCurrentAdmin();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($adminTitle) ? sanitize($adminTitle) . ' — Web3edu NEAR Admin' : 'Web3edu NEAR Admin' ?></title>
    <script>
        (function(){
            var t = localStorage.getItem('web3edu_theme');
            var p = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', t || p);
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/admin.css">
    <style>
        body { overflow-x: hidden; }
        .admin-topbar {
            height: 58px;
            background: var(--bg-card);
            border-bottom: 1.5px solid var(--border);
            display: flex; align-items: center;
            padding: 0 24px;
            justify-content: space-between;
            position: sticky; top: 0; z-index: 100;
            backdrop-filter: blur(16px);
        }
        .admin-topbar-brand {
            font-family: var(--font-head); font-weight: 900;
            font-size: 1.1rem; display: flex; align-items: center; gap: 10px;
        }
        .admin-topbar-brand img {
            width: 30px; height: 30px; border-radius: 8px; object-fit: contain;
            filter: drop-shadow(0 0 6px var(--accent-soft));
        }
        .admin-topbar-actions { display: flex; align-items: center; gap: 10px; }
        .admin-badge {
            padding: 3px 10px; border-radius: 20px;
            background: var(--accent-soft); border: 1px solid var(--accent-soft);
            color: var(--accent-bright); font-size: 0.72rem; font-weight: 700;
            letter-spacing: 0.06em; text-transform: uppercase;
        }
    </style>
</head>
<body>

<!-- Admin Topbar -->
<div class="admin-topbar">
    <div class="admin-topbar-brand">
        <img src="<?= SITE_URL ?>/assets/images/logo.png"
             alt="Web3edu"
             onerror="this.style.display='none'">
        <span>Web3<span style="background:linear-gradient(135deg,var(--accent-bright),var(--accent));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text">edu</span></span>
        <span class="admin-badge">Admin</span>
    </div>
    <div class="admin-topbar-actions">
        <?php
try {
    $__adminUnread = (int)getDB()->query("SELECT COUNT(*) FROM admin_notifications WHERE is_read = 0")->fetchColumn();
} catch (PDOException $e) {
    if ($e->getCode() === '42S02') {
        $__adminUnread = 0;
    } else {
        error_log("Database error in admin/header.php: " . $e->getMessage());
        $__adminUnread = 0;
    }
}
?>
        <a href="<?= SITE_URL ?>/admin/notification-center.php" class="btn-ghost btn-sm" style="position:relative;display:inline-flex;align-items:center;gap:6px" title="Notification Center">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
            <?php if ($__adminUnread > 0): ?><span style="background:#ef4444;color:#fff;border-radius:20px;padding:0 6px;font-size:0.68rem;font-weight:700;line-height:1.5"><?= $__adminUnread > 99 ? '99+' : $__adminUnread ?></span><?php endif; ?>
        </a>
        <span style="color:var(--text-muted);font-size:0.82rem">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> <?= sanitize($admin['name'] ?? $admin['email']) ?>
        </span>
        <button class="theme-toggle" id="themeToggle" aria-label="Toggle theme" title="Toggle dark/light mode">
            <span class="theme-toggle-icon dark"  aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></span>
            <span class="theme-toggle-icon light" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>️</span>
        </button>
        <a href="<?= SITE_URL ?>/index.php" class="btn-ghost btn-sm" target="_blank">View Site ↗</a>
        <a href="<?= SITE_URL ?>/admin/logout.php" class="btn-danger btn-sm">Logout</a>
    </div>
</div>

<!-- Admin Layout -->
<div class="admin-layout" style="position:relative;z-index:1">

<!-- Sidebar -->
<nav class="admin-sidebar" aria-label="Admin navigation">
    <div style="padding:0 4px" class="admin-sidebar-inner">
        <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);padding:10px 10px 6px">Main</div>
        <a href="<?= SITE_URL ?>/admin/" class="admin-nav-item <?= basename($_SERVER['PHP_SELF'])==='index.php'?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg> <span>Dashboard</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/analytics.php" class="admin-nav-item <?= basename($_SERVER['PHP_SELF'])==='analytics.php'?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M3 3v18h18"/><path d="M18.7 8 13 13.7l-4-4L3 16"/></svg> <span>Analytics</span>
        </a>

        <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);padding:14px 10px 6px">Learning Content</div>
        <a href="<?= SITE_URL ?>/admin/lessons.php" class="admin-nav-item <?= strpos(basename($_SERVER['PHP_SELF']),'lesson') !==false?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/></svg> <span>Lessons</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/categories.php" class="admin-nav-item <?= strpos(basename($_SERVER['PHP_SELF']),'categories')!==false?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><polygon points="5 3 19 12 5 21 5 3"/></svg> <span>Categories</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/guides.php" class="admin-nav-item <?= strpos(basename($_SERVER['PHP_SELF']),'guides')!==false?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg> <span>Guides</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/career-paths.php" class="admin-nav-item <?= strpos(basename($_SERVER['PHP_SELF']),'career-paths')!==false?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M3 3v18h18"/><path d="M18.7 8 12 15.5 8.5 12 4 16.5"/></svg> <span>Career Paths</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/opportunities.php" class="admin-nav-item <?= basename($_SERVER['PHP_SELF'])==='opportunities.php'?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg> <span>Opportunities</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/xp-logs.php" class="admin-nav-item <?= basename($_SERVER['PHP_SELF'])==='xp-logs.php'?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg> <span>XP Logs</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/streak-management.php" class="admin-nav-item <?= basename($_SERVER['PHP_SELF'])==='streak-management.php'?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg> <span>Streaks</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/milestones.php" class="admin-nav-item <?= basename($_SERVER['PHP_SELF'])==='milestones.php'?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg> <span>Milestones</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/comeback-management.php" class="admin-nav-item <?= basename($_SERVER['PHP_SELF'])==='comeback-management.php'?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg> <span>Comeback</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/projects.php" class="admin-nav-item <?= in_array(basename($_SERVER['PHP_SELF']),['projects.php','guide-projects.php'])?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg> <span>Projects</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/submissions.php" class="admin-nav-item <?= basename($_SERVER['PHP_SELF'])==='submissions.php'?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg> <span>Submissions</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/resources.php" class="admin-nav-item <?= basename($_SERVER['PHP_SELF'])==='resources.php'?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg> <span>Resources</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/gamification.php?tab=quizzes" class="admin-nav-item <?= (strpos(basename($_SERVER['PHP_SELF']),'gamification')!==false && ($_GET['tab']??'')==='quizzes')?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M9 18h6"/><path d="M10 22h4"/><path d="M12 2a7 7 0 0 0-7 7c0 3 2 5 3 6.5a3 3 0 0 1 1 2.5h6a3 3 0 0 1 1-2.5c1-1.5 3-3.5 3-6.5a7 7 0 0 0-7-7Z"/></svg> <span>Quizzes</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/gamification.php?tab=badges" class="admin-nav-item <?= (strpos(basename($_SERVER['PHP_SELF']),'gamification')!==false && ($_GET['tab']??'badges')==='badges')?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="8" r="6"/><path d="M15.5 13.5 17 22l-5-3-5 3 1.5-8.5"/></svg> <span>Badges</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/gamification.php?tab=levels" class="admin-nav-item <?= (strpos(basename($_SERVER['PHP_SELF']),'gamification')!==false && ($_GET['tab']??'')==='levels')?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><polygon points="12 2 15 8.5 22 9.5 17 14.5 18.5 21.5 12 18 5.5 21.5 7 14.5 2 9.5 9 8.5 12 2"/></svg> <span>Levels</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/certificate-templates.php" class="admin-nav-item <?= strpos(basename($_SERVER['PHP_SELF']),'certificate')!==false?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg> <span>Certificates</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/bounties.php" class="admin-nav-item <?= strpos(basename($_SERVER['PHP_SELF']),'bount')!==false?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg> <span>Bounties</span>
        </a>

        <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);padding:14px 10px 6px">Site Content</div>
        <a href="<?= SITE_URL ?>/admin/airdrops.php" class="admin-nav-item <?= strpos(basename($_SERVER['PHP_SELF']),'airdrop')!==false?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M17 17 A 7 7 0 0 0 5 17"/><line x1="11" y1="17" x2="9" y2="22"/><line x1="13" y1="17" x2="15" y2="22"/><path d="M11 17V5a1 1 0 0 1 2 0v12"/></svg> <span>Airdrops</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/blog.php" class="admin-nav-item <?= strpos(basename($_SERVER['PHP_SELF']),'blog')!==false?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 0-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/><path d="M18 14h-8"/><path d="M15 18h-5"/><path d="M10 6h8v4h-8V6Z"/></svg> <span>Blog</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/hero-headlines.php" class="admin-nav-item <?= strpos(basename($_SERVER['PHP_SELF']),'hero-headlines')!==false?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg> <span>Hero Headlines</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/crypto-carousel.php" class="admin-nav-item <?= strpos(basename($_SERVER['PHP_SELF']),'crypto-carousel')!==false?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg> <span>Crypto Carousel</span>
        </a>

        <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);padding:14px 10px 6px">Users &amp; Monetization</div>
        <a href="<?= SITE_URL ?>/admin/users.php" class="admin-nav-item <?= strpos(basename($_SERVER['PHP_SELF']),'users')!==false?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg> <span>Users</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/waitlist-rewards.php" class="admin-nav-item <?= strpos(basename($_SERVER['PHP_SELF']),'waitlist-rewards')!==false?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M20 12v10H4V12"/><path d="M2 7h20v5H2z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg> <span>Waitlist Rewards</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/premium-plans.php" class="admin-nav-item <?= strpos(basename($_SERVER['PHP_SELF']),'premium-plans')!==false?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg> <span>Premium Plans</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/subscriptions.php" class="admin-nav-item <?= strpos(basename($_SERVER['PHP_SELF']),'subscriptions')!==false?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M6 3h12l4 6-10 13L2 9Z"/><path d="M11 3 8 9l4 13 4-13-3-6"/><path d="M2 9h20"/></svg> <span>Subscriptions</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/payments.php" class="admin-nav-item <?= strpos(basename($_SERVER['PHP_SELF']),'payment')!==false?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg> <span>Payments</span>
        </a>

        <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);padding:14px 10px 6px">Communications</div>
        <a href="<?= SITE_URL ?>/admin/notifications.php" class="admin-nav-item <?= basename($_SERVER['PHP_SELF'])==='notifications.php'?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg> <span>Notifications</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/notification-center.php" class="admin-nav-item <?= strpos(basename($_SERVER['PHP_SELF']),'notification-center')!==false?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg> <span>Notification Center</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/announcements.php" class="admin-nav-item <?= strpos(basename($_SERVER['PHP_SELF']),'announcements')!==false?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg> <span>Announcements</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/email-templates.php" class="admin-nav-item <?= strpos(basename($_SERVER['PHP_SELF']),'email-templates')!==false?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg> <span>Email Templates</span>
        </a>

        <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);padding:14px 10px 6px">System</div>
        <a href="<?= SITE_URL ?>/admin/ai_management.php" class="admin-nav-item <?= strpos(basename($_SERVER['PHP_SELF']),'ai_management')!==false?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg> <span>AI Management</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/settings.php" class="admin-nav-item <?= strpos(basename($_SERVER['PHP_SELF']),'settings')!==false?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg> <span>Settings</span>
        </a>
        <a href="<?= SITE_URL ?>/admin/near-issuance.php" class="admin-nav-item <?= strpos(basename($_SERVER['PHP_SELF']),'near-issuance')!==false?'active':'' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg> <span>NEAR Issuance</span>
        </a>

        <?php $_maint_active = (getSetting('maintenance_mode', '0') === '1'); ?>
        <a href="<?= SITE_URL ?>/admin/maintenance.php"
           class="admin-nav-item <?= strpos(basename($_SERVER['PHP_SELF']),'maintenance')!==false?'active':'' ?>"
           style="position:relative;display:flex;align-items:center;gap:8px">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
            <span>Maintenance</span>
            <?php if ($_maint_active): ?>
            <span title="Maintenance mode is ON" style="margin-left:auto;width:7px;height:7px;border-radius:50%;background:#ef4444;flex-shrink:0;animation:blink-nav 1.4s step-start infinite"></span>
            <?php endif; ?>
        </a>
        </a>
        <?php if ($_maint_active): ?><style>@keyframes blink-nav{0%,100%{opacity:1}50%{opacity:0.15}}</style><?php endif; ?>

        <div style="margin:20px 8px 0;padding-top:16px;border-top:1px solid var(--border)">
            <a href="<?= SITE_URL ?>/index.php" target="_blank"
               style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:9px;font-size:0.8rem;color:var(--text-muted);transition:all 0.2s"
               onmouseover="this.style.color='var(--accent-bright)'" onmouseout="this.style.color='var(--text-muted)'">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg> <span>View Site</span>
            </a>
        </div>
    </div>
</nav>

<!-- Main content wrapper -->
<div style="min-width:0">
