<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/video_helpers.php';

if (!isLoggedIn()) {
    $_SESSION['redirect_after_login'] = SITE_URL . '/videos.php';
    setFlash('info', 'Sign in to browse video lessons.');
    header('Location: ' . SITE_URL . '/login.php'); exit;
}
requireProfileComplete();

$pageTitle = 'Videos — Web3 Video Library';
$db = getDB();

// ── Query params ──────────────────────────────────────────────
$filter  = in_array($_GET['filter'] ?? '', ['all','free','premium']) ? $_GET['filter'] : 'all';
$search  = trim($_GET['search'] ?? '');
$catSlug = trim($_GET['cat'] ?? '');
$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = 6;

// ── Load categories ───────────────────────────────────────────
$allCats = $db->query(
    "SELECT vc.*, COUNT(l.id) AS lesson_count
     FROM   categories vc
     LEFT JOIN lessons l ON l.video_category_id = vc.id
                         AND l.video_id IS NOT NULL AND l.video_id != ''
     GROUP BY vc.id
     ORDER BY vc.sort_order ASC, vc.name ASC"
)->fetchAll();

$activeCat = null;
foreach ($allCats as $c) {
    if ($c['slug'] === $catSlug) { $activeCat = $c; break; }
}

// ── Build WHERE clause ────────────────────────────────────────
$where  = ["(l.video_id IS NOT NULL AND l.video_id != '')"];
$params = [];

if ($filter === 'free')        { $where[] = "l.access_level = 'free'"; }
elseif ($filter === 'premium') { $where[] = "l.access_level = 'premium'"; }
if (!empty($search)) {
    $where[] = "(l.title LIKE ? OR l.preview_content LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
}
if ($activeCat) {
    $where[] = "l.video_category_id = ?";
    $params[] = $activeCat['id'];
}

$whereSQL = implode(' AND ', $where);

// ── Total count ───────────────────────────────────────────────
$countStmt = $db->prepare(
    "SELECT COUNT(*) FROM lessons l WHERE $whereSQL"
);
$countStmt->execute($params);
$totalItems = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalItems / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// ── Paginated video fetch ────────────────────────────────────
$stmt = $db->prepare(
    "SELECT l.*, vc.name AS cat_name, vc.slug AS cat_slug
     FROM   lessons l
     LEFT JOIN categories vc ON vc.id = l.video_category_id
     WHERE  $whereSQL
     ORDER  BY l.sort_order ASC, l.is_featured DESC, l.created_at DESC
     LIMIT  $perPage OFFSET $offset"
);
$stmt->execute($params);
$videos = $stmt->fetchAll();

// ── Global counts (for filter tabs) ──────────────────────────
$totalVideos   = (int)$db->query("SELECT COUNT(*) FROM lessons WHERE video_id IS NOT NULL AND video_id != ''")->fetchColumn();
$freeVideos    = (int)$db->query("SELECT COUNT(*) FROM lessons WHERE video_id IS NOT NULL AND video_id != '' AND access_level='free'")->fetchColumn();
$premiumVideos = (int)$db->query("SELECT COUNT(*) FROM lessons WHERE video_id IS NOT NULL AND video_id != '' AND access_level='premium'")->fetchColumn();
$counts = ['all' => $totalVideos, 'free' => $freeVideos, 'premium' => $premiumVideos];

$premiumPriceDisplay = getPremiumPriceDisplay();

// ── URL helper ────────────────────────────────────────────────
function videoPageUrl(int $p, array $get): string {
    $q = array_filter($get, fn($v) => $v !== '' && $v !== null);
    unset($q['page']);
    if ($p > 1) $q['page'] = $p;
    return '?' . http_build_query($q);
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- Thumbnail fallback JS -->
<script>
function ytThumbFallback(img, hq, mq) {
  if (img.dataset.fallback === 'hq') { img.dataset.fallback = 'mq'; img.src = mq; }
  else if (!img.dataset.fallback)    { img.dataset.fallback = 'hq'; img.src = hq; }
}
</script>

<style>
/* ── Videos layout ─────────────────────────────────────────── */
.videos-layout {
    display: grid;
    grid-template-columns: 240px 1fr;
    gap: 28px;
    align-items: start;
}
@media (max-width: 900px) {
    .videos-layout { grid-template-columns: 1fr; }
    .videos-sidebar { display: none; }
}

/* ── Sidebar ────────────────────────────────────────────────── */
.videos-sidebar {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    overflow: hidden;
    position: sticky;
    top: 80px;
}
.vsidebar-head {
    padding: 14px 16px;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.09em;
    text-transform: uppercase;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border);
    background: var(--bg-mid, var(--bg-card));
}
.vcat-link {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 10px 16px;
    font-size: 0.84rem;
    font-weight: 500;
    color: var(--text-muted);
    text-decoration: none;
    border-left: 3px solid transparent;
    transition: all 0.18s;
}
.vcat-link:hover { background: rgba(11,121,88,0.06); color: var(--text); }
.vcat-link.active {
    background: rgba(11,121,88,0.1);
    border-left-color: var(--accent);
    color: var(--accent-bright);
    font-weight: 600;
}
.vcat-count {
    font-size: 0.72rem;
    background: var(--bg-mid, var(--border));
    border-radius: 99px;
    padding: 2px 8px;
    min-width: 22px;
    text-align: center;
    flex-shrink: 0;
}

