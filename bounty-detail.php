<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/bounty_auth.php';
require_once __DIR__ . '/includes/countries.php';

$slug = trim($_GET['slug'] ?? '');
$bounty = $slug ? getBountyBySlug($slug) : null;

if (!$bounty || !in_array($bounty['status'], ['published','judging','winners_announced'], true)) {
    // Owners/admins can still preview their own non-published bounty
    $isOwnerPreview = false;
    if ($bounty && isLoggedIn()) {
        $isOwnerPreview = canManageBounty((int)$_SESSION['user_id'], (int)$bounty['id'])
                        || bountyAuthIsSubmitter((int)$_SESSION['user_id'], $bounty);
    }
    if (!$isOwnerPreview) {
        http_response_code(404);
        $bounty = null;
    }
}

$db = getDB();
if ($bounty) {
    $db->prepare("UPDATE bounties SET view_count = view_count + 1 WHERE id = ?")->execute([$bounty['id']]);
    Analytics::track('bounty_viewed', isLoggedIn() ? (int)$_SESSION['user_id'] : null, [
        'bounty_id' => (int)$bounty['id'], 'bounty_type' => $bounty['type'],
    ]);
}

$pageTitle = $bounty ? $bounty['title'] : 'Bounty Not Found';
$extraCss  = [SITE_URL . '/assets/css/airdrops.css', SITE_URL . '/assets/css/bounty.css'];
$extraJs   = [SITE_URL . '/assets/js/bookmark.js'];

$errors = []; $success = '';
$mySubmission = ($bounty && isLoggedIn()) ? getBountySubmission((int)$bounty['id'], (int)$_SESSION['user_id']) : null;

