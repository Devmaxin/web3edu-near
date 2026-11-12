<?php
/**
 * Web3edu — Guide Lesson Page
 * Route: /guides/{guideId}/lesson/{lessonId}
 * → .htaccess rewrites to: /guides/lesson.php?guide_id=X&id=Y
 *
 * This page renders the SAME lesson content as /learn/lesson.php
 * but with GUIDE context:
 *   - Prev/Next follow guide_path_lessons.sort_order, NOT the lesson row's own sort_order
 *   - Displays guide title, step X of Y, guide progress bar
 *   - Back link returns to the guide overview page
 *   - Progress completion works identically (shared user_lesson_progress table)
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/video_helpers.php';
require_once __DIR__ . '/../includes/gamification.php';
require_once __DIR__ . '/../includes/lesson_renderer.php';
require_once __DIR__ . '/../includes/ai_study_card.php';
require_once __DIR__ . '/../includes/notes_highlights.php';
require_once __DIR__ . '/../includes/projects.php';

// ── Route params ──────────────────────────────────────────────────────────────
$guideId  = intval($_GET['guide_id'] ?? 0);
$lessonId = intval($_GET['id'] ?? 0);

if (!$guideId || !$lessonId) {
    header('Location: ' . SITE_URL . '/guide.php'); exit;
}

$db = getDB();

// ── Load the guide ─────────────────────────────────────────────────────────────
$guideStmt = $db->prepare("SELECT * FROM guide_paths WHERE id = ? AND is_active = 1");
$guideStmt->execute([$guideId]);
$guide = $guideStmt->fetch();
if (!$guide) {
    header('Location: ' . SITE_URL . '/guide.php'); exit;
}

// ── Load ALL lessons in this guide (ordered by sort_order) ──────────────────
$guideSeqStmt = $db->prepare("
    SELECT l.id, l.title, l.video_id, l.thumbnail, l.access_level, l.sort_order AS lesson_sort_order,
           l.duration, l.category, gpl.sort_order
    FROM   guide_path_lessons gpl
    INNER JOIN lessons l ON l.id = gpl.lesson_id
    WHERE  gpl.guide_id = ?
    ORDER  BY gpl.sort_order ASC
");
$guideSeqStmt->execute([$guideId]);
$guideSequence = $guideSeqStmt->fetchAll();

if (empty($guideSequence)) {
    header('Location: ' . SITE_URL . '/guide.php?guide=' . $guideId); exit;
}

// ── Find current lesson's position within guide sequence ───────────────────
$currentStep = null;
$currentIdx  = null;
foreach ($guideSequence as $i => $s) {
    if ((int)$s['id'] === $lessonId) {
        $currentStep = $s;
        $currentIdx  = $i;
        break;
    }
}

// Lesson not part of this guide — redirect to guide overview
if ($currentStep === null) {
    header('Location: ' . SITE_URL . '/guide.php?guide=' . $guideId); exit;
}

// ── Guide-aware prev/next ──────────────────────────────────────────────────
$prevGuideLesson = ($currentIdx > 0)
    ? $guideSequence[$currentIdx - 1]
    : null;

$nextGuideLesson = ($currentIdx < count($guideSequence) - 1)
    ? $guideSequence[$currentIdx + 1]
    : null;

$stepNumber = $currentIdx + 1;
$totalSteps = count($guideSequence);

// ── Load full lesson content ───────────────────────────────────────────────
$lessonStmt = $db->prepare("SELECT * FROM lessons WHERE id = ?");
$lessonStmt->execute([$lessonId]);
$lesson = $lessonStmt->fetch();
if (!$lesson) {
    header('Location: ' . SITE_URL . '/guide.php?guide=' . $guideId); exit;
}

$isPremiumLesson = $lesson['access_level'] === 'premium';

// ── Access control ─────────────────────────────────────────────────────────
if (!isLoggedIn()) {
    $_SESSION['redirect_after_login'] = SITE_URL . '/guides/' . $guideId . '/lesson/' . $lessonId;
    setFlash('info', 'Sign in to access this lesson.');
    header('Location: ' . SITE_URL . '/login.php'); exit;
}

requireProfileComplete();

if ($isPremiumLesson) {
    $isLocked = !userHasActivePremium((int)$_SESSION['user_id']);
} else {
    $isLocked = false;
}

if (empty($isLocked)) {
    Analytics::track('lesson_started', (int)$_SESSION['user_id'], [
        'lesson_id' => $lessonId,
        'guide_id'  => $guideId,
    ]);
    if ($currentIdx === 0) {
        // trackOnce — the first-step lesson page is revisited every time a
        // learner returns to a guide (prev/next nav, resuming later), but
        // "guide_started" in the activation funnel should count a guide once
        // per user, not once per visit to its first lesson.
        Analytics::trackOnce('guide_started', (int)$_SESSION['user_id'], ['guide_id' => $guideId], 'guide_id');
    }
}

// ── Guide progress ─────────────────────────────────────────────────────────
$guideProgress = getGuideProgress($guideId);

// ── Per-lesson completion state ────────────────────────────────────────────
$lessonAlreadyDone = isLoggedIn() ? isLessonCompleted($lesson['id']) : false;

// ── Related lessons (same category, same guide preferred) ─────────────────
$relatedStmt = $db->prepare("
    SELECT l.id, l.title, l.video_id, l.access_level, l.duration, l.category, l.thumbnail
    FROM   guide_path_lessons gpl
    INNER JOIN lessons l ON l.id = gpl.lesson_id
    WHERE  gpl.guide_id = ? AND l.category = ? AND l.id != ?
    ORDER  BY gpl.sort_order ASC LIMIT 4
");
$relatedStmt->execute([$guideId, $lesson['category'], $lessonId]);
$related = $relatedStmt->fetchAll();

$hasVideo = !empty($lesson['video_id']);
$hasAudio = !empty($lesson['audio_url']) && !$isLocked;
$audioSrc = $hasAudio ? UPLOAD_URL . htmlspecialchars($lesson['audio_url']) : null;
$pageTitle = $lesson['title'] . ' — ' . sanitize($guide['title']);
$extraCss = [SITE_URL . '/assets/css/study-card.css', SITE_URL . '/assets/css/lesson-reading.css', SITE_URL . '/assets/css/lesson-engagement.css'];
$extraJs  = [SITE_URL . '/assets/js/lesson-engagement.js'];

// ── Phase 2: private notes/highlights (rendered by the shared partial) +
// related Guides/Projects (rendered by the shared related_content partial) —
// both partials pull $lesson/$guideId/$userId directly from this scope.
$userId = (int)($_SESSION['user_id'] ?? 0);

// ── Helper: build guide lesson URL (defined globally in includes/config.php) ──
// guideLessonUrl(int $guideId, int $lessonId): string

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Thumbnail fallback -->
<script>
function ytThumbFallback(img, hq, mq) {
  if (img.dataset.fallback === 'hq') { img.dataset.fallback = 'mq'; img.src = mq; }
  else if (!img.dataset.fallback)    { img.dataset.fallback = 'hq'; img.src = hq; }
}
</script>

<style>
/* ── Guide context banner ─────────────────────────────────────────────── */
.guide-context-bar {
    background: linear-gradient(90deg, var(--accent-soft), rgba(11,121,88,0.05));
    border-bottom: 1px solid rgba(11,121,88,0.2);
    padding: 10px 0;
    position: sticky;
    top: 72px;
    z-index: 100;
    backdrop-filter: blur(8px);
}
.guide-context-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}
.guide-ctx-left {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}
.guide-ctx-title {
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--accent-bright);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 240px;
}
.guide-ctx-step {
    font-size: 0.78rem;
    color: var(--text-muted);
    white-space: nowrap;
    flex-shrink: 0;
}
.guide-progress-mini {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}
.guide-progress-bar {
    width: 80px;
    height: 5px;
    background: var(--border);
    border-radius: 99px;
    overflow: hidden;
}
.guide-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--accent), var(--cyan));
    border-radius: 99px;
    transition: width 0.5s ease;
}