/* ── Mobile category pills ──────────────────────────────────── */
.vcat-pills { display: none; gap: 7px; flex-wrap: wrap; margin-bottom: 20px; }
@media (max-width: 900px) { .vcat-pills { display: flex; } }
.vcat-pill {
    padding: 5px 14px;
    border-radius: 20px;
    font-size: 0.78rem;
    font-weight: 600;
    border: 1.5px solid var(--border);
    color: var(--text-muted);
    background: transparent;
    text-decoration: none;
    transition: all 0.18s;
    white-space: nowrap;
}
.vcat-pill.active, .vcat-pill:hover {
    border-color: var(--accent);
    color: var(--accent-bright);
    background: var(--glow-soft, rgba(11,121,88,0.08));
}

/* ── Video card ─────────────────────────────────────────────── */
.video-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    overflow: hidden;
    transition: transform 0.2s, border-color 0.2s, box-shadow 0.2s;
    display: flex;
    flex-direction: column;
}
.video-card:hover {
    transform: translateY(-3px);
    border-color: rgba(11,121,88,0.4);
    box-shadow: 0 8px 24px rgba(0,0,0,0.25);
}
.video-thumb {
    position: relative;
    width: 100%;
    aspect-ratio: 16/9;
    background: linear-gradient(135deg, var(--bg-mid, #1a1a2e), var(--bg-card2, #16213e));
    overflow: hidden;
    flex-shrink: 0;
}
.video-thumb img {
    width: 100%; height: 100%;
    object-fit: cover;
    transition: transform 0.35s ease;
    display: block;
}
.video-card:hover .video-thumb img { transform: scale(1.04); }
.play-btn { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; }
.play-btn-inner {
    width: 44px; height: 44px;
    background: rgba(0,0,0,0.6);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    backdrop-filter: blur(3px);
    border: 2px solid rgba(255,255,255,0.25);
    transition: transform 0.2s, background 0.2s;
}
.video-card:hover .play-btn-inner { transform: scale(1.12); background: rgba(11,121,88,0.7); }
.video-badge {
    position: absolute; top: 8px; right: 8px;
    padding: 3px 9px; border-radius: 20px;
    font-size: 0.68rem; font-weight: 700;
    letter-spacing: 0.04em; text-transform: uppercase; z-index: 2;
}
.badge-free    { background: rgba(11,121,88,0.85);  color: #fff; }
.badge-premium { background: rgba(11,121,88,0.85); color: #fff; }
.video-lock {
    position: absolute; inset: 0;
    background: rgba(0,0,0,0.55);
    display: flex; align-items: center; justify-content: center; z-index: 3;
}
.video-body {
    padding: 14px 16px; flex: 1;
    display: flex; flex-direction: column; gap: 6px;
}
.video-cat-tag {
    font-size: 0.7rem; font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.07em;
    color: var(--accent-bright); opacity: 0.8;
}
.video-title { font-size: 0.92rem; font-weight: 700; line-height: 1.35; color: var(--text); margin: 0; }
.video-meta {
    display: flex; align-items: center; gap: 10px;
    font-size: 0.75rem; color: var(--text-muted);
    margin-top: auto; padding-top: 6px;
}

/* ── Category banner ────────────────────────────────────────── */
.vcat-banner {
    background: linear-gradient(135deg, rgba(11,121,88,0.12), rgba(6,182,212,0.06));
    border: 1.5px solid rgba(11,121,88,0.25);
    border-radius: var(--radius-lg);
    padding: 20px 24px;
    margin-bottom: 24px;
    display: flex; align-items: center; justify-content: space-between;
    gap: 16px; flex-wrap: wrap;
}

/* ── Stats bar ──────────────────────────────────────────────── */
.videos-stats { display: flex; align-items: center; gap: 18px; margin-bottom: 18px; flex-wrap: wrap; }
.vstat { font-size: 0.82rem; color: var(--text-muted); }
.vstat strong { color: var(--text); }

/* ── Pagination ─────────────────────────────────────────────── */
.pagination-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 18px;
    margin-top: 48px;
    margin-bottom: 8px;
}
.pagination {
    display: flex; align-items: center; gap: 4px;
    flex-wrap: wrap; justify-content: center; margin: 0;
}
.page-btn {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 38px; height: 38px; padding: 0 10px;
    border-radius: 10px; font-size: 0.84rem; font-weight: 600;
    border: 1.5px solid var(--border); background: var(--bg-card);
    color: var(--text-muted); cursor: pointer;
    transition: all 0.18s; text-decoration: none; user-select: none;
}
.page-btn:hover:not(.disabled) {
    border-color: var(--accent); color: var(--accent-bright);
    background: var(--glow-soft, rgba(11,121,88,0.08));
}
.page-btn.active {
    border-color: var(--accent); color: var(--accent-bright);
    background: var(--glow-soft, rgba(11,121,88,0.1));
}
.page-btn.disabled { opacity: 0.35; cursor: not-allowed; pointer-events: none; }
.page-btn.arrow { font-size: 1rem; font-weight: 700; gap: 4px; }
.page-dots {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 38px; height: 38px;
    color: var(--text-muted); font-size: 0.9rem; letter-spacing: 2px;
}
.pagination-info { font-size: 0.8rem; color: var(--text-muted); }
.pagination-info strong { color: var(--text); }

/* ── Grid fade-in ───────────────────────────────────────────── */
.grid-paged { animation: pageFadeIn 0.28s ease; }
@keyframes pageFadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}
</style>

<!-- Page header -->
<div class="page-header">
  <div class="container">
    <div data-reveal>
      <div class="section-label">Video Library</div>
      <h1 class="page-header-title">Web3 <span class="gradient-text">Video Lessons</span></h1>
      <p class="page-header-subtitle">Watch curated video lessons across blockchain, DeFi, NFTs, smart contracts, and more — at your own pace.</p>
    </div>
    <?php if (!isPremium()): ?>
    <div style="margin-top:22px;padding:14px 20px;background:rgba(11,121,88,0.08);border:1.5px solid rgba(11,121,88,0.25);border-radius:var(--radius);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px">
      <div style="display:flex;align-items:center;gap:10px">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--accent-bright);flex-shrink:0"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <div>
          <span style="color:var(--accent-bright);font-weight:600;font-size:0.9rem"><?= $counts['premium'] ?> premium videos locked</span>
          <span style="color:var(--text-muted);font-size:0.84rem;margin-left:8px">Upgrade for full access.</span>
        </div>
      </div>
      <a href="<?= SITE_URL ?>/upgrade.php" class="btn-upgrade btn-sm">
        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
        Upgrade — <?= $premiumPriceDisplay ?>
      </a>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="section-sm">
  <div class="container">

    <!-- Search + filter -->
    <div style="margin-bottom:22px" data-reveal>
      <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <div class="search-wrap" style="flex:1;min-width:220px">
          <span class="search-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          </span>
          <input type="text" name="search" class="search-input" placeholder="Search videos…" value="<?= sanitize($search) ?>">
          <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?= sanitize($filter) ?>"><?php endif; ?>
          <?php if ($catSlug):         ?><input type="hidden" name="cat"    value="<?= sanitize($catSlug) ?>"><?php endif; ?>
        </div>
        <div class="tabs">
          <?php foreach (['all'=>'All','free'=>'Free','premium'=>'Premium'] as $val => $label): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['filter'=>$val, 'page'=>1])) ?>"
             class="tab-btn <?= $filter===$val?'active':'' ?>">
            <?= $label ?>
            <span style="opacity:0.55;font-size:0.75rem;margin-left:3px">(<?= $counts[$val] ?>)</span>
          </a>
          <?php endforeach; ?>
        </div>
      </form>
    </div>

    <!-- Mobile category pills -->
    <div class="vcat-pills" data-reveal>
      <a href="<?= SITE_URL ?>/videos.php?filter=<?= $filter ?><?= $search ? '&search='.urlencode($search) : '' ?>"
         class="vcat-pill <?= !$activeCat ? 'active' : '' ?>">All</a>
      <?php foreach ($allCats as $c): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['cat'=>$c['slug'], 'page'=>1])) ?>"
         class="vcat-pill <?= ($activeCat && $activeCat['id']===$c['id']) ? 'active' : '' ?>">
        <?= sanitize($c['name']) ?>
        <span style="opacity:0.6">(<?= (int)$c['lesson_count'] ?>)</span>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Layout: sidebar + main -->
    <div class="videos-layout">

      <!-- Sidebar -->
      <aside class="videos-sidebar" data-reveal>
        <div class="vsidebar-head">Categories</div>
        <a href="<?= SITE_URL ?>/videos.php?filter=<?= $filter ?><?= $search ? '&search='.urlencode($search) : '' ?>"
           class="vcat-link <?= !$activeCat ? 'active' : '' ?>">
          <span>All Videos</span>
          <span class="vcat-count"><?= $totalVideos ?></span>
        </a>
        <?php foreach ($allCats as $c): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['cat'=>$c['slug'], 'page'=>1])) ?>"
           class="vcat-link <?= ($activeCat && $activeCat['id']===$c['id']) ? 'active' : '' ?>">
          <span><?= sanitize($c['name']) ?></span>
          <span class="vcat-count"><?= (int)$c['lesson_count'] ?></span>
        </a>
        <?php endforeach; ?>
      </aside>

      <!-- Main -->
      <main>

        <!-- Category banner -->
        <?php if ($activeCat): ?>
        <div class="vcat-banner" data-reveal>
          <div>
            <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--accent-bright);font-weight:600;margin-bottom:4px">Category</div>
            <h2 style="margin:0;font-size:1.15rem;font-weight:700"><?= sanitize($activeCat['name']) ?></h2>
            <?php if (!empty($activeCat['description'])): ?>
            <p style="margin:6px 0 0;font-size:0.85rem;color:var(--text-muted)"><?= sanitize($activeCat['description']) ?></p>
            <?php endif; ?>
          </div>
          <a href="<?= SITE_URL ?>/videos.php" style="font-size:0.8rem;color:var(--text-muted);text-decoration:none;white-space:nowrap;display:flex;align-items:center;gap:4px">
            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            All categories
          </a>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="videos-stats" data-reveal>
          <div class="vstat">
            Showing <strong><?= count($videos) ?></strong> of <strong><?= $totalItems ?></strong>
            video<?= $totalItems !== 1 ? 's' : '' ?>
            <?= !empty($search) ? ' for <strong>"'.sanitize($search).'"</strong>' : '' ?>
            <?php if ($totalPages > 1): ?>
            · <span>Page <?= $page ?> of <?= $totalPages ?></span>
            <?php endif; ?>
          </div>
          <?php if (!empty($search) || $activeCat || $filter !== 'all'): ?>
          <a href="<?= SITE_URL ?>/videos.php" style="font-size:0.8rem;color:var(--text-muted);text-decoration:none">✕ Clear filters</a>
          <?php endif; ?>
        </div>

        <!-- Video grid -->
        <?php if (empty($videos)): ?>
        <div style="text-align:center;padding:80px 20px;color:var(--text-muted)">
          <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto 16px;display:block;color:var(--border)"><polygon points="5 3 19 12 5 21 5 3"/></svg>
          <h3 style="margin-bottom:8px">No videos found</h3>
          <p style="font-size:0.88rem">Try a different search or category.</p>
          <a href="<?= SITE_URL ?>/videos.php" class="btn-ghost" style="margin-top:16px;display:inline-flex">Clear filters</a>
        </div>
        <?php else: ?>
        <div class="grid-3 grid-paged">
          <?php foreach ($videos as $v):
            $isPremiumLesson = $v['access_level'] === 'premium';
            $isLocked        = $isPremiumLesson && !isPremium();
            $videoUrl        = SITE_URL . '/learn/lesson/' . $v['id'];
            $cardUrl         = $isLocked ? SITE_URL . '/upgrade.php' : $videoUrl;
            $thumb_max = youtubeThumbnailUrl($v['video_id'], 'maxresdefault');
            $thumb_hq  = youtubeThumbnailUrl($v['video_id'], 'hqdefault');
            $thumb_mq  = youtubeThumbnailUrl($v['video_id'], 'mqdefault');
          ?>
          <article class="video-card" data-reveal>
            <a href="<?= $cardUrl ?>" style="display:block;text-decoration:none">
              <div class="video-thumb">
                <img src="<?= $thumb_max ?>" alt="<?= sanitize($v['title']) ?>"
                     loading="lazy"
                     onerror="ytThumbFallback(this,'<?= $thumb_hq ?>','<?= $thumb_mq ?>')">
                <div class="play-btn">
                  <div class="play-btn-inner">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="white" stroke="none"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                  </div>
                </div>
                <div class="video-badge <?= $isPremiumLesson ? 'badge-premium' : 'badge-free' ?>">
                  <?= $isPremiumLesson ? '⭐ Premium' : 'Free' ?>
                </div>
                <?php if ($isLocked): ?>
                <div class="video-lock">
                  <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </div>
                <?php endif; ?>
              </div>
            </a>

            <div class="video-body">
              <?php if (!empty($v['cat_name'])): ?>
              <div class="video-cat-tag"><?= sanitize($v['cat_name']) ?></div>
              <?php endif; ?>
              <a href="<?= $cardUrl ?>" style="text-decoration:none">
                <h3 class="video-title"><?= sanitize($v['title']) ?></h3>
              </a>
              <?php if (!empty($v['preview_content'])): ?>
              <p style="font-size:0.8rem;color:var(--text-muted);margin:0;line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
                <?= sanitize(strip_tags($v['preview_content'])) ?>
              </p>
              <?php endif; ?>
              <div class="video-meta">
                <span style="display:flex;align-items:center;gap:4px">
                  <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                  <?= sanitize($v['duration'] ?? '—') ?>
                </span>
                <?php if ($isLocked): ?>
                <a href="<?= SITE_URL ?>/upgrade.php" class="btn-upgrade" style="padding:4px 12px;font-size:0.72rem;min-height:unset;margin-left:auto">
                  <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                  Upgrade
                </a>
                <?php else: ?>
                <a href="<?= $videoUrl ?>" class="btn-ghost" style="padding:4px 12px;font-size:0.72rem;min-height:unset;margin-left:auto">Watch</a>
                <?php endif; ?>
              </div>
            </div>
          </article>
          <?php endforeach; ?>
        </div>

        <!-- ── Pagination ── -->
        <?php if ($totalPages > 1): ?>
        <nav class="pagination-wrap" aria-label="Video pages">
          <div class="pagination">

            <!-- Prev -->
            <?php if ($page > 1): ?>
            <a href="<?= videoPageUrl($page - 1, $_GET) ?>" class="page-btn arrow" aria-label="Previous page">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
              Prev
            </a>
            <?php else: ?>
            <span class="page-btn arrow disabled" aria-disabled="true">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
              Prev
            </span>
            <?php endif; ?>

            <!-- Numbered pages with ellipsis -->
            <?php
            $window = 2;
            for ($i = 1; $i <= $totalPages; $i++):
                $nearStart   = $i <= $window + 1;
                $nearEnd     = $i >= $totalPages - $window;
                $nearCurrent = abs($i - $page) <= $window;
                if (!$nearStart && !$nearEnd && !$nearCurrent):
                    if ($i === $window + 2 || $i === $totalPages - $window - 1):
            ?>
            <span class="page-dots">…</span>
            <?php       endif; continue; endif; ?>
            <a href="<?= videoPageUrl($i, $_GET) ?>"
               class="page-btn <?= $i === $page ? 'active' : '' ?>"
               <?= $i === $page ? 'aria-current="page"' : '' ?>>
              <?= $i ?>
            </a>
            <?php endfor; ?>

            <!-- Next -->
            <?php if ($page < $totalPages): ?>
            <a href="<?= videoPageUrl($page + 1, $_GET) ?>" class="page-btn arrow" aria-label="Next page">
              Next
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
            <?php else: ?>
            <span class="page-btn arrow disabled" aria-disabled="true">
              Next
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            </span>
            <?php endif; ?>

          </div>
          <div class="pagination-info">
            Showing videos <strong><?= $offset + 1 ?></strong>–<strong><?= min($offset + $perPage, $totalItems) ?></strong>
            of <strong><?= $totalItems ?></strong>
          </div>
        </nav>
        <?php endif; ?>

        <?php endif; // end else (videos found) ?>

      </main>
    </div><!-- /videos-layout -->
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
