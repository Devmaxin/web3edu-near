<?php
require_once __DIR__ . '/includes/config.php';

$slug = trim($_GET['slug'] ?? '');
if (empty($slug)) { header('Location: ' . SITE_URL . '/blog.php'); exit; }

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM blog_posts WHERE slug = ? AND is_published = 1 AND deleted_at IS NULL");
$stmt->execute([$slug]);
$post = $stmt->fetch();
if (!$post) { header('Location: ' . SITE_URL . '/blog.php'); exit; }

$pageTitle = $post['title'];
$extraCss  = [SITE_URL . '/assets/css/blog.css'];
$extraJs   = [SITE_URL . '/assets/js/bookmark.js'];

$stmt = $db->prepare("SELECT id,title,slug,excerpt,created_at,cover_image FROM blog_posts WHERE is_published=1 AND deleted_at IS NULL AND id!=? ORDER BY RAND() LIMIT 3");
$stmt->execute([$post['id']]);
$related = $stmt->fetchAll();

$readMins = max(1, (int)(str_word_count(strip_tags($post['content'])) / 200));

ob_start();
?>
<button class="bookmark-btn <?= isBookmarked('blog_post', (int)$post['id']) ? 'active' : '' ?>"
        data-type="blog_post" data-id="<?= (int)$post['id'] ?>"
        aria-pressed="<?= isBookmarked('blog_post', (int)$post['id']) ? 'true' : 'false' ?>"
        title="<?= isBookmarked('blog_post', (int)$post['id']) ? 'Remove bookmark' : 'Save for later' ?>">
  <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
</button>
<?php
$bookmarkBtnHtml = ob_get_clean();

require_once __DIR__ . '/includes/header.php';
?>

<?php if (!empty($post['cover_image'])): ?>
  <div class="blog-hero-cover">
    <img src="<?= htmlspecialchars(imageUrl($post['cover_image'], 'blog')) ?>" alt="<?= sanitize($post['title']) ?>">
    <div class="blog-hero-gradient"></div>
    <div class="blog-hero-content">
      <div class="container">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:var(--space-3);margin-bottom:var(--space-3)">
          <a href="<?= SITE_URL ?>/blog.php" class="btn btn-ghost btn-sm" style="color:rgba(255,255,255,0.8)">← Back to blog</a>
          <?= $bookmarkBtnHtml ?>
        </div>
        <span class="badge badge-accent" style="margin-bottom:var(--space-3)">Web3 insights</span>
        <h1 style="font-size:clamp(1.8rem,4vw,var(--text-3xl));color:#fff;max-width:760px;margin-bottom:var(--space-3)"><?= sanitize($post['title']) ?></h1>
        <div style="display:flex;gap:var(--space-4);color:rgba(255,255,255,0.65);font-size:var(--text-sm);flex-wrap:wrap">
          <span><?= date('F j, Y', strtotime($post['created_at'])) ?></span>
          <span><?= $readMins ?> min read</span>
        </div>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="page-header container">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:var(--space-3);margin-bottom:var(--space-4)">
      <a href="<?= SITE_URL ?>/blog.php" class="btn btn-ghost btn-sm">← Back to blog</a>
      <?= $bookmarkBtnHtml ?>
    </div>
    <div class="section-label">Web3 insights</div>
    <h1 class="page-header-title"><?= sanitize($post['title']) ?></h1>
    <div style="display:flex;gap:var(--space-4);color:var(--text-muted);font-size:var(--text-sm);margin-top:var(--space-2);flex-wrap:wrap">
      <span><?= date('F j, Y', strtotime($post['created_at'])) ?></span>
      <span><?= $readMins ?> min read</span>
    </div>
  </div>
<?php endif; ?>

<div class="container section">
  <div class="blog-post-layout">

    <article>
      <div style="font-size:var(--text-base);line-height:1.75"><?= $post['content'] ?></div>

      <div class="card blog-share-strip">
        <div>
          <div style="font-weight:700">Enjoyed this article?</div>
          <div style="color:var(--text-muted);font-size:var(--text-sm)">Share it with your Web3 community</div>
        </div>
        <a href="https://twitter.com/intent/tweet?text=<?= urlencode($post['title']) ?>&url=<?= urlencode(SITE_URL . '/blog-post.php?slug=' . $post['slug']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm">Share on X →</a>
      </div>
    </article>

    <aside class="blog-sidebar-card">
      <?php if (!isPremium()): ?>
        <div class="card" style="text-align:center;margin-bottom:var(--space-4);background:var(--accent-soft);border:1px solid var(--border-strong)">
          <h3 style="font-size:var(--text-base)">⭐ Unlock Premium</h3>
          <p style="font-size:var(--text-sm)">Access all premium lessons, airdrop guides, and advanced strategies.</p>
          <a href="<?= SITE_URL ?>/upgrade.php" class="btn btn-primary" style="width:100%;justify-content:center">Go Premium</a>
        </div>
      <?php endif; ?>

      <?php if (!empty($related)): ?>
        <div class="card">
          <div class="section-label">More articles</div>
          <?php foreach ($related as $r): ?>
            <a href="<?= SITE_URL ?>/blog-post.php?slug=<?= urlencode($r['slug']) ?>" class="related-post-link">
              <?php if (!empty($r['cover_image'])): ?>
                <img src="<?= htmlspecialchars(imageUrl($r['cover_image'], 'blog')) ?>" class="related-post-thumb" alt="" loading="lazy">
              <?php else: ?>
                <div class="related-post-thumb"></div>
              <?php endif; ?>
              <div style="min-width:0">
                <div class="related-post-title"><?= sanitize($r['title']) ?></div>
                <div style="font-size:var(--text-xs);color:var(--text-muted)"><?= timeAgo($r['created_at']) ?></div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </aside>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