/* ── Step navigator (Prev / Next Guide Step) ─────────────────────────── */
.guide-step-nav {
    display: flex;
    gap: 10px;
    margin-top: 44px;
    flex-wrap: wrap;
}
.guide-nav-card {
    flex: 1;
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 15px 18px;
    background: var(--bg-card);
    border: 1.5px solid var(--border);
    border-radius: var(--radius-lg);
    text-decoration: none;
    transition: border-color 0.2s, background 0.2s, transform 0.15s;
    position: relative;
}
.guide-nav-card:hover {
    border-color: var(--accent);
    background: var(--glow-soft, rgba(11,121,88,0.06));
    transform: translateY(-1px);
}
.guide-nav-card.next { justify-content: flex-end; text-align: right; }
.guide-nav-card .nav-label {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--accent-bright);
    margin-bottom: 3px;
    display: flex;
    align-items: center;
    gap: 5px;
}
.guide-nav-card.next .nav-label { justify-content: flex-end; }
.guide-nav-card .nav-title {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 200px;
}
.guide-nav-card .nav-step {
    font-size: 0.72rem;
    color: var(--text-muted);
    margin-top: 1px;
}
.guide-nav-finish {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 15px 18px;
    background: rgba(11,121,88,0.06);
    border: 1.5px solid rgba(11,121,88,0.25);
    border-radius: var(--radius-lg);
    text-decoration: none;
    transition: all 0.2s;
    color: #0B7958;
    font-weight: 600;
    font-size: 0.9rem;
}
.guide-nav-finish:hover {
    background: rgba(11,121,88,0.1);
    border-color: rgba(11,121,88,0.5);
}
.guide-nav-arrow {
    flex-shrink: 0;
    color: var(--text-muted);
}

/* ── Step breadcrumb dots ─────────────────────────────────────────────── */
.guide-step-dots {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    margin-top: 20px;
    flex-wrap: wrap;
}
.guide-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: var(--border);
    transition: background 0.2s, transform 0.2s;
    flex-shrink: 0;
}
.guide-dot.done  { background: rgba(11,121,88,0.6); }
.guide-dot.current {
    background: var(--accent);
    transform: scale(1.4);
}

/* ── Layout responsive ────────────────────────────────────────────────── */
@media (max-width: 900px) {
    .lesson-layout, .lesson-hero-grid { grid-template-columns: 1fr !important; gap: 24px !important; }
    .lesson-layout > *, .lesson-hero-grid > * { min-width: 0; }
}
@media (max-width: 768px) { .lesson-hero-grid div:last-child { display: none; } }
@media (max-width: 600px) {
    .guide-context-bar { top: 60px; }
    .guide-ctx-title   { max-width: 130px; }
    .guide-progress-bar { width: 50px; }
    .guide-nav-card .nav-title { max-width: 130px; }
}

/* Audio players */
.audio-player-desktop { display: block; }
.audio-player-mobile  { display: none;  }
@media (max-width: 480px) {
    .audio-player-desktop { display: none !important; }
    .audio-player-mobile  { display: block !important; margin-bottom: 20px; }
    .audio-player-mobile button#audioPlayBtnMobile {
        display: flex !important; align-items: center; justify-content: center;
        width: 40px; height: 40px; padding: 0;
        background: var(--accent); border: none; border-radius: 50%;
        cursor: pointer; box-shadow: 0 0 14px rgba(11,121,88,0.4);
        transition: background 0.2s, transform 0.15s;
    }
    .mobile-audio-icon { display: inline-flex; align-items: center; }
    #completeLessonWrap { bottom: 16px; right: 12px; left: 12px; }
    #completeLessonBtn  { width: 100%; justify-content: center; }
    .lesson-done-chip   { width: 100%; justify-content: center; }
}
</style>

<!-- ── Guide Context Bar (sticky, shows guide name + step + progress) ── -->
<div class="guide-context-bar">
  <div class="container">
    <div class="guide-context-inner">
      <div class="guide-ctx-left">
        <!-- Back to guide overview -->
        <a href="<?= SITE_URL ?>/guide.php?guide=<?= $guideId ?>"
           title="Back to <?= sanitize($guide['title']) ?>"
           style="color:var(--text-muted);display:flex;align-items:center;gap:4px;text-decoration:none;font-size:0.78rem;flex-shrink:0;transition:color 0.2s"
           onmouseover="this.style.color='var(--accent-bright)'" onmouseout="this.style.color='var(--text-muted)'">
          <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
          Guide
        </a>
        <span style="color:var(--border);flex-shrink:0">·</span>
        <div class="guide-ctx-title" title="<?= sanitize($guide['title']) ?>">
          <?= sanitize($guide['title']) ?>
        </div>
        <div class="guide-ctx-step">Step <?= $stepNumber ?> of <?= $totalSteps ?></div>
      </div>

      <!-- Mini progress bar -->
      <div class="guide-progress-mini">
        <div class="guide-progress-bar">
          <div class="guide-progress-fill" style="width:<?= $guideProgress['pct'] ?>%"></div>
        </div>
        <span style="font-size:0.72rem;color:var(--text-muted);font-weight:600"><?= $guideProgress['pct'] ?>%</span>
      </div>
    </div>
  </div>
</div>

