<?php
// ============================================================
// maintenance.php  —  Shown to users when maintenance mode is ON
// ============================================================
// Direct access to this page is intentional (it IS the wall).
// The check_maintenance.php bootstrap redirects here for blocked users.
// We deliberately do NOT include check_maintenance.php on this file
// to avoid redirect loops.
require_once __DIR__ . '/includes/config.php';

// Safety: if maintenance is actually OFF now, redirect home.
// (covers the case where someone bookmarked this URL)
if (getSetting('maintenance_mode', '0') === '0') {
    header('Location: ' . SITE_URL . '/index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Under Maintenance — <?= SITE_NAME ?></title>
    <meta name="robots" content="noindex,nofollow">

    <script>
        (function(){
            var t = localStorage.getItem('web3edu_theme');
            var p = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', t || p);
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Exo+2:wght@300;400;700;900&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/main.css">

    <style>
        /* ── Page reset ─────────────────────────────────────── */
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        html,body{height:100%}
        body{
            min-height:100vh;
            display:flex;align-items:center;justify-content:center;
            padding:24px;
            background:var(--bg);
            font-family:var(--font-body,Outfit,sans-serif);
            overflow:hidden;
            position:relative;
        }

        /* ── Animated background glow orbs ──────────────────── */
        .orb{
            position:fixed;border-radius:50%;filter:blur(80px);
            opacity:0.18;pointer-events:none;z-index:0;
            animation:float 8s ease-in-out infinite;
        }
        .orb-1{width:480px;height:480px;background:radial-gradient(circle,#0B7958,transparent);top:-120px;left:-120px;animation-delay:0s}
        .orb-2{width:360px;height:360px;background:radial-gradient(circle,#ec4899,transparent);bottom:-80px;right:-80px;animation-delay:-4s}
        .orb-3{width:280px;height:280px;background:radial-gradient(circle,#06b6d4,transparent);top:50%;left:50%;transform:translate(-50%,-50%);animation-delay:-2s}

        @keyframes float{
            0%,100%{transform:translateY(0) scale(1)}
            50%{transform:translateY(-24px) scale(1.04)}
        }

        /* ── Card ────────────────────────────────────────────── */
        .maint-card{
            position:relative;z-index:1;
            max-width:520px;width:100%;
            background:var(--bg-card,rgba(16,10,30,0.92));
            border:1.5px solid var(--border,rgba(139,92,246,0.2));
            border-radius:24px;
            padding:52px 48px;
            text-align:center;
            box-shadow:0 32px 80px rgba(0,0,0,0.45),0 0 0 1px rgba(139,92,246,0.08) inset;
            backdrop-filter:blur(24px);
        }
        @media(max-width:540px){.maint-card{padding:36px 24px;border-radius:18px}}

        /* ── Icon badge ─────────────────────────────────────── */
        .maint-icon{
            width:84px;height:84px;border-radius:22px;
            background:linear-gradient(135deg,rgba(139,92,246,0.18),rgba(236,72,153,0.12));
            border:1.5px solid rgba(139,92,246,0.3);
            display:flex;align-items:center;justify-content:center;
            margin:0 auto 28px;
            animation:pulse-glow 3s ease-in-out infinite;
        }
        @keyframes pulse-glow{
            0%,100%{box-shadow:0 0 0 0 rgba(139,92,246,0.25)}
            50%{box-shadow:0 0 0 14px rgba(139,92,246,0)}
        }

        /* ── Brand ───────────────────────────────────────────── */
        .maint-brand{
            font-family:'Exo 2',sans-serif;font-weight:900;font-size:1.1rem;
            letter-spacing:0.04em;color:var(--text);
            margin-bottom:28px;display:flex;align-items:center;justify-content:center;gap:6px;
        }
        .maint-brand span{
            background:linear-gradient(135deg,#a78bfa,#ec4899);
            -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;
        }

        /* ── Heading ────────────────────────────────────────── */
        .maint-heading{
            font-family:'Exo 2',sans-serif;font-weight:900;
            font-size:clamp(1.7rem,5vw,2.2rem);
            line-height:1.15;
            color:var(--text,#f1f0f5);
            margin-bottom:12px;
        }
        .maint-heading span{
            background:linear-gradient(135deg,#a78bfa,#ec4899);
            -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;
        }

        /* ── Sub-text ───────────────────────────────────────── */
        .maint-sub{
            font-size:0.98rem;color:var(--text-muted,#7b7491);
            line-height:1.7;margin-bottom:36px;
        }

        /* ── Progress bar (purely decorative, shows "busy") ── */
        .maint-progress-track{
            height:5px;background:rgba(139,92,246,0.12);
            border-radius:99px;overflow:hidden;margin-bottom:36px;
        }
        .maint-progress-bar{
            height:100%;border-radius:99px;
            background:linear-gradient(90deg,#0B7958,#ec4899,#0B7958);
            background-size:200%;
            animation:shimmer 2.2s linear infinite;
        }
        @keyframes shimmer{from{background-position:200% 0}to{background-position:-200% 0}}

        /* ── Divider ────────────────────────────────────────── */
        .maint-divider{
            height:1px;background:var(--border,rgba(139,92,246,0.15));
            margin:0 -4px 28px;
        }

        /* ── Links row ──────────────────────────────────────── */
        .maint-links{
            display:flex;align-items:center;justify-content:center;gap:16px;flex-wrap:wrap;
        }
        .maint-link{
            display:inline-flex;align-items:center;gap:7px;
            padding:9px 20px;border-radius:10px;font-size:0.85rem;font-weight:600;
            text-decoration:none;transition:all 0.2s;border:1.5px solid transparent;
        }
        .maint-link-primary{
            background:linear-gradient(135deg,rgba(139,92,246,0.18),rgba(236,72,153,0.12));
            border-color:rgba(139,92,246,0.35);color:#c4b5fd;
        }
        .maint-link-primary:hover{background:linear-gradient(135deg,rgba(139,92,246,0.32),rgba(236,72,153,0.2));border-color:rgba(139,92,246,0.6);color:#ede9fe}
        .maint-link-ghost{
            background:transparent;border-color:var(--border,rgba(255,255,255,0.1));
            color:var(--text-muted,#7b7491);
        }
        .maint-link-ghost:hover{border-color:rgba(139,92,246,0.35);color:#c4b5fd}

        /* ── Footer note ────────────────────────────────────── */
        .maint-footer{
            margin-top:32px;font-size:0.75rem;color:var(--text-muted,#7b7491);
            opacity:0.65;
        }
    </style>
</head>
<body>

<!-- Animated background orbs -->
<div class="orb orb-1" aria-hidden="true"></div>
<div class="orb orb-2" aria-hidden="true"></div>
<div class="orb orb-3" aria-hidden="true"></div>

<main class="maint-card" role="main" aria-label="Maintenance notice">

    <!-- Brand -->
    <div class="maint-brand">
        <?= SITE_NAME ?>
    </div>

    <!-- Icon -->
    <div class="maint-icon" aria-hidden="true">
        <svg xmlns="http://www.w3.org/2000/svg" width="38" height="38" viewBox="0 0 24 24"
             fill="none" stroke="url(#mg)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <defs>
                <linearGradient id="mg" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%"   stop-color="#a78bfa"/>
                    <stop offset="100%" stop-color="#ec4899"/>
                </linearGradient>
            </defs>
            <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
        </svg>
    </div>

    <!-- Heading -->
    <h1 class="maint-heading">
        We're <span>Under Maintenance</span>
    </h1>

    <!-- Sub-text -->
    <p class="maint-sub">
        <?= SITE_NAME ?> is currently undergoing scheduled maintenance.<br>
        We're working hard to upgrade the platform for you.<br>
        Please check back soon — we won't be long!
    </p>

    <!-- Animated progress bar -->
    <div class="maint-progress-track" role="progressbar" aria-label="Work in progress">
        <div class="maint-progress-bar"></div>
    </div>

    <div class="maint-divider"></div>

    <!-- Contact / social links -->
    <div class="maint-links">
        <a href="mailto:web3eduofficial@gmail.com" class="maint-link maint-link-primary"
           aria-label="Email support">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect width="20" height="16" x="2" y="4" rx="2"/>
                <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>
            </svg>
            Contact Support
        </a>

        <a href="https://x.com/web3eduofficial" target="_blank" rel="noopener noreferrer"
           class="maint-link maint-link-ghost" aria-label="Follow on X (Twitter)">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"
                 fill="currentColor">
                <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.746l7.73-8.835L1.254 2.25H8.08l4.259 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
            </svg>
            Follow on X
        </a>
    </div>

    <p class="maint-footer">
        &copy; <?= date('Y') ?> <?= SITE_NAME ?> &mdash; All rights reserved.
    </p>

</main>

</body>
</html>
