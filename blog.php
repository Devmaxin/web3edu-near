<?php
require_once __DIR__ . '/includes/config.php';
$pageTitle = 'Web3 Blog';
$extraCss  = [SITE_URL . '/assets/css/blog.css'];
$extraJs   = [SITE_URL . '/assets/js/bookmark.js'];
$db = getDB();

$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = 9;
$offset  = ($page - 1) * $perPage;

$total = $db->query("SELECT COUNT(*) FROM blog_posts WHERE is_published=1 AND deleted_at IS NULL")->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

$stmt = $db->prepare("SELECT * FROM blog_posts WHERE is_published=1 AND deleted_at IS NULL ORDER BY is_featured DESC, created_at DESC LIMIT ? OFFSET ?");
$stmt->execute([$perPage, $offset]);
$posts = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header container" data-reveal>
  <div class="section-label">Web3 insights</div>
  <h1 class="page-header-title">The Web3Edu <span class="gradient-text">Blog</span></h1>
  <p class="page-header-subtitle">Blockchain news, analysis, and guides from our team of Web3 experts.</p>
</div>

<div class="container section">

  <?php if (empty($posts)): ?>
    <div class="learn-empty">
      <h3>No posts yet</h3>
      <p>Check back soon for Web3 insights and news.</p>
    </div>
  <?php else:
    // Pull the featured post off the top on page 1
    $featured = null;
    $rest     = $posts;
    if ($page === 1) { $featured = array_shift($rest); }
  ?>

  <?php if ($featured): ?>
    <a href="<?= SITE_URL ?>/blog-post.php?slug=<?= urlencode($featured['slug']) ?>" class="featured-blog-card" data-reveal>
      <div class="featured-blog-media">
        <?php if (!empty($featured['cover_image'])): ?>
          <img src="<?= htmlspecialchars(imageUrl($featured['cover_image'], 'blog')) ?>" alt="<?= sanitize($featured['title']) ?>" class="featured-blog-img">
        <?php endif; ?>
        <span class="badge badge-accent featured-blog-badge">★ Featured</span>
        <button class="bookmark-btn <?= isBookmarked('blog_post', (int)$featured['id']) ? 'active' : '' ?>"
                style="position:absolute;top:var(--space-4);right:var(--space-4)"
                data-type="blog_post" data-id="<?= (int)$featured['id'] ?>"
                aria-pressed="<?= isBookmarked('blog_post', (int)$featured['id']) ? 'true' : 'false' ?>"
                title="<?= isBookmarked('blog_post', (int)$featured['id']) ? 'Remove bookmark' : 'Save for later' ?>">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
        </button>
      </div>
      <div class="featured-blog-body">
        <div class="blog-cat">Web3 insights</div>
        <h2 style="font-size:var(--text-2xl);margin-bottom:var(--space-3)"><?= sanitize($featured['title']) ?></h2>
        <p><?= sanitize(substr($featured['excerpt'] ?? strip_tags($featured['content']), 0, 160)) ?>…</p>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-top:var(--space-4)">
          <span style="color:var(--text-muted);font-size:var(--text-xs)"><?= timeAgo($featured['created_at']) ?></span>
          <span style="color:var(--accent-bright);font-weight:600;font-size:var(--text-sm)">Read article →</span>
        </div>
      </div>
    </a>
  <?php endif; ?>

  <?php if (!empty($rest)): ?>
    <div class="grid-3">
      <?php foreach ($rest as $post): ?>
        <article class="card blog-card card-interactive" data-reveal>
          <a href="<?= SITE_URL ?>/blog-post.php?slug=<?= urlencode($post['slug']) ?>">
            <div class="blog-thumb">
              <?php if (!empty($post['cover_image'])): ?>
                <img src="<?= htmlspecialchars(imageUrl($post['cover_image'], 'blog')) ?>" alt="<?= sanitize($post['title']) ?>" loading="lazy">
              <?php endif; ?>
              <?php if ($post['is_featured']): ?><span class="badge badge-accent content-card-badge">Featured</span><?php endif; ?>
              <button class="bookmark-btn <?= isBookmarked('blog_post', (int)$post['id']) ? 'active' : '' ?>"
                      style="position:absolute;top:var(--space-3);right:var(--space-3)"
                      data-type="blog_post" data-id="<?= (int)$post['id'] ?>"
                      aria-pressed="<?= isBookmarked('blog_post', (int)$post['id']) ? 'true' : 'false' ?>"
                      title="<?= isBookmarked('blog_post', (int)$post['id']) ? 'Remove bookmark' : 'Save for later' ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
              </button>
            </div>
          </a>
          <div class="blog-body">
            <div class="blog-cat">Web3</div>
            <h3 class="blog-title"><a href="<?= SITE_URL ?>/blog-post.php?slug=<?= urlencode($post['slug']) ?>" style="color:inherit"><?= sanitize($post['title']) ?></a></h3>
            <p class="blog-excerpt"><?= sanitize(substr($post['excerpt'] ?? strip_tags($post['content']), 0, 110)) ?>…</p>
            <div class="blog-meta">
              <span><?= timeAgo($post['created_at']) ?></span>
              <a href="<?= SITE_URL ?>/blog-post.php?slug=<?= urlencode($post['slug']) ?>" style="color:var(--accent-bright);font-weight:600">Read →</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($pages > 1): ?>
    <div class="pagination" style="margin-top:var(--space-7);justify-content:center">
      <?php if ($page > 1): ?><a href="?page=<?= $page-1 ?>" class="page-btn">‹ Prev</a><?php endif; ?>
      <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a href="?page=<?= $i ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
      <?php endfor; ?>
      <?php if ($page < $pages): ?><a href="?page=<?= $page+1 ?>" class="page-btn">Next ›</a><?php endif; ?>
    </div>
  <?php endif; ?>

  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
