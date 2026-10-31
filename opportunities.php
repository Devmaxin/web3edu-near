<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/opportunities.php';

if (!isLoggedIn()) {
    $_SESSION['redirect_after_login'] = SITE_URL . '/opportunities.php';
    setFlash('info', 'Sign in to browse Opportunities.');
    header('Location: ' . SITE_URL . '/login.php'); exit;
}
requireProfileComplete();

$userId = (int)$_SESSION['user_id'];
$filters = [
    'category' => $_GET['category'] ?? '',
    'work_mode' => $_GET['work_mode'] ?? '',
    'q' => trim($_GET['q'] ?? ''),
];
$opportunities = getOpportunities($filters);

$pageTitle = 'Opportunities';
$extraJs = [SITE_URL . '/assets/js/bookmark.js'];
require_once __DIR__ . '/includes/header.php';
?>

<section class="learn-hero container">
  <div class="section-label" style="text-align:center">Opportunities Hub</div>
  <h1 style="text-align:center">🚀 Jobs, Grants, Hackathons & More</h1>
  <p style="text-align:center;color:var(--text-muted);max-width:560px;margin:0 auto">
    Real opportunities to put your Web3 skills to work.
  </p>
</section>

<section class="container" style="padding-bottom:60px">

  <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px">
    <input type="text" name="q" class="form-input" placeholder="Search opportunities…" value="<?= sanitize($filters['q']) ?>" style="flex:1;min-width:200px">
    <select name="work_mode" class="form-select" style="width:160px" onchange="this.form.submit()">
        <option value="">Any Work Mode</option>
        <option value="remote" <?= $filters['work_mode']==='remote'?'selected':'' ?>>Remote</option>
        <option value="hybrid" <?= $filters['work_mode']==='hybrid'?'selected':'' ?>>Hybrid</option>
        <option value="onsite" <?= $filters['work_mode']==='onsite'?'selected':'' ?>>Onsite</option>
    </select>
    <input type="hidden" name="category" value="<?= sanitize($filters['category']) ?>">
    <button class="btn-primary btn-sm" type="submit">Search</button>
  </form>

  <div style="display:flex;gap:8px;margin-bottom:24px;flex-wrap:wrap">
    <a href="?<?= http_build_query(['work_mode'=>$filters['work_mode'],'q'=>$filters['q']]) ?>" class="btn-<?= !$filters['category']?'primary':'ghost' ?> btn-sm">All</a>
    <?php foreach (OPPORTUNITY_CATEGORIES as $k => $label): ?>
    <a href="?<?= http_build_query(['category'=>$k,'work_mode'=>$filters['work_mode'],'q'=>$filters['q']]) ?>" class="btn-<?= $filters['category']===$k?'primary':'ghost' ?> btn-sm"><?= $label ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (empty($opportunities)): ?>
    <div class="learn-empty" style="text-align:center;padding:60px 20px">
        <div style="font-size:2.5rem;margin-bottom:12px">🔍</div>
        <h3>No opportunities match your filters</h3>
        <p style="color:var(--text-muted);margin-top:8px">Check back soon, or try a broader search.</p>
    </div>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px">
  <?php foreach ($opportunities as $o): ?>
  <div class="card" style="padding:20px;<?= $o['is_featured'] ? 'border-color:rgba(245,158,11,0.4)' : '' ?>">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:8px">
        <span style="display:inline-block;padding:2px 9px;border-radius:20px;font-size:0.7rem;font-weight:700;background:rgba(139,92,246,0.12);color:var(--primary-bright)"><?= sanitize(OPPORTUNITY_CATEGORIES[$o['category']] ?? $o['category']) ?></span>
        <div style="display:flex;align-items:center;gap:6px">
            <?php if ($o['is_featured']): ?><span title="Featured">⭐</span><?php endif; ?>
            <button type="button" class="bookmark-btn <?= isBookmarked('opportunity', (int)$o['id']) ? 'active' : '' ?>"
                    data-type="opportunity" data-id="<?= (int)$o['id'] ?>"
                    aria-pressed="<?= isBookmarked('opportunity', (int)$o['id']) ? 'true' : 'false' ?>"
                    title="Bookmark this opportunity"></button>
        </div>
    </div>
    <h3 style="font-size:1rem;font-weight:800;margin:0 0 4px"><?= sanitize($o['title']) ?></h3>
    <?php if ($o['organization']): ?><p style="font-size:0.8rem;color:var(--text-muted);margin-bottom:8px"><?= sanitize($o['organization']) ?></p><?php endif; ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;font-size:0.74rem;color:var(--text-muted);margin-bottom:10px">
        <span>📍 <?= sanitize($o['location'] ?: ucfirst($o['work_mode'])) ?></span>
        <?php if ($o['deadline']): ?><span>⏰ <?= date('M j', strtotime($o['deadline'])) ?></span><?php endif; ?>
        <?php if ($o['reward']): ?><span>💰 <?= sanitize($o['reward']) ?></span><?php endif; ?>
    </div>
    <?php if ($o['description']): ?><p style="font-size:0.82rem;color:var(--text-dim);margin-bottom:12px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden"><?= sanitize($o['description']) ?></p><?php endif; ?>
    <a href="<?= SITE_URL ?>/opportunity.php?id=<?= (int)$o['id'] ?>" class="btn-primary btn-sm" style="width:100%;justify-content:center">View Details →</a>
  </div>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>

</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
