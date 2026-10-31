<?php
require_once __DIR__ . '/includes/config.php';
$pageTitle = 'About Web3edu';

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header" style="text-align:center">
    <div class="container" data-reveal>
        <div class="section-label" style="margin:0 auto 20px">Our Mission</div>
        <h1 class="page-header-title">Building the Next Generation<br>of <span class="gradient-text">Web3 Builders</span></h1>
        <p style="color:var(--text-muted);max-width:580px;margin:0 auto;font-size:1rem;line-height:1.7">
            Web3edu was founded with a simple belief: everyone should have access to quality blockchain education, regardless of their technical background.
        </p>
    </div>
</div>

<div class="section-sm">
    <div class="container">
        <!-- Story -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:64px;align-items:center;margin-bottom:80px" class="about-grid">
            <div data-reveal>
                <div class="section-label">Our Story</div>
                <h2 style="font-size:2rem;font-weight:800;margin-bottom:20px">Why We Built Web3edu</h2>
                <p style="color:var(--text-muted);line-height:1.8;margin-bottom:16px">
                    When we first entered the Web3 space, we were overwhelmed — scattered YouTube videos, confusing whitepapers, and predatory "courses" that promised riches instead of knowledge.
                </p>
                <p style="color:var(--text-muted);line-height:1.8;margin-bottom:16px">
                    We built Web3edu to be the resource we wish we had: structured, honest, practical, and affordable. Our curriculum takes you from "what is blockchain?" to deploying smart contracts and farming real yields.
                </p>
               
            </div>
            <div data-reveal>
                <div style="background:linear-gradient(135deg,rgba(139,92,246,0.06),rgba(168,85,247,0.06));border:1px solid var(--border);border-radius:var(--radius-lg);padding:40px">
                    <h3 style="font-size:1.2rem;font-weight:700;margin-bottom:24px;color:var(--primary-bright)">Our Values</h3>
                    <?php
                    $values = [
                        ['<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>','Clarity First','No jargon without explanation. We make complex ideas accessible.'],
                        ['<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>','Honest Education','We teach risks alongside opportunities. No get-rich-quick promises.'],
                        ['<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>','Always Current','Web3 moves fast. Our content stays up-to-date with the ecosystem.'],
                        ['<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>','Accessible','Premium quality at a price that respects every learner.'],
                    ];
                    foreach ($values as [$icon, $title, $desc]):
                    ?>
                    <div style="display:flex;gap:14px;margin-bottom:20px">
                        <span style="font-size:1.4rem;flex-shrink:0"><?= $icon ?></span>
                        <div>
                            <div style="font-weight:600;margin-bottom:4px"><?= $title ?></div>
                            <div style="color:var(--text-muted);font-size:0.85rem"><?= $desc ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- CTA -->
        <div style="text-align:center;padding:60px;background:linear-gradient(135deg,rgba(168,85,247,0.08),rgba(139,92,246,0.05));border:1px solid rgba(168,85,247,0.2);border-radius:var(--radius-lg)" data-reveal>
            <h2 style="font-size:1.8rem;font-weight:800;margin-bottom:12px">Join the Community</h2>
            <p style="color:var(--text-muted);margin-bottom:28px">Start learning for free. Upgrade when you're ready for premium content.</p>
            <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
                <?php if (!isLoggedIn()): ?>
                    <a href="<?= SITE_URL ?>/register.php" class="btn-primary btn-lg"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg> Start for Free</a>
                <?php endif; ?>
                <a href="<?= SITE_URL ?>/learn.php" class="btn-ghost btn-lg">Browse Lessons</a>
            </div>
        </div>
    </div>
</div>

<style>
@media (max-width: 899px) {
    .about-grid       { grid-template-columns: 1fr !important; gap: 40px !important; }
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