if ($bounty && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireLogin();
    requireCsrf();

    if (($_POST['action'] ?? '') === 'submit_entry' && $bounty['type'] !== 'external' && $bounty['status'] === 'published') {
        $url = trim($_POST['submission_url'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if (!userCanSubmitToBounty($bounty)) { $errors[] = 'This bounty is exclusive to Premium members.'; }
        elseif (!canSubmitToBounty((int)$_SESSION['user_id'], (int)$bounty['id'])) { $errors[] = 'You have already submitted to this bounty.'; }
        elseif (!$url) { $errors[] = 'Please provide a submission link.'; }
        elseif (!hasBountyProfile((int)$_SESSION['user_id'])) { $errors[] = 'Please complete your Bounty Profile first.'; }
        elseif (submitBountyEntry((int)$bounty['id'], (int)$_SESSION['user_id'], $url, $desc)) {
            $success = 'Your submission was received!';
            $mySubmission = getBountySubmission((int)$bounty['id'], (int)$_SESSION['user_id']);
        } else {
            $errors[] = 'You have already submitted to this bounty.';
        }
    }

    if (($_POST['action'] ?? '') === 'post_comment' && canCommentOnBounty((int)$_SESSION['user_id'], (int)$bounty['id'])) {
        $body = trim($_POST['body'] ?? '');
        if ($body !== '') {
            $db->prepare("INSERT INTO bounty_comments (bounty_id, user_id, body) VALUES (?,?,?)")->execute([$bounty['id'], $_SESSION['user_id'], $body]);
            header('Location: ' . SITE_URL . '/bounty-detail.php?slug=' . urlencode($slug) . '#comments');
            exit;
        }
    }
}

$comments = [];
$winners = [];
if ($bounty) {
    $cStmt = $db->prepare("
        SELECT c.*, u.username, u.avatar FROM bounty_comments c
        INNER JOIN users u ON u.id = c.user_id
        WHERE c.bounty_id = ? AND c.deleted_at IS NULL ORDER BY c.created_at ASC
    ");
    $cStmt->execute([$bounty['id']]);
    $comments = $cStmt->fetchAll();

    if ($bounty['status'] === 'winners_announced') {
        $wStmt = $db->prepare("
            SELECT s.*, u.username, u.avatar FROM bounty_submissions s
            INNER JOIN users u ON u.id = s.user_id
            WHERE s.bounty_id = ? AND s.status = 'winner' ORDER BY s.placement ASC
        ");
        $wStmt->execute([$bounty['id']]);
        $winners = $wStmt->fetchAll();
    }
}

require_once __DIR__ . '/includes/header.php';
if ($bounty) require_once __DIR__ . '/includes/bounty_profile_modal.php';
?>

<div class="container section">
<?php if (!$bounty): ?>
  <div class="learn-empty">
    <h3>Bounty not found</h3>
    <p>This bounty may have been removed or hasn't been published yet.</p>
    <a href="<?= SITE_URL ?>/bounty.php" class="btn btn-primary" style="margin-top:var(--space-3);display:inline-flex;align-items:center;gap:6px"><?= lucide('arrow-left', ['size' => 14]) ?> Back to Bounties</a>
  </div>
<?php else:
  $badge = bountyTypeBadge($bounty);
  $deadlineTs = $bounty['deadline'] ? strtotime($bounty['deadline']) : null;
  $skills = array_filter(array_map('trim', explode(',', $bounty['skills'] ?? '')));
?>

  <div class="bounty-detail-head" data-reveal>
    <img class="bounty-detail-logo" src="<?= htmlspecialchars(imageUrl($bounty['project_logo'] ?: $bounty['host_logo'], 'lesson')) ?>" alt="">
    <div style="flex:1;min-width:220px">
      <span class="bounty-badge <?= $badge['class'] ?>"><?= $badge['icon'] ?> <?= htmlspecialchars($badge['label']) ?></span>
      <?php if (bountyIsPremiumOnly($bounty)): ?><span class="bounty-badge bounty-badge-premium"><?= lucide('lock', ['size' => 12]) ?> Premium Only</span><?php endif; ?>
      <h1 class="page-header-title" style="margin:var(--space-2) 0"><?= htmlspecialchars($bounty['title']) ?></h1>
      <div style="color:var(--text-secondary);font-size:var(--text-sm)">
        <?= htmlspecialchars($bounty['project_name'] ?: $bounty['host_name'] ?: 'Web3Edu') ?>
        <?php if ($bounty['country']): ?> · <?= lucide('map-pin', ['size' => 12]) ?> <?= htmlspecialchars($bounty['country']) ?><?php else: ?> · <?= lucide('globe', ['size' => 12]) ?> Global<?php endif; ?>
      </div>
    </div>
    <button class="bookmark-btn <?= isBookmarked('bounty', (int)$bounty['id']) ? 'active' : '' ?>" data-type="bounty" data-id="<?= (int)$bounty['id'] ?>" title="Save for later">
      <?= lucide('bookmark', ['size' => 18, 'stroke_width' => 2]) ?>
      <?= (int)$bounty['bookmark_count'] ?>
    </button>
  </div>

  <div class="bounty-stat-strip" data-reveal>
    <div class="bounty-stat-box"><span class="value"><?= htmlspecialchars($bounty['reward_display'] ?: 'TBD') ?></span><span class="label">Reward</span></div>
    <div class="bounty-stat-box"><span class="value"><?= (int)$bounty['num_winners'] ?></span><span class="label">Winners</span></div>
    <div class="bounty-stat-box">
      <span class="value bounty-countdown" id="countdownVal" data-deadline="<?= $deadlineTs ? date('c', $deadlineTs) : '' ?>"><?= $deadlineTs ? '—' : 'No deadline' ?></span>
      <span class="label">Deadline</span>
    </div>
    <div class="bounty-stat-box"><span class="value"><?= (int)$bounty['submission_count'] ?></span><span class="label">Submissions</span></div>
    <div class="bounty-stat-box"><span class="value"><?= htmlspecialchars($bounty['category_name'] ?: '—') ?></span><span class="label">Category</span></div>
    <div class="bounty-stat-box"><span class="value"><?= htmlspecialchars($bounty['difficulty']) ?></span><span class="label">Difficulty</span></div>
  </div>

  <div class="grid-2" style="align-items:flex-start" data-reveal>
    <div>
      <div class="card">
        <h2 style="font-size:var(--text-lg)">About this bounty</h2>
        <div style="color:var(--text-secondary);white-space:pre-wrap;font-size:var(--text-sm)"><?= nl2br(htmlspecialchars($bounty['description'] ?? '')) ?></div>

        <?php if (!empty($bounty['requirements'])): ?>
          <h3 style="font-size:var(--text-base);margin-top:var(--space-5)">Requirements</h3>
          <div style="color:var(--text-secondary);white-space:pre-wrap;font-size:var(--text-sm)"><?= nl2br(htmlspecialchars($bounty['requirements'])) ?></div>
        <?php endif; ?>

        <?php if (!empty($skills)): ?>
          <h3 style="font-size:var(--text-base);margin-top:var(--space-5)">Skills needed</h3>
          <div class="skills-row"><?php foreach ($skills as $s): ?><span class="skill-chip"><?= htmlspecialchars($s) ?></span><?php endforeach; ?></div>
        <?php endif; ?>

        <?php if (!empty($bounty['winner_announcement_date'])): ?>
          <p style="font-size:var(--text-xs);color:var(--text-muted);margin-top:var(--space-5)">
            Winners will be announced by <?= date('F j, Y', strtotime($bounty['winner_announcement_date'])) ?>.
          </p>
        <?php endif; ?>
      </div>

      <!-- ── Comments ── -->
      <div class="card" style="margin-top:var(--space-5)" id="comments">
        <h2 style="font-size:var(--text-lg)"><?= count($comments) ?> Comments</h2>
        <?php if (isLoggedIn()): ?>
        <form method="POST" style="display:flex;gap:var(--space-2);margin-bottom:var(--space-4)">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="post_comment">
          <input type="text" name="body" class="form-input" placeholder="Write a comment…" maxlength="1000" required>
          <button type="submit" class="btn btn-secondary btn-sm">Post</button>
        </form>
        <?php endif; ?>
        <?php foreach ($comments as $c): ?>
          <div class="bounty-comment">
            <img class="bounty-comment-avatar" src="<?= htmlspecialchars(imageUrl($c['avatar'], 'avatar')) ?>" alt="">
            <div>
              <div class="bounty-comment-meta"><strong><?= htmlspecialchars($c['username']) ?></strong> · <?= date('M j, Y', strtotime($c['created_at'])) ?></div>
              <div class="bounty-comment-body"><?= nl2br(htmlspecialchars($c['body'])) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($comments)): ?><p style="color:var(--text-muted);font-size:var(--text-sm)">No comments yet — be the first!</p><?php endif; ?>
      </div>
    </div>

    <!-- ── Right: action panel ── -->
    <div class="card" style="position:sticky;top:100px">
      <?php if ($bounty['status'] === 'winners_announced' && !empty($winners)): ?>
        <h2 style="font-size:var(--text-lg);text-align:center;display:flex;align-items:center;justify-content:center;gap:8px"><?= lucide('trophy', ['size' => 18]) ?> Winners Announced</h2>
        <div class="podium-row">
          <?php $medals = ['1'=>lucide('trophy',['size'=>14,'class'=>'medal-gold']),'2'=>lucide('medal',['size'=>14,'class'=>'medal-silver']),'3'=>lucide('medal',['size'=>14,'class'=>'medal-bronze'])]; foreach (array_slice($winners, 0, 3) as $w): ?>
            <div class="podium-item">
              <div class="podium-avatar-wrap">
                <img class="podium-avatar" src="<?= htmlspecialchars(imageUrl($w['avatar'], 'avatar')) ?>" alt="">
                <span class="podium-medal"><?= $medals[(string)$w['placement']] ?? lucide('award', ['size' => 14]) ?></span>
              </div>
              <div class="podium-name"><?= htmlspecialchars($w['username']) ?></div>
              <div class="podium-prize"><?= htmlspecialchars($w['prize_awarded'] ?? '') ?></div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if (count($winners) > 3): ?>
          <p style="text-align:center;font-size:var(--text-sm);color:var(--text-muted)">+<?= count($winners) - 3 ?> more winners</p>
          <ul style="font-size:var(--text-sm);color:var(--text-secondary)">
            <?php foreach (array_slice($winners, 3) as $w): ?>
              <li><?= htmlspecialchars($w['username']) ?> — <?= htmlspecialchars($w['prize_awarded'] ?? '') ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

      <?php elseif ($bounty['type'] === 'external' && $bounty['status'] === 'winners_announced'): ?>
        <h2 style="font-size:var(--text-lg);text-align:center;display:flex;align-items:center;justify-content:center;gap:8px"><?= lucide('trophy', ['size' => 18]) ?> Winners Announced</h2>
        <p style="color:var(--text-secondary);font-size:var(--text-sm);text-align:center">Winner(s) announced on the external platform.</p>
        <a href="<?= htmlspecialchars($bounty['external_url']) ?>" target="_blank" rel="noopener" class="btn btn-secondary" style="width:100%;justify-content:center;margin-top:var(--space-3);display:flex;align-items:center;gap:6px">View on <?= htmlspecialchars($bounty['external_platform']) ?> <?= lucide('external-link', ['size' => 14]) ?></a>

      <?php elseif ($bounty['type'] === 'external'): ?>
        <h2 style="font-size:var(--text-lg)">This bounty runs on <?= htmlspecialchars($bounty['external_platform']) ?></h2>
        <p style="color:var(--text-secondary);font-size:var(--text-sm)">Submissions happen on the original platform, not on Web3Edu.</p>
        <a href="<?= htmlspecialchars($bounty['external_url']) ?>" target="_blank" rel="noopener" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:var(--space-3);display:flex;align-items:center;gap:6px">Continue on Platform <?= lucide('external-link', ['size' => 14]) ?></a>

      <?php elseif ($mySubmission): ?>
        <h2 style="font-size:var(--text-lg)">Your submission</h2>
        <p style="font-size:var(--text-sm);color:var(--text-secondary)">Status: <span class="status-chip status-<?= $mySubmission['status'] === 'submitted' ? 'pending_review' : $mySubmission['status'] ?>"><?= ucfirst(str_replace('_',' ',$mySubmission['status'])) ?></span></p>
        <p style="font-size:var(--text-sm);word-break:break-all"><a href="<?= htmlspecialchars($mySubmission['submission_url']) ?>" target="_blank"><?= htmlspecialchars($mySubmission['submission_url']) ?></a></p>

      <?php elseif ($bounty['status'] === 'published' && bountyIsPremiumOnly($bounty) && !userCanSubmitToBounty($bounty)): ?>
        <div class="premium-lock-icon"><?= lucide('lock', ['size' => 22]) ?></div>
        <h2 style="font-size:var(--text-lg);text-align:center">Premium Members Only</h2>
        <p style="color:var(--text-secondary);font-size:var(--text-sm);text-align:center">This bounty is exclusive to Premium members. Upgrade your membership to participate and submit your work.</p>
        <a href="<?= SITE_URL ?>/upgrade.php" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:var(--space-3);display:flex;align-items:center;gap:6px"><?= lucide('zap', ['size' => 15]) ?> Upgrade to Premium</a>

      <?php elseif ($bounty['status'] === 'published'): ?>
        <h2 style="font-size:var(--text-lg)">Submit Entry</h2>
        <?php if (!empty($errors)): ?><div class="alert alert-danger"><?php foreach ($errors as $e) echo '<div>'.htmlspecialchars($e).'</div>'; ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if (isLoggedIn()): ?>
        <?php Analytics::trackOnce('bounty_submission_started', (int)$_SESSION['user_id'], ['bounty_id' => (int)$bounty['id']], 'bounty_id'); ?>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="submit_entry">
          <div class="form-group"><label class="form-label">Submission Link</label><input type="text" name="submission_url" class="form-input" placeholder="https://" required></div>
          <div class="form-group"><label class="form-label">Notes <span style="color:var(--text-muted);font-weight:400">(optional)</span></label><textarea name="description" class="form-input" rows="3"></textarea></div>
          <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">Submit Entry</button>
        </form>
        <?php else: ?>
          <a href="<?= SITE_URL ?>/login.php" class="btn btn-primary" style="width:100%;justify-content:center">Sign in to submit</a>
        <?php endif; ?>

      <?php else: ?>
        <p style="color:var(--text-muted);font-size:var(--text-sm)">This bounty isn't accepting submissions right now (<?= htmlspecialchars($bounty['status']) ?>).</p>
      <?php endif; ?>
    </div>
  </div>

<?php endif; ?>
</div>

<script>
const cd = document.getElementById('countdownVal');
if (cd && cd.dataset.deadline) {
  const target = new Date(cd.dataset.deadline).getTime();
  function tick() {
    const diff = target - Date.now();
    if (diff <= 0) { cd.textContent = 'Closed'; return; }
    const d = Math.floor(diff / 86400000), h = Math.floor(diff % 86400000 / 3600000), m = Math.floor(diff % 3600000 / 60000);
    cd.textContent = `${d}d ${h}h ${m}m`;
  }
  tick(); setInterval(tick, 60000);
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