<!-- ── Lesson Hero ── -->
<div class="lesson-hero" style="background:linear-gradient(180deg,rgba(11,121,88,0.07),transparent)">
  <div class="container">
    <!-- Breadcrumb -->
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:20px;flex-wrap:wrap">
      <a href="<?= SITE_URL ?>/guide.php?guide=<?= $guideId ?>"
         style="color:var(--text-muted);font-size:0.875rem;display:inline-flex;align-items:center;gap:5px;text-decoration:none;transition:color 0.2s"
         onmouseover="this.style.color='var(--accent-bright)'" onmouseout="this.style.color='var(--text-muted)'">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        <?= sanitize($guide['title']) ?>
      </a>
      <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--border)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
      <span style="font-size:0.875rem;color:var(--text-muted)">Step <?= $stepNumber ?></span>
    </div>

    <div style="display:grid;grid-template-columns:1fr auto;gap:40px;align-items:flex-start" class="lesson-hero-grid">
      <div style="min-width:0">
        <!-- Badges row -->
        <div style="display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px">
          <!-- Guide step badge -->
          <span style="display:inline-flex;align-items:center;gap:5px;font-size:0.78rem;color:var(--accent-bright);background:rgba(11,121,88,0.1);padding:4px 10px;border-radius:20px;border:1px solid rgba(11,121,88,0.25);font-weight:700">
            <svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            Step <?= $stepNumber ?>/<?= $totalSteps ?>
          </span>

          <span class="lesson-badge <?= $isPremiumLesson?'badge-premium':'badge-free' ?>" style="position:static;display:inline-flex;align-items:center;gap:5px">
            <?php if ($isPremiumLesson): ?>
            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            Premium
            <?php else: ?>
            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            Free
            <?php endif; ?>
          </span>

          <?php if ($hasVideo): ?>
          <span style="display:inline-flex;align-items:center;gap:5px;font-size:0.78rem;color:#f87171;background:rgba(248,113,113,0.1);padding:4px 10px;border-radius:20px;border:1px solid rgba(248,113,113,0.25)">
            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="5 3 19 12 5 21 5 3"/></svg>
            Video Lesson
          </span>
          <?php endif; ?>

          <?php if (!empty($lesson['audio_url'])): ?>
          <span style="display:inline-flex;align-items:center;gap:5px;font-size:0.78rem;color:var(--accent-bright);background:rgba(11,121,88,0.1);padding:4px 10px;border-radius:20px;border:1px solid rgba(11,121,88,0.25)">
            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
            Audio
          </span>
          <?php endif; ?>

          <span style="color:var(--text-muted);font-size:0.82rem;display:flex;align-items:center;gap:4px">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <?= sanitize($lesson['duration'] ?? '') ?>
          </span>
        </div>

        <h1 class="lesson-page-title"><?= sanitize($lesson['title']) ?></h1>
        <p style="color:var(--text-muted);max-width:640px;font-size:1rem;line-height:1.7">
          <?= sanitize(substr($lesson['preview_content'] ?? '', 0, 200)) ?>…
        </p>
      </div>

      <?php if (!$hasVideo && !empty($lesson['thumbnail'])): ?>
      <div style="flex-shrink:0;max-width:100%">
        <img src="<?= imageUrl($lesson['thumbnail'],'lesson') ?>"
             alt="<?= sanitize($lesson['title']) ?>"
             style="width:min(280px,100%);height:180px;object-fit:cover;border-radius:var(--radius-lg);border:1.5px solid var(--border);box-shadow:var(--shadow-glow);display:block">
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── Main lesson body ── -->
<div class="section-sm">
  <div class="container">
    <div style="display:grid;grid-template-columns:1fr 320px;gap:40px;align-items:flex-start" class="lesson-layout">

      <!-- Main content -->
      <div style="min-width:0">
        <?php if (!$isLocked): ?>

          <?php if ($hasAudio): ?>
          <!-- Full audio player (desktop) -->
          <div id="lessonAudioPlayer" class="audio-player-desktop" style="background:var(--bg-card);border:1.5px solid rgba(11,121,88,0.35);border-radius:var(--radius-lg);padding:20px 24px;margin-bottom:28px;box-shadow:0 0 24px rgba(11,121,88,0.08)">
            <div style="display:flex;align-items:center;gap:16px">
              <button id="audioPlayBtn" onclick="toggleAudio()"
                style="width:46px;height:46px;border-radius:50%;background:var(--accent);border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all 0.2s;box-shadow:0 0 18px rgba(11,121,88,0.4)"
                onmouseover="this.style.background='var(--accent-bright)';this.style.transform='scale(1.06)'"
                onmouseout="this.style.background='var(--accent)';this.style.transform='scale(1)'">
                <svg id="iconPlay" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="white" stroke="none"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                <svg id="iconPause" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="white" stroke="none" style="display:none"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
              </button>
              <div style="flex:1;min-width:0">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                  <span style="font-size:0.78rem;font-weight:700;color:var(--accent-bright);text-transform:uppercase;letter-spacing:0.05em">Audio Lesson</span>
                  <div style="font-size:0.75rem;color:var(--text-muted);font-family:var(--font-mono)">
                    <span id="audioCurrentTime">0:00</span> / <span id="audioDuration">--:--</span>
                  </div>
                </div>
                <div id="audioProgressBar" style="width:100%;height:5px;background:var(--border);border-radius:3px;cursor:pointer;position:relative;overflow:hidden" onclick="seekAudio(event)">
                  <div id="audioProgressFill" style="height:100%;width:0%;background:linear-gradient(90deg,var(--accent),var(--accent-bright));border-radius:3px;transition:width 0.15s linear;pointer-events:none"></div>
                </div>
                <div id="audioLoading" style="display:none;font-size:0.72rem;color:var(--text-muted);margin-top:6px">Loading audio…</div>
              </div>
            </div>
          </div>

          <!-- Compact button (mobile) -->
          <div class="audio-player-mobile">
            <button id="audioPlayBtnMobile" onclick="toggleAudio()" aria-label="Play / Pause audio lesson">
              <span class="mobile-audio-icon mobile-icon-play">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="white" stroke="none"><polygon points="5 3 19 12 5 21 5 3"/></svg>
              </span>
              <span class="mobile-audio-icon mobile-icon-pause" style="display:none">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="white" stroke="none"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
              </span>
            </button>
          </div>
          <audio id="lessonAudio" preload="none" src="<?= $audioSrc ?>"></audio>
          <?php endif; ?>

          <?php if ($hasVideo): ?>
          <div style="position:relative;width:100%;padding-bottom:56.25%;border-radius:var(--radius-lg);overflow:hidden;margin-bottom:32px;border:1.5px solid var(--border);box-shadow:var(--shadow-glow)">
            <iframe src="<?= youtubeEmbedUrl($lesson['video_id']) ?>"
                    title="<?= sanitize($lesson['title']) ?>"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                    allowfullscreen loading="lazy"
                    style="position:absolute;top:0;left:0;width:100%;height:100%;border:none"></iframe>
          </div>
          <?php endif; ?>

          <!-- ── Text-to-Speech Controls ── -->
          <div id="tts-controls" style="display:flex;align-items:center;gap:10px;margin-bottom:20px;flex-wrap:wrap">
            <button id="tts-play-btn"
              class="tts-play-btn"
              onclick="ttsPlay()"
              style="display:inline-flex;align-items:center;gap:8px;padding:9px 18px;background:var(--bg-card);border:1.5px solid var(--border);border-radius:50px;cursor:pointer;font-size:0.82rem;font-weight:600;color:var(--text);transition:all 0.2s">
              <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
              <span id="tts-play-label"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;margin-right:3px"><rect x="9" y="9" width="6" height="6"/><path d="M5 9v6l4-2z"/></svg> Play Lesson</span>
            </button>
            <button id="tts-stop-btn"
              class="tts-stop-btn"
              onclick="ttsStop()"
              style="display:inline-flex;align-items:center;gap:8px;padding:9px 18px;background:rgba(248,113,113,0.08);border:1.5px solid rgba(248,113,113,0.3);border-radius:50px;cursor:pointer;font-size:0.82rem;font-weight:600;color:#f87171;transition:all 0.2s">
              <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="currentColor" stroke="none"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
              ⏹ Stop
            </button>
            <span id="tts-status"
              class="tts-status-el"
              style="font-size:0.75rem;color:var(--text-muted)">
              <span style="display:inline-block;width:6px;height:6px;background:var(--accent-bright);border-radius:50%;margin-right:5px;animation:ttsPulse 1s ease-in-out infinite"></span>
              Speaking…
            </span>
          </div>

          <!-- Full lesson content -->
          <div id="lesson-content" class="lesson-content-body lesson-content-scroll">
            <?php render_lesson_content($lesson); ?>
          </div>

          <?php include __DIR__ . '/../includes/partials/lesson_engagement.php'; ?>

          <?php render_ai_study_card($lesson['id'], 'guide'); ?>

          <!-- ═══════════════════════════════════════════════════════════════
               GUIDE NAVIGATION — Prev / Next guide steps
               These NEVER follow the lesson row's own sort_order — only guide_path_lessons.sort_order.
               ═══════════════════════════════════════════════════════════════ -->
          <div class="guide-step-nav">

            <!-- Previous guide step -->
            <?php if ($prevGuideLesson): ?>
            <?php $prevLocked = $prevGuideLesson['access_level']==='premium' && !isPremium(); ?>
            <a href="<?= $prevLocked ? SITE_URL.'/upgrade.php' : guideLessonUrl($guideId, $prevGuideLesson['id']) ?>"
               data-fallback="<?= $prevLocked ? '' : guideLessonDirectUrl($guideId, $prevGuideLesson['id']) ?>"
               class="guide-nav-card prev guide-lesson-link">
              <div class="guide-nav-arrow">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
              </div>
              <div style="min-width:0">
                <div class="nav-label">
                  <svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                  Previous Step
                </div>
                <div class="nav-title"><?= sanitize($prevGuideLesson['title']) ?></div>
                <div class="nav-step">Step <?= $currentIdx ?> of <?= $totalSteps ?><?= $prevLocked ? ' <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:3px"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>' : '' ?></div>
              </div>
            </a>
            <?php else: ?>
            <!-- First lesson — back to guide overview -->
            <a href="<?= SITE_URL ?>/guide.php?guide=<?= $guideId ?>" class="guide-nav-card prev">
              <div class="guide-nav-arrow">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
              </div>
              <div style="min-width:0">
                <div class="nav-label">Guide Overview</div>
                <div class="nav-title"><?= sanitize($guide['title']) ?></div>
              </div>
            </a>
            <?php endif; ?>

            <!-- Next guide step -->
            <?php if ($nextGuideLesson): ?>
            <?php $nextLocked = $nextGuideLesson['access_level']==='premium' && !isPremium(); ?>
            <a href="<?= $nextLocked ? SITE_URL.'/upgrade.php' : guideLessonUrl($guideId, $nextGuideLesson['id']) ?>"
               data-fallback="<?= $nextLocked ? '' : guideLessonDirectUrl($guideId, $nextGuideLesson['id']) ?>"
               class="guide-nav-card next guide-lesson-link">
              <div style="min-width:0;text-align:right">
                <div class="nav-label">
                  Next Step
                  <svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                </div>
                <div class="nav-title"><?= sanitize($nextGuideLesson['title']) ?></div>
                <div class="nav-step">Step <?= $stepNumber + 1 ?> of <?= $totalSteps ?><?= $nextLocked ? ' <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:3px"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>' : '' ?></div>
              </div>
              <div class="guide-nav-arrow">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
              </div>
            </a>
            <?php else: ?>
            <!-- Last lesson — link back to guide for quiz -->
            <a href="<?= SITE_URL ?>/guide.php?guide=<?= $guideId ?>" class="guide-nav-finish">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
              <div>
                <div style="font-size:0.72rem;margin-bottom:2px;opacity:0.75">Guide Complete!</div>
                <div>Back to <?= sanitize($guide['title']) ?></div>
              </div>
            </a>
            <?php endif; ?>

          </div><!-- /guide-step-nav -->

          <!-- Step progress dots -->
          <div class="guide-step-dots">
            <?php foreach ($guideSequence as $si => $sl):
              $dotDone = isLoggedIn() && isLessonCompleted($sl['id']);
              $dotClass = ((int)$sl['id'] === $lessonId) ? 'current' : ($dotDone ? 'done' : '');
            ?>
            <a href="<?= guideLessonDirectUrl($guideId, $sl['id']) ?>"
               class="guide-dot <?= $dotClass ?>"
               title="Step <?= $si+1 ?>: <?= sanitize($sl['title']) ?>"
               style="text-decoration:none"></a>
            <?php endforeach; ?>
          </div>

        <?php else: ?>
          <!-- LOCKED: premium upgrade wall -->
          <?php if (!empty($lesson['audio_url'])): ?>
          <div style="display:flex;align-items:center;gap:14px;padding:16px 20px;background:rgba(11,121,88,0.06);border:1.5px solid rgba(11,121,88,0.2);border-radius:var(--radius-lg);margin-bottom:24px">
            <div style="width:44px;height:44px;border-radius:50%;background:rgba(11,121,88,0.15);display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent-bright)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </div>
            <div>
              <div style="font-size:0.85rem;font-weight:700;color:var(--accent-bright)">Audio narration included</div>
              <div style="font-size:0.78rem;color:var(--text-muted)">Upgrade to unlock the audio lesson for this topic.</div>
            </div>
          </div>
          <?php endif; ?>

          <?php if ($hasVideo): ?>
          <div style="position:relative;width:100%;padding-bottom:56.25%;border-radius:var(--radius-lg);overflow:hidden;margin-bottom:32px;border:1.5px solid var(--border)">
            <img src="<?= youtubeThumbnailUrl($lesson['video_id'], 'maxresdefault') ?>" alt=""
                 loading="lazy"
                 style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;filter:blur(6px) brightness(0.4)"
                 onerror="ytThumbFallback(this,'<?= youtubeThumbnailUrl($lesson['video_id'],'hqdefault') ?>','<?= youtubeThumbnailUrl($lesson['video_id'],'mqdefault') ?>')">
            <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px">
              <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--accent-bright)"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <span style="color:#fff;font-size:0.9rem;font-weight:600">Premium video locked</span>
            </div>
          </div>
          <?php endif; ?>

          <div class="lesson-content-body">
            <p><?= nl2br(sanitize($lesson['preview_content'] ?? '')) ?></p>
          </div>

          <div class="content-lock">
            <div class="content-blur">
              <div style="padding:40px;background:var(--bg-card);border-radius:var(--radius-lg)">
                <h2>Continue Watching…</h2>
                <p>This premium lesson includes the full video, detailed notes, and practical exercises.</p>
              </div>
            </div>
            <div class="lock-overlay">
              <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--accent-bright);margin-bottom:12px"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <div class="lock-title">Premium Content Locked</div>
              <div class="lock-subtitle">Upgrade for full access to all premium lessons in this guide and beyond.</div>
              <a href="<?= SITE_URL ?>/upgrade.php" class="btn-upgrade btn-lg">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                Upgrade — <?= getPremiumPriceDisplay() ?>
              </a>
            </div>
          </div>
        <?php endif; ?>
      </div><!-- /main col -->

      <!-- ── Sidebar ── -->
      <aside style="min-width:0">

        <!-- Guide overview card -->
        <div style="background:var(--bg-card);border:1.5px solid rgba(11,121,88,0.2);border-radius:var(--radius-lg);padding:20px;margin-bottom:18px;position:sticky;top:130px">
          <div style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--accent-bright);margin-bottom:10px;display:flex;align-items:center;gap:6px">
            <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            Guide Progress
          </div>

          <!-- Guide title + description -->
          <div style="font-weight:700;font-size:0.9rem;margin-bottom:4px"><?= sanitize($guide['title']) ?></div>
          <?php if (!empty($guide['description'])): ?>
          <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:12px;line-height:1.5"><?= sanitize($guide['description']) ?></div>
          <?php endif; ?>

          <!-- Progress bar -->
          <div style="background:var(--border);border-radius:99px;height:6px;overflow:hidden;margin-bottom:6px">
            <div style="width:<?= $guideProgress['pct'] ?>%;height:100%;border-radius:99px;background:linear-gradient(90deg,var(--accent),var(--cyan));transition:width 0.5s"></div>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:0.78rem;color:var(--text-muted);margin-bottom:16px">
            <span><?= $guideProgress['completed'] ?>/<?= $guideProgress['total'] ?> completed</span>
            <span style="font-weight:700;color:var(--accent-bright)"><?= $guideProgress['pct'] ?>%</span>
          </div>

          <!-- Guide lesson list -->
          <div style="display:flex;flex-direction:column;gap:2px">
          <?php foreach ($guideSequence as $si => $sl):
            $slDone   = isLoggedIn() && isLessonCompleted($sl['id']);
            $slActive = (int)$sl['id'] === $lessonId;
            $slLocked = $sl['access_level']==='premium' && !isPremium();
          ?>
          <a href="<?= $slLocked ? SITE_URL.'/upgrade.php' : guideLessonDirectUrl($guideId, $sl['id']) ?>"
             style="display:flex;align-items:center;gap:8px;padding:7px 8px;border-radius:var(--radius);text-decoration:none;transition:background 0.15s;<?= $slActive ? 'background:rgba(11,121,88,0.12);' : '' ?>"
             onmouseover="this.style.background='rgba(11,121,88,0.08)'" onmouseout="this.style.background='<?= $slActive ? 'rgba(11,121,88,0.12)' : 'transparent' ?>'">
            <!-- Step indicator -->
            <div style="width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:0.65rem;font-weight:700;<?= $slDone ? 'background:rgba(11,121,88,0.15);color:#0B7958' : ($slActive ? 'background:rgba(11,121,88,0.2);color:var(--accent-bright)' : 'background:var(--bg-card2);color:var(--text-muted);border:1px solid var(--border)') ?>">
              <?php if ($slDone): ?>
              <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
              <?php else: ?>
              <?= $si + 1 ?>
              <?php endif; ?>
            </div>
            <div style="min-width:0;flex:1">
              <div style="font-size:0.78rem;font-weight:<?= $slActive?'700':'500' ?>;color:<?= $slActive?'var(--text)':'var(--text-muted)' ?>;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                <?= sanitize($sl['title']) ?><?= $slLocked ? ' <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:3px"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>' : '' ?>
              </div>
              <div style="font-size:0.66rem;color:var(--text-muted)"><?= sanitize($sl['duration'] ?? '') ?></div>
            </div>
          </a>
          <?php endforeach; ?>
          </div>

          <a href="<?= SITE_URL ?>/guide.php?guide=<?= $guideId ?>"
             style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:14px;padding:8px;border-radius:var(--radius);background:rgba(11,121,88,0.06);border:1px solid rgba(11,121,88,0.15);color:var(--accent-bright);font-size:0.78rem;font-weight:600;text-decoration:none;transition:all 0.2s"
             onmouseover="this.style.background='rgba(11,121,88,0.12)'" onmouseout="this.style.background='rgba(11,121,88,0.06)'">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
            Guide Overview
          </a>
        </div>

        <!-- Lesson meta -->
        <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);padding:20px">
          <h3 style="font-size:0.8rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);margin-bottom:14px">Lesson Info</h3>
          <?php $meta = [
            ['Category', sanitize($lesson['category'])],
            ['Duration',  sanitize($lesson['duration'] ?? '')],
            ['Access',    $isPremiumLesson ? '<span style="color:var(--accent-bright)">Premium</span>' : '<span style="color:var(--success)">Free</span>'],
            ['Format',    implode(' &nbsp;', array_filter([
              $hasVideo ? '<span style="color:#f87171">▶ Video</span>' : null,
              !empty($lesson['audio_url']) ? '<span style="color:var(--accent-bright)">♫ Audio</span>' : null,
              (!$hasVideo && empty($lesson['audio_url'])) ? 'Text' : null,
            ]))],
          ];
          foreach ($meta as [$label, $val]): ?>
          <div style="display:flex;justify-content:space-between;font-size:0.84rem;padding:7px 0;border-bottom:1px solid var(--border-subtle)">
            <span style="color:var(--text-muted)"><?= $label ?></span>
            <span><?= $val ?></span>
          </div>
          <?php endforeach; ?>
          <?php if ($isLocked): ?>
          <a href="<?= SITE_URL ?>/upgrade.php" class="btn-upgrade" style="width:100%;justify-content:center;padding:10px;margin-top:14px;display:flex">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
            Unlock Premium
          </a>
          <?php endif; ?>
        </div>

      </aside><!-- /sidebar -->
    </div><!-- /lesson-layout -->
  </div>
