<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/video_helpers.php';

if (!isLoggedIn()) {
    $_SESSION['redirect_after_login'] = SITE_URL . '/lessons.php';
    setFlash('info', 'Sign in to access all lessons.');
    header('Location: ' . SITE_URL . '/login.php'); exit;
}
requireProfileComplete();

$pageTitle = 'Browse Lessons';
$db = getDB();

// ── Query params ──────────────────────────────────────────────
$filter   = $_GET['filter'] ?? 'all';
$search   = trim($_GET['search'] ?? '');
$category = trim($_GET['cat'] ?? '');
$page     = max(1, intval($_GET['page'] ?? 1));
$perPage  = 6;

// ── Build WHERE clause ────────────────────────────────────────
$where  = ['1=1'];
$params = [];

if ($filter === 'free')        { $where[] = "access_level = 'free'"; }
elseif ($filter === 'premium') { $where[] = "access_level = 'premium'"; }
if (!empty($search)) {
    $where[] = "(title LIKE ? OR preview_content LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
    Analytics::track('search_performed', (int)$_SESSION['user_id'], ['query' => mb_substr($search, 0, 100), 'context' => 'lessons']);
}
if (!empty($category)) { $where[] = "category = ?"; $params[] = $category; }

$whereSQL = implode(' AND ', $where);

// ── Total count (for pagination) ──────────────────────────────
$countStmt = $db->prepare("SELECT COUNT(*) FROM lessons WHERE $whereSQL");
$countStmt->execute($params);
$totalItems = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalItems / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// ── Paginated lesson fetch ────────────────────────────────────
$stmt = $db->prepare(
    "SELECT * FROM lessons WHERE $whereSQL
     ORDER BY sort_order ASC, is_featured DESC, created_at DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$lessons = $stmt->fetchAll();

// ── Category list & counts ────────────────────────────────────
$cats = $db->query(
    "SELECT vc.name AS category FROM categories vc
     WHERE EXISTS (SELECT 1 FROM lessons l WHERE l.video_category_id = vc.id)
     UNION
     SELECT DISTINCT category FROM lessons WHERE category IS NOT NULL AND category != ''
     ORDER BY category"
)->fetchAll(PDO::FETCH_COLUMN);

$counts = [
    'all'     => $db->query("SELECT COUNT(*) FROM lessons")->fetchColumn(),
    'free'    => $db->query("SELECT COUNT(*) FROM lessons WHERE access_level='free'")->fetchColumn(),
    'premium' => $db->query("SELECT COUNT(*) FROM lessons WHERE access_level='premium'")->fetchColumn(),
];

// ── Build base URL (preserves all params except page) ─────────
function learnPageUrl(int $p, array $get): string {
    $q = array_filter(array_merge($get, ['page' => $p]), fn($v) => $v !== '' && $v !== null);
    unset($q['page']); // remove page=1 noise
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
/* ── Lesson card hover ── */
.lesson-card:hover .lesson-thumb-img { transform: scale(1.04); }

/* ── Pagination ── */
.pagination-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 18px;
    margin-top: 48px;
    margin-bottom: 8px;
}
.pagination {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
    justify-content: center;
    margin: 0;
}
.page-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 38px;
    height: 38px;
    padding: 0 10px;
    border-radius: 10px;
    font-size: 0.84rem;
    font-weight: 600;
    border: 1.5px solid var(--border);
    background: var(--bg-card);
    color: var(--text-muted);
    cursor: pointer;
    transition: all 0.18s;
    text-decoration: none;
    user-select: none;
}
.page-btn:hover:not(.disabled) {
    border-color: var(--accent);
    color: var(--accent-bright);
    background: var(--glow-soft, rgba(11,121,88,0.08));
}
.page-btn.active {
    border-color: var(--accent);
    color: var(--accent-bright);
    background: var(--glow-soft, rgba(11,121,88,0.1));
}
.page-btn.disabled {
    opacity: 0.35;
    cursor: not-allowed;
    pointer-events: none;
}
.page-btn.arrow {
    font-size: 1rem;
    font-weight: 700;
    gap: 4px;
}
.page-dots {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 38px;
    height: 38px;
    color: var(--text-muted);
    font-size: 0.9rem;
    letter-spacing: 2px;
}
.pagination-info {
    font-size: 0.8rem;
    color: var(--text-muted);
}
.pagination-info strong { color: var(--text); }

