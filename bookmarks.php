<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/gamification.php';
require_once __DIR__ . '/includes/bounties.php';
requireLogin();
requireProfileComplete();

$pageTitle = 'My Bookmarks';
$extraCss  = [SITE_URL . '/assets/css/learn.css'];
$extraJs   = [SITE_URL . '/assets/js/bookmark.js'];
$db = getDB();
$userId = (int)$_SESSION['user_id'];

$stmt = $db->prepare("SELECT * FROM user_bookmarks WHERE user_id=? ORDER BY created_at DESC");
$stmt->execute([$userId]);
$bookmarks = $stmt->fetchAll();

// Resolve each polymorphic bookmark to its real content row, split by section.
$guideBookmarks  = [];
$lessonBookmarks = [];
$otherBookmarks  = [];

foreach ($bookmarks as $b) {
    $row = null;
    switch ($b['bookmarkable_type']) {
        case 'lesson':
            $s = $db->prepare("SELECT id, title, category AS description FROM lessons WHERE id=? AND deleted_at IS NULL");
            $s->execute([$b['bookmarkable_id']]); $row = $s->fetch();
            if ($row) {
                $row['url']  = SITE_URL . '/learn/lesson.php?id=' . $row['id'];
                $row['type'] = 'lesson';
                $lessonBookmarks[] = $row;
            }
            break;

        case 'guide_path':
            $s = $db->prepare("SELECT id, title, description, thumbnail FROM guide_paths WHERE id=? AND deleted_at IS NULL AND is_active=1");
            $s->execute([$b['bookmarkable_id']]); $row = $s->fetch();
            if ($row) {
                $countStmt = $db->prepare("SELECT COUNT(*) FROM guide_path_lessons WHERE guide_id=?");
                $countStmt->execute([$row['id']]);
                $row['lesson_count'] = (int)$countStmt->fetchColumn();
                $row['progress']     = getGuideProgress((int)$row['id']);
                $row['url']          = SITE_URL . '/guide.php?guide=' . $row['id'];
                $row['type']         = 'guide_path';
                $guideBookmarks[] = $row;
            }
            break;

        case 'airdrop':
            $s = $db->prepare("SELECT id, title, slug, description FROM airdrops WHERE id=? AND deleted_at IS NULL");
            $s->execute([$b['bookmarkable_id']]); $row = $s->fetch();
            if ($row) {
                $row['url']  = SITE_URL . '/airdrops/' . urlencode($row['slug']);
                $row['type'] = 'airdrop';
                $otherBookmarks[] = $row;
            }
            break;

        case 'blog_post':
            $s = $db->prepare("SELECT id, title, slug, excerpt AS description FROM blog_posts WHERE id=? AND deleted_at IS NULL");
            $s->execute([$b['bookmarkable_id']]); $row = $s->fetch();
            if ($row) {
                $row['url']  = SITE_URL . '/blog-post.php?slug=' . urlencode($row['slug']);
                $row['type'] = 'blog_post';
                $otherBookmarks[] = $row;
            }
            break;

        case 'project':
            $s = $db->prepare("SELECT id, title, description FROM projects WHERE id=? AND deleted_at IS NULL");
            $s->execute([$b['bookmarkable_id']]); $row = $s->fetch();
            if ($row) {
                $row['url']  = SITE_URL . '/projects/submit.php?project_id=' . $row['id'];
                $row['type'] = 'project';
                $otherBookmarks[] = $row;
            }
            break;

        case 'resource':
            $s = $db->prepare("SELECT id, title, description, url AS resource_url FROM resources WHERE id=? AND deleted_at IS NULL");
            $s->execute([$b['bookmarkable_id']]); $row = $s->fetch();
            if ($row) {
                $row['url']  = $row['resource_url'];
                $row['type'] = 'resource';
                $otherBookmarks[] = $row;
            }
            break;

        case 'opportunity':
            $s = $db->prepare("SELECT id, title, description FROM opportunities WHERE id=? AND deleted_at IS NULL");
            $s->execute([$b['bookmarkable_id']]); $row = $s->fetch();
            if ($row) {
                $row['url']  = SITE_URL . '/opportunity.php?id=' . $row['id'];
                $row['type'] = 'opportunity';
                $otherBookmarks[] = $row;
            }
            break;

        case 'bounty':
            // This case was missing entirely, which was the real cause of
            // "bookmarked bounties don't appear on the Bookmarks page" —
            // toggle_bookmark.php and the bounty_card/bounty-detail bookmark
            // buttons always worked (the row was saved to user_bookmarks
            // just fine), but this resolver had no branch for 'bounty', so
            // every bounty bookmark silently fell through and was dropped
            // before it ever reached a display list.
            $row = getBounty((int)$b['bookmarkable_id']);
            if ($row) {
                $badge = bountyTypeBadge($row);
                $row['description'] = $row['reward_display'] ? ($badge['label'] . ' · ' . $row['reward_display']) : $badge['label'];
                $row['url']  = SITE_URL . '/bounty-detail.php?slug=' . urlencode($row['slug']);
                $row['type'] = 'bounty';
                $otherBookmarks[] = $row;
            }
            break;
    }
}

