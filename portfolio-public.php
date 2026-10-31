<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/projects.php';

$slug = trim($_GET['u'] ?? '');
$owner = $slug ? getPublicPortfolioBySlug($slug) : null;

if (!$owner) {
    http_response_code(404);
    $pageTitle = 'Portfolio Not Found';
    require_once __DIR__ . '/includes/header.php';
    echo '<section class="container" style="text-align:center;padding:80px 20px">
            <div style="font-size:2.5rem;margin-bottom:12px">🔍</div>
            <h1>Portfolio not found</h1>
            <p style="color:var(--text-muted);margin-top:8px">This portfolio is private or the link is incorrect.</p>
          </section>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$userId = (int)$owner['user_id'];
$portfolio = getUserPortfolio($userId);
$completedPaths = getUserCompletedCareerPaths($userId);
$certificates = getUserCertificatesForPortfolio($userId);

// Aggregate all "Technologies Used" tags across approved projects into a
// single Skills summary, per the brief ("Skills" alongside "Technologies Used").
$allSkills = [];
foreach ($portfolio as $p) {
    foreach (array_filter(array_map('trim', explode(',', $p['technologies'] ?? ''))) as $t) {
        $allSkills[$t] = true;
    }
}
$allSkills = array_keys($allSkills);

$pageTitle = sanitize($owner['username']) . "'s Portfolio";
require_once __DIR__ . '/includes/header.php';
?>

<section class="learn-hero container">
  <div class="section-label" style="text-align:center">Public Portfolio</div>
  <h1 style="text-align:center">👤 <?= sanitize($owner['username']) ?></h1>
  <p style="text-align:center;color:var(--text-muted)">Built with Web3Edu</p>
</section>

<section class="container" style="padding-bottom:60px;max-width:960px">

<?php if (!empty($allSkills)): ?>
<div class="card" style="padding:20px;margin-bottom:24px">
    <h3 style="font-size:0.9rem;font-weight:700;margin-bottom:10px">🧠 Skills</h3>
    <div style="display:flex;flex-wrap:wrap;gap:8px">
        <?php foreach ($allSkills as $skill): ?>
        <span style="font-size:0.76rem;padding:4px 12px;border-radius:20px;background:rgba(139,92,246,0.12);color:var(--primary-bright);font-weight:600"><?= sanitize($skill) ?></span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($completedPaths) || !empty($certificates)): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:24px">
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
    <p style="color:var(--text-muted)">No approved projects yet.</p>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:18px">
<?php foreach ($portfolio as $p): ?>
<div class="card" style="padding:20px">
    <span style="display:inline-block;padding:2px 9px;border-radius:20px;font-size:0.7rem;font-weight:700;background:rgba(139,92,246,0.12);color:var(--primary-bright);margin-bottom:6px"><?= ucfirst($p['project_type']) ?></span>
    <h3 style="font-size:1rem;font-weight:800;margin:0 0 4px"><?= sanitize($p['project_title']) ?></h3>
    <?php if ($p['career_path_name']): ?>
    <p style="font-size:0.78rem;color:var(--text-muted);margin-bottom:8px"><?= sanitize($p['career_path_icon'] ?: '⭐') ?> <?= sanitize($p['career_path_name']) ?><?= $p['guide_title'] ? ' · ' . sanitize($p['guide_title']) : '' ?></p>
    <?php endif; ?>
    <?php if ($p['technologies']): ?>
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px">
        <?php foreach (array_filter(array_map('trim', explode(',', $p['technologies']))) as $tech): ?>
        <span style="font-size:0.7rem;padding:2px 8px;border-radius:20px;background:var(--bg-card2);color:var(--text-muted);border:1px solid var(--border)"><?= sanitize($tech) ?></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
        <?php if ($p['github_url']): ?><a href="<?= sanitize($p['github_url']) ?>" target="_blank" rel="noopener" class="btn-ghost btn-sm">GitHub</a><?php endif; ?>
        <?php if ($p['live_demo_url']): ?><a href="<?= sanitize($p['live_demo_url']) ?>" target="_blank" rel="noopener" class="btn-primary btn-sm">Live Demo</a><?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
