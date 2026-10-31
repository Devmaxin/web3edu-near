<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/opportunities.php';

if (!isLoggedIn()) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    setFlash('info', 'Sign in to view this opportunity.');
    header('Location: ' . SITE_URL . '/login.php'); exit;
}
requireProfileComplete();

$id = intval($_GET['id'] ?? 0);
$opportunity = getOpportunity($id);
if (!$opportunity) {
    setFlash('error', 'That opportunity could not be found.');
    header('Location: ' . SITE_URL . '/opportunities.php'); exit;
}

$linkedPaths = getLinkedCareerPaths($id);
$pageTitle = $opportunity['title'];
$extraJs = [SITE_URL . '/assets/js/bookmark.js'];
require_once __DIR__ . '/includes/header.php';
?>

<section class="container" style="max-width:760px;padding-top:32px;padding-bottom:60px">

    <div style="margin-bottom:24px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px">
            <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:700;background:rgba(139,92,246,0.12);color:var(--primary-bright);margin-bottom:10px">
                <?= sanitize(OPPORTUNITY_CATEGORIES[$opportunity['category']] ?? $opportunity['category']) ?>
            </span>
            <button type="button" class="bookmark-btn <?= isBookmarked('opportunity', $id) ? 'active' : '' ?>"
                    data-type="opportunity" data-id="<?= $id ?>"
                    aria-pressed="<?= isBookmarked('opportunity', $id) ? 'true' : 'false' ?>"
                    title="Bookmark this opportunity"></button>
        </div>
        <h1 class="gradient-text" style="font-size:clamp(1.4rem,4vw,2rem);font-weight:800;margin-bottom:6px"><?= sanitize($opportunity['title']) ?></h1>
        <?php if ($opportunity['organization']): ?><p style="color:var(--text-muted);font-weight:600"><?= sanitize($opportunity['organization']) ?></p><?php endif; ?>

        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:12px;font-size:0.84rem;color:var(--text-muted)">
            <span>📍 <?= sanitize($opportunity['location'] ?: '—') ?> (<?= ucfirst($opportunity['work_mode']) ?>)</span>
            <span>📊 <?= sanitize($opportunity['difficulty']) ?></span>
            <?php if ($opportunity['deadline']): ?><span>⏰ Deadline: <?= date('M j, Y', strtotime($opportunity['deadline'])) ?></span><?php endif; ?>
            <?php if ($opportunity['reward']): ?><span>💰 <?= sanitize($opportunity['reward']) ?></span><?php endif; ?>
        </div>
    </div>

    <?php if ($opportunity['description']): ?>
    <div class="card" style="padding:20px;margin-bottom:16px">
        <h2 style="font-size:0.95rem;font-weight:700;margin-bottom:10px">Description</h2>
        <p style="font-size:0.88rem;line-height:1.7;color:var(--text-dim);white-space:pre-line"><?= sanitize($opportunity['description']) ?></p>
    </div>
    <?php endif; ?>

    <?php if ($opportunity['requirements']): ?>
    <div class="card" style="padding:20px;margin-bottom:16px">
        <h2 style="font-size:0.95rem;font-weight:700;margin-bottom:10px">✅ Requirements</h2>
        <p style="font-size:0.88rem;line-height:1.7;color:var(--text-dim);white-space:pre-line"><?= sanitize($opportunity['requirements']) ?></p>
    </div>
    <?php endif; ?>

    <?php if ($opportunity['tags']): ?>
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px">
        <?php foreach (array_filter(array_map('trim', explode(',', $opportunity['tags']))) as $tag): ?>
        <span style="font-size:0.76rem;padding:4px 12px;border-radius:20px;background:var(--bg-card2);color:var(--text-muted);border:1px solid var(--border)"><?= sanitize($tag) ?></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($linkedPaths)): ?>
    <div class="card" style="padding:18px;margin-bottom:20px">
        <h3 style="font-size:0.85rem;font-weight:700;margin-bottom:10px">Relevant to these Career Paths</h3>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php foreach ($linkedPaths as $lp): ?>
            <a href="<?= SITE_URL ?>/roadmap.php" class="btn-ghost btn-sm"><?= sanitize($lp['icon'] ?: '⭐') ?> <?= sanitize($lp['name']) ?></a>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <a href="<?= sanitize($opportunity['official_link']) ?>" target="_blank" rel="noopener nofollow" class="btn-primary" style="width:100%;justify-content:center;padding:14px">
        Apply / View Official Listing →
    </a>

    <div style="text-align:center;margin-top:20px">
        <a href="<?= SITE_URL ?>/opportunities.php" class="btn-ghost btn-sm">← Back to Opportunities</a>
    </div>

</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