</div>

<?php if ($hasAudio): ?>
<script>
(function() {
  const audio    = document.getElementById('lessonAudio');
  const iconPlay = document.getElementById('iconPlay');
  const iconPause= document.getElementById('iconPause');
  const fill     = document.getElementById('audioProgressFill');
  const curTime  = document.getElementById('audioCurrentTime');
  const durEl    = document.getElementById('audioDuration');
  const loading  = document.getElementById('audioLoading');
  function fmt(s) { if (!isFinite(s)) return '--:--'; const m=Math.floor(s/60),sc=String(Math.floor(s%60)).padStart(2,'0'); return m+':'+sc; }
  audio.addEventListener('loadedmetadata', () => { durEl.textContent=fmt(audio.duration); loading.style.display='none'; });
  audio.addEventListener('timeupdate', () => { if(!audio.duration) return; fill.style.width=(audio.currentTime/audio.duration*100)+'%'; curTime.textContent=fmt(audio.currentTime); });
  audio.addEventListener('waiting', () => loading.style.display='block');
  audio.addEventListener('playing', () => loading.style.display='none');
  audio.addEventListener('ended',   () => setPlayState(false));
  function setPlayState(p) {
    iconPlay.style.display=p?'none':'block'; iconPause.style.display=p?'block':'none';
    const mp=document.querySelector('.mobile-icon-play'), mps=document.querySelector('.mobile-icon-pause');
    if(mp) mp.style.display=p?'none':'inline-flex'; if(mps) mps.style.display=p?'inline-flex':'none';
  }
  window.toggleAudio=function(){ if(audio.paused){ audio.play(); setPlayState(true); loading.style.display='block'; } else { audio.pause(); setPlayState(false); } };
  window.seekAudio=function(e){ const b=document.getElementById('audioProgressBar'),r=b.getBoundingClientRect(),p=Math.max(0,Math.min(1,(e.clientX-r.left)/r.width)); if(audio.duration) audio.currentTime=p*audio.duration; };
  window.addEventListener('beforeunload',()=>audio.pause());
  document.addEventListener('visibilitychange',()=>{ if(document.visibilityState==='hidden') audio.pause(); });
})();
</script>
<?php endif; ?>

