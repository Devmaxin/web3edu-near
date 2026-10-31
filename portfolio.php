<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/projects.php';

if (!isLoggedIn()) {
    $_SESSION['redirect_after_login'] = SITE_URL . '/portfolio.php';
    setFlash('info', 'Sign in to view your portfolio.');
    header('Location: ' . SITE_URL . '/login.php'); exit;
}
requireProfileComplete();

$userId = (int)$_SESSION['user_id'];
$portfolio = getUserPortfolio($userId);
$settings = getPortfolioSettings($userId);
$completedPaths = getUserCompletedCareerPaths($userId);
$certificates = getUserCertificatesForPortfolio($userId);

$pageTitle = 'My Portfolio';
$extraCss = [SITE_URL . '/assets/css/career-paths.css'];
require_once __DIR__ . '/includes/header.php';
?>

<section class="learn-hero container">
  <div class="section-label" style="text-align:center">Your work</div>
  <h1 style="text-align:center">🏆 My Portfolio</h1>
  <p style="text-align:center;max-width:560px;margin:0 auto;color:var(--text-muted)">
    Every approved project you've built, in one place — ready to share.
  </p>
</section>

<section class="container" style="padding-bottom:60px">

<div class="card" style="padding:18px 20px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px">
  <div>
    <div style="font-weight:700;font-size:0.9rem;margin-bottom:2px">
      <?= $settings['is_public'] ? '🌎 Public' : '🔒 Private' ?>
    </div>
    <div style="font-size:0.78rem;color:var(--text-muted)">
      <?php if ($settings['is_public']): ?>
        Anyone with the link can view your portfolio — no account needed.
      <?php else: ?>
        Only you can see this. Make it public to share with employers.
      <?php endif; ?>
    </div>
  </div>
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <?php if ($settings['is_public'] && $settings['slug']): ?>
    <input type="text" readonly value="<?= SITE_URL ?>/portfolio-public.php?u=<?= sanitize($settings['slug']) ?>" onclick="this.select()"
           style="font-size:0.78rem;padding:8px 10px;border-radius:var(--radius);border:1px solid var(--border);background:var(--bg-card2);color:var(--text-muted);width:260px">
    <button type="button" class="btn-ghost btn-sm" onclick="navigator.clipboard.writeText('<?= SITE_URL ?>/portfolio-public.php?u=<?= sanitize($settings['slug']) ?>');this.textContent='Copied!'">Copy Link</button>
    <?php endif; ?>
    <button type="button" id="portfolioToggleBtn" class="btn-<?= $settings['is_public'] ? 'ghost' : 'primary' ?> btn-sm">
      <?= $settings['is_public'] ? 'Make Private' : 'Make Public' ?>
    </button>
  </div>
</div>

<script>
document.getElementById('portfolioToggleBtn').addEventListener('click', function () {
    const btn = this;
    btn.disabled = true;
    fetch(window.SITE_URL + '/api/portfolio-visibility.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'is_public=<?= $settings['is_public'] ? '0' : '1' ?>',
    }).then(r => r.json()).then(data => { if (data.success) location.reload(); else btn.disabled = false; });
});
</script>

<?php if (!empty($completedPaths) || !empty($certificates)): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:28px">
    <?php if (!empty($completedPaths)): ?>
    <div class="card" style="padding:20px">
        <h3 style="font-size:0.9rem;font-weight:700;margin-bottom:12px">🏁 Career Paths Completed</h3>
        <?php foreach ($completedPaths as $cp): ?>
        <div style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid var(--border)">
            <span><?= sanitize($cp['icon'] ?: '⭐') ?></span>
            <span style="font-size:0.85rem;font-weight:600;flex:1"><?= sanitize($cp['name']) ?></span>
            <span style="font-size:0.72rem;color:var(--text-muted)"><?= date('M Y', strtotime($cp['completed_at'])) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if (!empty($certificates)): ?>
    <div class="card" style="padding:20px">
        <h3 style="font-size:0.9rem;font-weight:700;margin-bottom:12px">🎓 Certificates</h3>
        <?php foreach ($certificates as $c): ?>
        <div style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid var(--border)">
            <span><?= sanitize($c['type_icon'] ?: '🎓') ?></span>
            <span style="font-size:0.85rem;font-weight:600;flex:1"><?= sanitize($c['achievement_title'] ?: $c['type_label']) ?></span>
            <span style="font-size:0.72rem;color:var(--text-muted)"><?= date('M Y', strtotime($c['issued_at'])) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<h2 style="font-size:1rem;font-weight:700;margin-bottom:14px">Projects</h2>
<?php if (empty($portfolio)): ?>
    <div class="learn-empty" style="text-align:center;padding:60px 20px">
        <div style="font-size:2.5rem;margin-bottom:12px">🧰</div>
        <h3>No approved projects yet</h3>
        <p style="color:var(--text-muted);margin-top:8px">Submit a project from any guide's sidebar — once it's approved, it shows up here automatically.</p>
        <a href="<?= SITE_URL ?>/learn.php" class="btn-primary btn-sm" style="margin-top:16px;display:inline-flex">Go to Learn →</a>
    </div>
<?php else: ?>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:18px">
<?php foreach ($portfolio as $p): ?>
<div class="card" style="padding:20px">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:10px">
        <div>
            <span style="display:inline-block;padding:2px 9px;border-radius:20px;font-size:0.7rem;font-weight:700;background:rgba(139,92,246,0.12);color:var(--primary-bright);margin-bottom:6px"><?= ucfirst($p['project_type']) ?></span>
            <h3 style="font-size:1rem;font-weight:800;margin:0"><?= sanitize($p['project_title']) ?></h3>
        </div>
        <span style="font-size:1.3rem" title="Approved">✅</span>
    </div>

    <?php if ($p['career_path_name']): ?>
    <p style="font-size:0.78rem;color:var(--text-muted);margin-bottom:4px"><?= sanitize($p['career_path_icon'] ?: '⭐') ?> <?= sanitize($p['career_path_name']) ?><?= $p['guide_title'] ? ' · ' . sanitize($p['guide_title']) : '' ?></p>
    <?php elseif ($p['guide_title']): ?>
    <p style="font-size:0.78rem;color:var(--text-muted);margin-bottom:4px"><?= sanitize($p['guide_title']) ?></p>
    <?php endif; ?>

    <?php if ($p['technologies']): ?>
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin:10px 0">
        <?php foreach (array_filter(array_map('trim', explode(',', $p['technologies']))) as $tech): ?>
        <span style="font-size:0.7rem;padding:2px 8px;border-radius:20px;background:var(--bg-card2);color:var(--text-muted);border:1px solid var(--border)"><?= sanitize($tech) ?></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <p style="font-size:0.74rem;color:var(--text-muted);margin-bottom:12px">Completed <?= date('M j, Y', strtotime($p['reviewed_at'])) ?></p>

    <div style="display:flex;gap:10px;flex-wrap:wrap">
        <?php if ($p['github_url']): ?><a href="<?= sanitize($p['github_url']) ?>" target="_blank" rel="noopener" class="btn-ghost btn-sm">GitHub</a><?php endif; ?>
        <?php if ($p['live_demo_url']): ?><a href="<?= sanitize($p['live_demo_url']) ?>" target="_blank" rel="noopener" class="btn-primary btn-sm">Live Demo</a><?php endif; ?>
        <?php if ($p['video_demo_url']): ?><a href="<?= sanitize($p['video_demo_url']) ?>" target="_blank" rel="noopener" class="btn-ghost btn-sm">Video</a><?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