/* ── Grid fade-in on page change ── */
.grid-paged {
    animation: pageFadeIn 0.28s ease;
}
@keyframes pageFadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}
</style>

<div class="page-header">
  <div class="container">
    <div data-reveal>
      <div class="section-label">Video Curriculum</div>
      <h1 class="page-header-title">Web3 <span class="gradient-text">Learning Hub</span></h1>
      <p class="page-header-subtitle">Master blockchain from fundamentals to advanced development — video lessons, curated by experts.</p>
    </div>
    <?php if (!isPremium()): ?>
    <div style="margin-top:22px;padding:14px 20px;background:rgba(11,121,88,0.08);border:1.5px solid rgba(11,121,88,0.25);border-radius:var(--radius);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px">
      <div style="display:flex;align-items:center;gap:10px">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--accent-bright);flex-shrink:0"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <div>
          <span style="color:var(--accent-bright);font-weight:600;font-size:0.9rem"><?= $counts['premium'] ?> premium lessons locked</span>
          <span style="color:var(--text-muted);font-size:0.84rem;margin-left:8px">Upgrade for full access.</span>
        </div>
      </div>
      <a href="<?= SITE_URL ?>/upgrade.php" class="btn-upgrade btn-sm">
        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
        Upgrade Now
      </a>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="section-sm">
  <div class="container">

    <!-- Search + Filter bar -->
    <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:24px;align-items:center" data-reveal>
      <div class="search-wrap" style="flex:1;min-width:220px">
        <span class="search-icon">
          <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        </span>
        <form method="GET" style="width:100%">
          <input type="text" name="search" class="search-input" placeholder="Search lessons…" value="<?= sanitize($search) ?>">
          <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?= sanitize($filter) ?>"><?php endif; ?>
          <?php if ($category):         ?><input type="hidden" name="cat"    value="<?= sanitize($category) ?>"><?php endif; ?>
        </form>
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
    </div>

    <!-- Category pills -->
    <?php if (!empty($cats)): ?>
    <div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:28px" data-reveal>
      <a href="<?= SITE_URL ?>/learn.php?filter=<?= $filter ?>"
         style="padding:5px 14px;border-radius:20px;font-size:0.78rem;font-weight:600;border:1.5px solid <?= empty($category)?'var(--accent)':'var(--border)' ?>;color:<?= empty($category)?'var(--accent-bright)':'var(--text-muted)' ?>;background:<?= empty($category)?'var(--accent-soft)':'transparent' ?>;transition:all 0.2s;text-decoration:none">
        All
      </a>
      <?php foreach ($cats as $cat): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['cat'=>$cat, 'page'=>1])) ?>"
         style="padding:5px 14px;border-radius:20px;font-size:0.78rem;font-weight:600;border:1.5px solid <?= $category===$cat?'var(--accent)':'var(--border)' ?>;color:<?= $category===$cat?'var(--accent-bright)':'var(--text-muted)' ?>;background:<?= $category===$cat?'var(--accent-soft)':'transparent' ?>;transition:all 0.2s;text-decoration:none">
        <?= sanitize($cat) ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Results count -->
    <div style="margin-bottom:20px;color:var(--text-muted);font-size:0.84rem;display:flex;align-items:center;gap:6px">
      <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
      Showing <strong style="color:var(--text)"><?= count($lessons) ?></strong>
      of <strong style="color:var(--text)"><?= $totalItems ?></strong> lesson<?= $totalItems!==1?'s':'' ?>
      <?= !empty($search) ? ' for &ldquo;<strong style="color:var(--text)">' . sanitize($search) . '</strong>&rdquo;' : '' ?>
      <?php if ($totalPages > 1): ?>
      · <span>Page <?= $page ?> of <?= $totalPages ?></span>
      <?php endif; ?>
    </div>

    <!-- Lesson Grid -->
    <?php if (empty($lessons)): ?>
    <div style="text-align:center;padding:80px;color:var(--text-muted)">
      <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto 20px;display:block;color:var(--border)"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <h3 style="margin-bottom:8px">No lessons found</h3>
      <p>Try a different search or filter</p>
      <a href="<?= SITE_URL ?>/learn.php" class="btn-ghost" style="margin-top:16px;display:inline-flex">Clear filters</a>
    </div>
    <?php else: ?>
    <div class="grid-3 grid-paged">
      <?php foreach ($lessons as $lesson):
        $isPremiumLesson = $lesson['access_level'] === 'premium';
        $isLocked        = $isPremiumLesson && !isPremium();
        $hasVideo        = !empty($lesson['video_id']);
        $lessonUrl       = SITE_URL . '/learn/lesson/' . $lesson['id'];
        $cardUrl         = $isLocked ? SITE_URL . '/upgrade.php' : $lessonUrl;
      ?>
      <article class="lesson-card" data-reveal>

        <!-- Thumbnail -->
        <a href="<?= $cardUrl ?>" style="display:block;text-decoration:none">
          <div class="lesson-thumb" style="background:linear-gradient(135deg,<?= $isPremiumLesson?'#1a0e2a,#2a0a1a':'var(--bg-mid),var(--bg-card2)' ?>);position:relative">

            <?php if ($hasVideo): ?>
              <img src="<?= youtubeThumbnailUrl($lesson['video_id'], 'maxresdefault') ?>"
                   alt="<?= sanitize($lesson['title']) ?>"
                   class="lesson-thumb-img" loading="lazy"
                   style="width:100%;height:100%;object-fit:cover;transition:transform 0.4s ease"
                   onerror="ytThumbFallback(this,'<?= youtubeThumbnailUrl($lesson['video_id'], 'hqdefault') ?>','<?= youtubeThumbnailUrl($lesson['video_id'], 'mqdefault') ?>')">
              <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;z-index:1;pointer-events:none">
                <div style="background:rgba(0,0,0,0.55);border-radius:50%;width:44px;height:44px;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(2px);border:2px solid rgba(255,255,255,0.2)">
                  <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="white" stroke="none"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                </div>
              </div>
            <?php elseif (!empty($lesson['thumbnail'])): ?>
              <img src="<?= imageUrl($lesson['thumbnail'],'lesson') ?>"
                   alt="<?= sanitize($lesson['title']) ?>" class="lesson-thumb-img" loading="lazy"
                   style="width:100%;height:100%;object-fit:cover;transition:transform 0.4s ease">
            <?php else: ?>
              <div style="display:flex;align-items:center;justify-content:center;width:100%;height:100%">
                <?php if ($isPremiumLesson): ?>
                <svg xmlns="http://www.w3.org/2000/svg" width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="0.8" style="color:rgba(11,121,88,0.3)"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                <?php else: ?>
                <svg xmlns="http://www.w3.org/2000/svg" width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="0.8" style="color:rgba(11,121,88,0.2)"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <!-- Access badge -->
            <div class="lesson-badge <?= $isPremiumLesson?'badge-premium':'badge-free' ?>">
              <?php if ($isPremiumLesson): ?>
              <svg xmlns="http://www.w3.org/2000/svg" width="8" height="8" viewBox="0 0 24 24" fill="currentColor" stroke="none" style="display:inline"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              Premium
              <?php else: ?>Free<?php endif; ?>
            </div>

            <!-- Lock overlay -->
            <?php if ($isLocked): ?>
            <div style="position:absolute;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:2">
              <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:rgba(255,255,255,0.75)"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </div>
            <?php endif; ?>

            <!-- Order badge -->
            <?php if (!empty($lesson['sort_order'])): ?>
            <div style="position:absolute;top:8px;left:8px;background:rgba(0,0,0,0.6);border-radius:6px;padding:2px 7px;font-size:0.68rem;color:rgba(255,255,255,0.8);font-family:var(--font-mono)">
              #<?= intval($lesson['sort_order']) ?>
            </div>
            <?php endif; ?>
          </div>
        </a>

        <div class="lesson-body">
          <div class="lesson-cat" style="display:flex;align-items:center;gap:5px">
            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
            <?= sanitize($lesson['category']) ?>
            <?php if ($hasVideo): ?>
            <span style="display:inline-flex;align-items:center;gap:3px;font-size:0.7rem;color:#f87171;margin-left:4px">
              <svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="5 3 19 12 5 21 5 3"/></svg>
              Video
            </span>
            <?php else: ?>
            <span style="display:inline-flex;align-items:center;gap:3px;font-size:0.7rem;color:var(--text-muted);margin-left:4px">
              <svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/></svg>
              Text
            </span>
            <?php endif; ?>
          </div>
          <h3 class="lesson-title"><?= sanitize($lesson['title']) ?></h3>
          <p class="lesson-excerpt"><?= sanitize(substr($lesson['preview_content'] ?? '', 0, 110)) ?>…</p>
          <div class="lesson-footer">
            <span class="lesson-duration" style="display:flex;align-items:center;gap:4px">
              <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              <?= sanitize($lesson['duration'] ?? '') ?>
            </span>
            <?php if ($isLocked): ?>
            <a href="<?= SITE_URL ?>/upgrade.php" class="lesson-link lesson-lock" style="display:flex;align-items:center;gap:4px">
              <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              Unlock
            </a>
            <?php else: ?>
            <a href="<?= $lessonUrl ?>" class="lesson-link" style="display:flex;align-items:center;gap:4px">
              <?= $hasVideo ? 'Watch Now' : 'Read Now' ?>
              <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
            <?php endif; ?>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>

    <!-- ── Pagination ── -->
    <?php if ($totalPages > 1): ?>
    <nav class="pagination-wrap" aria-label="Lesson pages">
      <div class="pagination">

        <!-- Prev arrow -->
        <?php if ($page > 1): ?>
        <a href="<?= learnPageUrl($page - 1, $_GET) ?>" class="page-btn arrow" aria-label="Previous page">
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
          Prev
        </a>
        <?php else: ?>
        <span class="page-btn arrow disabled" aria-disabled="true">
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
          Prev
        </span>
        <?php endif; ?>

        <!-- Numbered pages with smart ellipsis -->
        <?php
        $window = 2; // pages to show each side of current
        for ($i = 1; $i <= $totalPages; $i++):
            $nearStart   = $i <= $window + 1;
            $nearEnd     = $i >= $totalPages - $window;
            $nearCurrent = abs($i - $page) <= $window;
            if (!$nearStart && !$nearEnd && !$nearCurrent):
                // Show ellipsis once per gap
                if ($i === $window + 2 || $i === $totalPages - $window - 1):
        ?>
        <span class="page-dots">…</span>
        <?php   endif; continue; endif; ?>
        <a href="<?= learnPageUrl($i, $_GET) ?>"
           class="page-btn <?= $i === $page ? 'active' : '' ?>"
           <?= $i === $page ? 'aria-current="page"' : '' ?>>
          <?= $i ?>
        </a>
        <?php endfor; ?>

        <!-- Next arrow -->
        <?php if ($page < $totalPages): ?>
        <a href="<?= learnPageUrl($page + 1, $_GET) ?>" class="page-btn arrow" aria-label="Next page">
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
        Showing lessons <strong><?= $offset + 1 ?></strong>–<strong><?= min($offset + $perPage, $totalItems) ?></strong>
        of <strong><?= $totalItems ?></strong>
      </div>
    </nav>
    <?php endif; ?>

    <?php endif; // end else (lessons found) ?>

  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