<?php if (!$isLocked && isLoggedIn()): ?>
<!-- ── Gamification: Mark as Complete button ── -->
<style>
#completeLessonWrap { position:fixed;bottom:24px;right:20px;z-index:900; }
@media (max-width: 768px) {
    /* Bound the control between both edges (instead of an unconstrained
       right-anchored box) so a long "Read the lesson to continue" pill
       can never grow left into the AI Tutor FAB's column, and lift the
       FAB itself clear of this row as a second safety net. */
    #completeLessonWrap {
        left: 16px;
        right: 16px;
        bottom: calc(16px + env(safe-area-inset-bottom, 0px));
        display: flex;
        justify-content: flex-end;
    }
    #aiFabBtn { bottom: calc(88px + env(safe-area-inset-bottom, 0px)) !important; }
}
#completeLessonBtn {
    display:inline-flex;align-items:center;gap:9px;padding:13px 26px;border-radius:50px;border:none;cursor:pointer;
    background:#0B7958;color:#ffffff;font-size:0.9rem;font-weight:700;letter-spacing:0.02em;
    box-shadow:0 4px 24px rgba(124,58,237,0.55),0 0 0 2px rgba(255,255,255,0.12);
    transition:transform 0.15s,box-shadow 0.15s,background 0.15s;text-shadow:0 1px 2px rgba(0,0,0,0.3);
}
#completeLessonBtn:hover:not(:disabled) { background:#0B7958;transform:translateY(-2px);box-shadow:0 8px 32px rgba(11,121,88,0.65),0 0 0 2px rgba(255,255,255,0.18); }
#completeLessonBtn:disabled { opacity:0.7;cursor:not-allowed; }
.lesson-done-chip { display:inline-flex;align-items:center;gap:8px;padding:11px 22px;border-radius:50px;background:rgba(11,121,88,0.15);border:1.5px solid rgba(11,121,88,0.4);color:#0B7958;font-size:0.88rem;font-weight:700;box-shadow:0 4px 20px rgba(0,0,0,0.25); }
.gm-overlay { display:none;position:fixed;inset:0;background:rgba(0,0,0,0.78);z-index:9999;align-items:center;justify-content:center;padding:16px; }
.gm-overlay.show { display:flex; }
.gm-box { background:var(--bg-card);border:1.5px solid rgba(11,121,88,0.4);border-radius:var(--radius-lg);padding:36px 28px;text-align:center;width:100%;max-width:380px;box-shadow:0 0 60px rgba(11,121,88,0.25);animation:gmPop 0.38s cubic-bezier(0.34,1.56,0.64,1); }
@keyframes gmPop { from{transform:scale(0.65);opacity:0} to{transform:scale(1);opacity:1} }
.gm-badge-icon { font-size:4rem;display:block;margin-bottom:12px;animation:badgeSpin 0.65s cubic-bezier(0.34,1.56,0.64,1); }
@keyframes badgeSpin { from{transform:rotate(-200deg) scale(0.3)} to{transform:rotate(0deg) scale(1)} }
.confetti-piece { position:fixed;pointer-events:none;z-index:10000;border-radius:2px;animation:confettiFall linear forwards; }
@keyframes confettiFall { 0%{transform:translateY(-10px) rotate(0deg);opacity:1} 100%{transform:translateY(110vh) rotate(720deg);opacity:0} }
</style>

<div id="completeLessonWrap">
<?php if ($lessonAlreadyDone): ?>
  <div class="lesson-done-chip">
    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
    Lesson Completed
  </div>
<?php else: ?>
  <div id="readingHint" style="display:inline-flex;align-items:center;gap:8px;padding:11px 20px;border-radius:50px;background:rgba(124,58,237,0.12);border:1.5px solid rgba(124,58,237,0.35);color:var(--text-muted);font-size:0.85rem;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,0.18);transition:opacity 0.4s">
    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="7 13 12 18 17 13"/><polyline points="7 6 12 11 17 6"/></svg>
    Read the lesson to continue
  </div>
  <button id="completeLessonBtn" onclick="completeLessonAction(<?= $lesson['id'] ?>)" style="display:none" aria-label="Mark lesson as complete">
    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
    Mark as Complete
  </button>
<?php endif; ?>
</div>

<!-- Badge modal -->
<div class="gm-overlay" id="badgeModal">
  <div class="gm-box">
    <span class="gm-badge-icon" id="bmIcon"></span>
    <p style="color:var(--accent-bright);font-size:0.72rem;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;margin-bottom:6px">Badge Earned!</p>
    <h2 id="bmName" style="font-size:1.35rem;font-weight:800;margin-bottom:8px"></h2>
    <p id="bmDesc" style="color:var(--text-muted);font-size:0.875rem;margin-bottom:24px;line-height:1.5"></p>
    <!-- After badge, offer to go to next guide step if available -->
    <div style="display:flex;flex-direction:column;gap:8px">
      <button onclick="closeBadgeModal()" style="width:100%;padding:12px;border:none;border-radius:var(--radius);background:#0B7958;color:#fff;font-weight:700;font-size:0.95rem;cursor:pointer">Awesome! <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></button>
      <?php if ($nextGuideLesson && !($nextGuideLesson['access_level']==='premium' && !isPremium())): ?>
      <a href="<?= guideLessonUrl($guideId, $nextGuideLesson['id']) ?>"
         data-fallback="<?= guideLessonDirectUrl($guideId, $nextGuideLesson['id']) ?>"
         class="guide-lesson-link"
         style="display:block;width:100%;padding:11px;border:1.5px solid var(--border);border-radius:var(--radius);color:var(--text-muted);font-weight:600;font-size:0.9rem;text-align:center;text-decoration:none">
        Next Step →
      </a>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
// Scroll-to-read gate
(function() {
    if (!document.getElementById('completeLessonBtn')) return;
    const btn=document.getElementById('completeLessonBtn'), hint=document.getElementById('readingHint');
    function reveal() {
        if (btn.style.display !== 'none') return;
        if (hint) { hint.style.opacity='0'; setTimeout(()=>{ if(hint) hint.style.display='none'; },400); }
        btn.style.display='inline-flex'; btn.style.opacity='0'; btn.style.transform='translateY(8px)';
        btn.style.transition='opacity 0.4s,transform 0.4s';
        requestAnimationFrame(()=>requestAnimationFrame(()=>{ btn.style.opacity='1'; btn.style.transform='translateY(0)'; }));
    }
    const content=document.querySelector('.lesson-content-body');
    if (!content) { reveal(); return; }

    // On desktop, the lesson content scrolls inside its own fixed-height
    // container (see lesson-reading.css). On mobile/tablet that container
    // expands naturally and the whole page scrolls instead.
    function isInternalScrollActive() {
        return content.scrollHeight > content.clientHeight + 4
            && window.getComputedStyle(content).overflowY !== 'visible';
    }

    function check() {
        if (isInternalScrollActive()) {
            const nearBottom = content.scrollTop + content.clientHeight >= content.scrollHeight - 40;
            if (nearBottom) reveal();
            return;
        }
        const rect=content.getBoundingClientRect(), ch=content.offsetHeight, vh=window.innerHeight;
        if (ch <= vh*1.1 || rect.bottom <= vh+120) reveal();
    }
    check();
    window.addEventListener('scroll',check,{passive:true});
    content.addEventListener('scroll',check,{passive:true});
    window.addEventListener('resize',check,{passive:true});
})();

// Complete lesson
function completeLessonAction(lessonId) {
    const btn=document.getElementById('completeLessonBtn');
    if (!btn||btn.disabled) return;
    btn.disabled=true;
    btn.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Saving…';
    const form=new FormData();
    form.append('lesson_id', lessonId);
    // Pass guide context so API can check quiz unlock for THIS guide
    form.append('guide_id', <?= $guideId ?>);
    fetch('<?= SITE_URL ?>/api/complete_lesson.php', {method:'POST',body:form})
        .then(r=>r.json())
        .then(d=>{
            if (!d.success) { btn.disabled=false; btn.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Mark as Complete'; return; }
            // Update the dot for this step to "done"
            const dots=document.querySelectorAll('.guide-dot');
            if (dots[<?= $currentIdx ?>]) { dots[<?= $currentIdx ?>].classList.remove('current'); dots[<?= $currentIdx ?>].classList.add('done'); }
            // Update progress bar
            if (d.guide_progress) {
                document.querySelectorAll('.guide-progress-fill').forEach(el=>el.style.width=d.guide_progress.pct+'%');
            }
            document.getElementById('completeLessonWrap').innerHTML='<div class="lesson-done-chip"><svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Lesson Completed</div>';
            if (d.new_badges && d.new_badges.length) {
                setTimeout(()=>showBadgeModal(d.new_badges[0].icon,d.new_badges[0].name,d.new_badges[0].desc),600);
            }
        })
        .catch(()=>{ btn.disabled=false; btn.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Mark as Complete'; });
}

function showBadgeModal(icon,name,desc) {
    document.getElementById('bmIcon').innerHTML=icon||'<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
    document.getElementById('bmName').textContent=name;
    document.getElementById('bmDesc').textContent=desc;
    document.getElementById('badgeModal').classList.add('show');
    launchConfetti();
}
function closeBadgeModal() { document.getElementById('badgeModal').classList.remove('show'); }
function launchConfetti() {
    const colors=['#0B7958','#0B7958','#22d3ee','#0B7958','#fbbf24','#f87171'];
    for(let i=0;i<45;i++) setTimeout(()=>{
        const el=document.createElement('div'); el.className='confetti-piece';
        const sz=6+Math.random()*7;
        el.style.cssText='left:'+Math.random()*100+'vw;top:-10px;background:'+colors[Math.floor(Math.random()*colors.length)]+';animation-duration:'+(1.5+Math.random()*2.5)+'s;animation-delay:'+Math.random()*0.5+'s;width:'+sz+'px;height:'+sz+'px;border-radius:'+(Math.random()>0.5?'50%':'3px')+';';
        document.body.appendChild(el); setTimeout(()=>el.remove(),5000);
    }, i*28);
}
document.getElementById('badgeModal').addEventListener('click',function(e){ if(e.target===this) this.classList.remove('show'); });

// ── Text-to-Speech (Web Speech API) ──────────────────────────────────────────
(function initTTS() {
    // ── 1. API guard ─────────────────────────────────────────────────────────
    if (!('speechSynthesis' in window)) {
        const ctrl = document.getElementById('tts-controls');
        if (ctrl) ctrl.style.display = 'none';
        return;
    }

    // ── 2. Inject styles once ────────────────────────────────────────────────
    if (!document.getElementById('tts-style')) {
        const s = document.createElement('style');
        s.id = 'tts-style';
        s.textContent = `
            @keyframes ttsPulse {
                0%,100% { opacity:1; transform:scale(1); }
                50%      { opacity:0.3; transform:scale(0.7); }
            }
            #tts-controls .tts-stop-btn  { display:inline-flex; }
            #tts-controls .tts-play-btn  { display:inline-flex; }
            #tts-controls .tts-status-el { display:none; }

            #tts-controls.tts-playing .tts-play-btn  { opacity:0.5; pointer-events:none; }
            #tts-controls.tts-playing .tts-status-el { display:inline-flex; }

            #tts-controls.tts-idle .tts-play-btn  { opacity:1; pointer-events:auto; }
            #tts-controls.tts-idle .tts-status-el { display:none; }

            .tts-sentence { border-radius:3px; transition:background 0.15s; }
            .tts-sentence.tts-highlight {
                background: rgba(11,121,88,0.22);
                outline: 1px solid rgba(11,121,88,0.35);
            }
        `;
        document.head.appendChild(s);
    }

    // ── 3. Voice loading (Chrome Android requires onvoiceschanged) ────────────
    let _voices = [];
    function loadVoices() {
        const v = window.speechSynthesis.getVoices();
        if (v.length) _voices = v;
    }
    loadVoices();
    if (window.speechSynthesis.onvoiceschanged !== undefined) {
        window.speechSynthesis.onvoiceschanged = loadVoices;
    }
    function pickVoice(lang) {
        return (
            _voices.find(v => v.lang === lang) ||
            _voices.find(v => v.lang.startsWith('en')) ||
            null
        );
    }

    // ── 4. Sentence span builder (enables per-sentence highlighting) ──────────
    let _sentenceMap = [];
    let _rawText     = '';

    function buildSentenceSpans() {
        const content = document.getElementById('lesson-content');
        if (!content) return;
        content.querySelectorAll('.tts-sentence').forEach(el => {
            el.replaceWith(document.createTextNode(el.textContent));
        });
        content.normalize();
        _rawText     = content.innerText.trim();
        _sentenceMap = [];
        const sentenceRe = /[^.!?\n]+(?:[.!?]+(?:\s|$)|\n|$)/g;
        let charOffset   = 0;

        function processNode(node) {
            if (node.nodeType === Node.TEXT_NODE) {
                const text = node.textContent;
                if (!text.trim()) return;
                const frag = document.createDocumentFragment();
                let match, lastIdx = 0;
                sentenceRe.lastIndex = 0;
                while ((match = sentenceRe.exec(text)) !== null) {
                    if (match.index > lastIdx) {
                        frag.appendChild(document.createTextNode(text.slice(lastIdx, match.index)));
                    }
                    const span      = document.createElement('span');
                    span.className  = 'tts-sentence';
                    span.textContent = match[0];
                    frag.appendChild(span);
                    _sentenceMap.push({ el: span, start: charOffset + match.index, end: charOffset + match.index + match[0].length });
                    lastIdx = match.index + match[0].length;
                }
                if (lastIdx < text.length) {
                    frag.appendChild(document.createTextNode(text.slice(lastIdx)));
                }
                charOffset += text.length;
                node.parentNode.replaceChild(frag, node);
            } else if (node.nodeType === Node.ELEMENT_NODE && node.tagName !== 'SCRIPT' && node.tagName !== 'STYLE') {
                Array.from(node.childNodes).forEach(processNode);
            }
        }
        Array.from(content.childNodes).forEach(processNode);
    }

    function highlightSentenceAt(charPos) {
        _sentenceMap.forEach(s => s.el.classList.remove('tts-highlight'));
        const match = _sentenceMap.find(s => charPos >= s.start && charPos < s.end);
        if (match) {
            match.el.classList.add('tts-highlight');
            match.el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }

    function clearHighlights() {
        _sentenceMap.forEach(s => s.el.classList.remove('tts-highlight'));
    }

    // ── 5. Chunk builder (prevents Chrome Android 15s cut-off) ───────────────
    const MAX_CHUNK = 200;
    function buildChunks(text) {
        const chunks = [];
        const parts  = text.match(/[^.!?\n]+(?:[.!?]+(?:\s|$)|\n|$)/g) || [text];
        let   buf    = '';
        parts.forEach(part => {
            if ((buf + part).length > MAX_CHUNK && buf) { chunks.push(buf.trim()); buf = part; }
            else { buf += part; }
        });
        if (buf.trim()) chunks.push(buf.trim());
        return chunks.length ? chunks : [text];
    }

    // ── 6. State ─────────────────────────────────────────────────────────────
    window._ttsActive = false;
    let _chunks = [], _chunkIdx = 0, _chunkOffset = 0;

    // ── 7. UI state helpers ───────────────────────────────────────────────────
    function setPlayingUI() {
        const c = document.getElementById('tts-controls');
        if (c) { c.classList.remove('tts-idle'); c.classList.add('tts-playing'); }
        const label = document.getElementById('tts-play-label');
        if (label) label.textContent = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:3px"><rect x="9" y="9" width="6" height="6"/><path d="M5 9v6l4-2z"/></svg> Playing…';
    }
    function setIdleUI() {
        const c = document.getElementById('tts-controls');
        if (c) { c.classList.remove('tts-playing'); c.classList.add('tts-idle'); }
        const label = document.getElementById('tts-play-label');
        if (label) label.textContent = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:3px"><rect x="9" y="9" width="6" height="6"/><path d="M5 9v6l4-2z"/></svg> Play Lesson';
    }

    // ── 8. Chunk player ───────────────────────────────────────────────────────
    function speakChunk(idx) {
        if (!window._ttsActive || idx >= _chunks.length) { ttsResetUI(); return; }
        const utter  = new SpeechSynthesisUtterance(_chunks[idx]);
        utter.lang   = 'en-US';
        utter.rate   = 1;
        utter.pitch  = 1;
        const voice  = pickVoice('en-US');
        if (voice) utter.voice = voice;
        utter.onboundary = function (e) {
            if (e.name !== 'word' && e.name !== 'sentence') return;
            highlightSentenceAt(_chunkOffset + e.charIndex);
        };
        utter.onend = function () {
            if (!window._ttsActive) return;
            _chunkOffset += _chunks[idx].length + 1;
            speakChunk(++_chunkIdx);
        };
        utter.onerror = function (e) {
            if (e.error === 'interrupted') return;
            ttsResetUI();
        };
        window._ttsUtterance = utter;
        window.speechSynthesis.speak(utter);
    }

    // ── 9. Public API ─────────────────────────────────────────────────────────
    window.ttsPlay = function () {
        const content = document.getElementById('lesson-content');
        if (!content) return;
        window.speechSynthesis.cancel();
        clearHighlights();
        buildSentenceSpans();
        if (!_rawText) return;
        _chunks      = buildChunks(_rawText);
        _chunkIdx    = 0;
        _chunkOffset = 0;
        window._ttsActive = true;
        setPlayingUI();
        if (!_voices.length) { const v = window.speechSynthesis.getVoices(); if (v.length) _voices = v; }
        speakChunk(0);
    };

    window.ttsStop = function () {
        window._ttsActive = false;
        window.speechSynthesis.cancel();
        ttsResetUI();
    };

    function ttsResetUI() {
        window._ttsActive = false;
        clearHighlights();
        setIdleUI();
    }

    // ── 10. Page-leave isolation ──────────────────────────────────────────────
    window.addEventListener('beforeunload', function () {
        window._ttsActive = false;
        window.speechSynthesis.cancel();
    });
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') window.ttsStop();
    });

    setIdleUI();
})();
// ─────────────────────────────────────────────────────────────────────────────
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/partials/related_content.php'; ?>

<script>
  // Lets the AI Tutor (popup + page) know what the user is currently reading,
  // so answers can be grounded in this lesson/guide first. No UI impact.
  window.__WEB3EDU_LESSON_CONTEXT__ = { lesson_id: <?= (int)$lesson['id'] ?>, lesson_type: 'guide', guide_id: <?= (int)$guideId ?> };
</script>
<script src="<?= SITE_URL ?>/assets/js/study-card.js"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script>
/**
 * Guide lesson navigation fallback.
 * Tries the pretty /guides/{id}/lesson/{id} URL first.
 * If the host returns 404 (mod_rewrite not active for this path),
 * redirects to the direct ?guide_id=X&id=Y query-string URL instead.
 */
(function () {
    document.querySelectorAll('a.guide-lesson-link').forEach(function (link) {
        link.addEventListener('click', function (e) {
            var pretty   = link.getAttribute('href');
            var fallback = link.getAttribute('data-fallback');
            if (!fallback || !pretty || pretty.indexOf('/upgrade.php') !== -1) return;

            e.preventDefault();
            fetch(pretty, { method: 'HEAD', redirect: 'follow' })
                .then(function (r) {
                    window.location.href = (r.ok || r.status !== 404) ? pretty : fallback;
                })
                .catch(function () {
                    window.location.href = pretty;
                });
        });
    });
})();
</script>