$typeLabels = ['lesson' => 'Lesson', 'guide_path' => 'Guide', 'airdrop' => 'Airdrop', 'blog_post' => 'Blog Post', 'project' => 'Project', 'resource' => 'Resource', 'opportunity' => 'Opportunity', 'bounty' => 'Bounty'];

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header container">
  <div class="section-label">Saved for later</div>
  <h1 class="page-header-title">Bookmarks</h1>
</div>

<div class="container section" style="padding-top:0">

  <!-- ══════════════ GUIDES ══════════════ -->
  <h2 style="font-size:var(--text-lg);margin-bottom:var(--space-4)">Guides</h2>
  <?php if (empty($guideBookmarks)): ?>
    <div class="learn-empty bm-section">
      <h3>No bookmarked guides yet</h3>
      <p>Tap the bookmark icon on any guide to save it here.</p>
    </div>
  <?php else: ?>
    <div class="bm-section">
      <?php foreach ($guideBookmarks as $g): ?>
        <div class="card guide-row card-interactive">
          <?php if (!empty($g['thumbnail'])): ?>
            <img src="<?= imageUrl($g['thumbnail'], 'lesson') ?>" alt="<?= sanitize($g['title']) ?>"
                 class="guide-row-thumb" style="object-fit:cover">
          <?php else: ?>
            <div class="guide-row-thumb"><?= strtoupper(substr($g['title'], 0, 2)) ?></div>
          <?php endif; ?>

          <div class="guide-row-body">
            <h3 style="font-size:var(--text-lg);margin:0"><?= htmlspecialchars($g['title']) ?></h3>
            <?php if (!empty($g['description'])): ?>
              <p style="font-size:var(--text-sm);color:var(--text-muted);margin-top:var(--space-1)"><?= htmlspecialchars(substr($g['description'], 0, 100)) ?></p>
            <?php endif; ?>
            <div class="guide-row-meta">
              <span><?= (int)$g['lesson_count'] ?> lessons</span>
            </div>
            <?php if (!empty($g['progress']['completed'])): ?>
            <div class="guide-row-progress">
              <div class="progress-track"><div class="progress-fill" style="width:<?= (int)$g['progress']['pct'] ?>%"></div></div>
              <span class="mono" style="font-size:var(--text-xs);color:var(--text-muted)"><?= (int)$g['progress']['completed'] ?>/<?= (int)$g['lesson_count'] ?></span>
            </div>
            <?php endif; ?>
          </div>

          <button class="bookmark-btn active" data-type="guide_path" data-id="<?= (int)$g['id'] ?>"
                  aria-pressed="true" title="Remove bookmark"
                  onclick="this.closest('.guide-row').style.opacity='0.4'">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
          </button>

          <a href="<?= $g['url'] ?>" class="guide-row-action">
            <?= !empty($g['progress']['completed']) ? 'Continue Learning' : 'Start Learning' ?> →
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- ══════════════ LESSONS ══════════════ -->
  <h2 style="font-size:var(--text-lg);margin-bottom:var(--space-4)">Lessons</h2>
  <?php if (empty($lessonBookmarks)): ?>
    <div class="learn-empty bm-section">
      <h3>No bookmarked lessons yet</h3>
      <p>Tap the bookmark icon on any lesson to save it here.</p>
    </div>
  <?php else: ?>
    <div class="grid-3 bm-section">
      <?php foreach ($lessonBookmarks as $r): ?>
        <div class="card card-interactive">
          <span class="badge badge-accent"><?= $typeLabels[$r['type']] ?></span>
          <h3 style="font-size:var(--text-lg);margin:var(--space-2) 0"><?= htmlspecialchars($r['title']) ?></h3>
          <?php if (!empty($r['description'])): ?><p style="font-size:var(--text-sm)"><?= htmlspecialchars(substr($r['description'], 0, 100)) ?>…</p><?php endif; ?>
          <div class="content-card-footer">
            <button class="bookmark-btn active" data-type="<?= $r['type'] ?>" data-id="<?= $r['id'] ?>" aria-pressed="true" title="Remove bookmark" onclick="this.closest('.card').style.opacity='0.4'">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
            </button>
            <a href="<?= $r['url'] ?>" class="btn btn-primary btn-sm">View →</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- ══════════════ OTHER BOOKMARKS (airdrops, blog posts) ══════════════ -->
  <?php if (!empty($otherBookmarks)): ?>
  <h2 style="font-size:var(--text-lg);margin-bottom:var(--space-4)">Other Bookmarks</h2>
  <div class="grid-3">
    <?php foreach ($otherBookmarks as $r): ?>
      <div class="card card-interactive">
        <span class="badge badge-accent"><?= $typeLabels[$r['type']] ?></span>
        <h3 style="font-size:var(--text-lg);margin:var(--space-2) 0"><?= htmlspecialchars($r['title']) ?></h3>
        <?php if (!empty($r['description'])): ?><p style="font-size:var(--text-sm)"><?= htmlspecialchars(substr($r['description'], 0, 100)) ?>…</p><?php endif; ?>
        <div class="content-card-footer">
          <button class="bookmark-btn active" data-type="<?= $r['type'] ?>" data-id="<?= $r['id'] ?>" aria-pressed="true" title="Remove bookmark" onclick="this.closest('.card').style.opacity='0.4'">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
          </button>
          <a href="<?= $r['url'] ?>" class="btn btn-primary btn-sm">View →</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
