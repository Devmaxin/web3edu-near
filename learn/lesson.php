<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/video_helpers.php';
require_once __DIR__ . '/../includes/gamification.php';
require_once __DIR__ . '/../includes/lesson_renderer.php';
require_once __DIR__ . '/../includes/ai_study_card.php';
require_once __DIR__ . '/../includes/notes_highlights.php';
require_once __DIR__ . '/../includes/projects.php';

// Accept /learn/lesson/ID
$lessonId = intval($_GET['id'] ?? 0);
if (!$lessonId) {
    header('Location: ' . SITE_URL . '/learn.php'); exit;
}

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM lessons WHERE id = ?");
$stmt->execute([$lessonId]);
$lesson = $stmt->fetch();
if (!$lesson) {
    header('Location: ' . SITE_URL . '/learn.php'); exit;
}

$isPremiumLesson = $lesson['access_level'] === 'premium';

// ── ACCESS CONTROL — enforced on backend ─────────────────────────────────────
if ($isPremiumLesson) {
    if (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = SITE_URL . '/learn/lesson/' . $lessonId;
        setFlash('info', 'Sign in to access this premium lesson.');
        header('Location: ' . SITE_URL . '/login.php'); exit;
    }
    // Re-verify from DB, not just session, to prevent session tampering
    $hasPremiumAccess = userHasActivePremium((int)$_SESSION['user_id']);
    if (!$hasPremiumAccess) {
        // Not premium — show upgrade wall
        $isLocked = true;
    } else {
        $isLocked = false;
    }
} else {
    // Free lesson — require login only
    if (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = SITE_URL . '/learn/lesson/' . $lessonId;
        setFlash('info', 'Sign in to watch this lesson.');
        header('Location: ' . SITE_URL . '/login.php'); exit;
    }
    $isLocked = false;
}

requireProfileComplete();

if (empty($isLocked)) {
    Analytics::track('lesson_started', (int)$_SESSION['user_id'], [
        'lesson_id'    => $lessonId,
        'category_id'  => $lesson['video_category_id'] ?? null,
        'access_level' => $lesson['access_level'],
    ]);
}

// ── Previous / Next navigation ────────────────────────────────────────────────
$pos = intval($lesson['sort_order']);
$prevLesson = null;
$nextLesson = null;

if ($pos > 0) {
    // Previous: highest sort_order < current
    $stmt = $db->prepare("SELECT id, title, video_id, thumbnail, access_level, sort_order FROM lessons WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1");
    $stmt->execute([$pos]);
    $prevLesson = $stmt->fetch() ?: null;

    // Next: lowest sort_order > current
    $stmt = $db->prepare("SELECT id, title, video_id, thumbnail, access_level, sort_order FROM lessons WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1");
    $stmt->execute([$pos]);
    $nextLesson = $stmt->fetch() ?: null;
}

// Related lessons (same category, exclude current)
$stmt = $db->prepare("SELECT id,title,video_id,access_level,duration,category,thumbnail FROM lessons WHERE category=? AND id!=? ORDER BY sort_order ASC LIMIT 4");
$stmt->execute([$lesson['category'], $lesson['id']]);
$related = $stmt->fetchAll();

$hasVideo = !empty($lesson['video_id']);
$hasAudio = !empty($lesson['audio_url']) && !($isLocked ?? false);
$audioSrc = $hasAudio ? UPLOAD_URL . htmlspecialchars($lesson['audio_url']) : null;
$pageTitle = $lesson['title'];
$extraCss = [SITE_URL . '/assets/css/study-card.css', SITE_URL . '/assets/css/lesson-reading.css', SITE_URL . '/assets/css/lesson-engagement.css'];
$extraJs = [SITE_URL . '/assets/js/lesson-engagement.js'];

// ── Phase 2: private notes/highlights + related content (shared partials) ──
$userId = (int)($_SESSION['user_id'] ?? 0);
$guideId = null; // standalone lesson viewer has no guide context

// Gamification: completion state for this lesson
$lessonAlreadyDone = isLoggedIn() ? isLessonCompleted($lesson['id']) : false;

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Thumbnail fallback JS -->
<script>
function ytThumbFallback(img, hq, mq) {
  if (img.dataset.fallback === 'hq') {
    img.dataset.fallback = 'mq';
    img.src = mq;
  } else if (!img.dataset.fallback) {
    img.dataset.fallback = 'hq';
    img.src = hq;
  }
}
</script>

<!-- ── Lesson Hero ── -->
<div class="lesson-hero" style="background:linear-gradient(180deg,rgba(139,92,246,0.07),transparent)">
  <div class="container">
    <a href="<?= SITE_URL ?>/learn.php" style="color:var(--text-muted);font-size:0.875rem;display:inline-flex;align-items:center;gap:6px;margin-bottom:20px;transition:color 0.2s" onmouseover="this.style.color='var(--primary-bright)'" onmouseout="this.style.color='var(--text-muted)'">
      <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
      Back to Lessons
    </a>

    <div style="display:grid;grid-template-columns:1fr auto;gap:40px;align-items:flex-start" class="lesson-hero-grid">
      <div style="min-width:0">
        <!-- Badges row -->
        <div style="display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px">
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
          <span style="display:inline-flex;align-items:center;gap:5px;font-size:0.78rem;color:var(--primary-bright);background:rgba(139,92,246,0.1);padding:4px 10px;border-radius:20px;border:1px solid rgba(139,92,246,0.25)">
            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
            Audio
          </span>
          <?php endif; ?>
          <span style="color:var(--text-muted);font-size:0.82rem;display:flex;align-items:center;gap:4px">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
            <?= sanitize($lesson['category']) ?>
          </span>
          <span style="color:var(--text-muted);font-size:0.82rem;display:flex;align-items:center;gap:4px">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <?= sanitize($lesson['duration'] ?? '') ?>
          </span>
          <?php if ($lesson['sort_order']): ?>
          <span style="color:var(--text-muted);font-size:0.78rem;font-family:var(--font-mono)">Lesson #<?= intval($lesson['sort_order']) ?></span>
          <?php endif; ?>
        </div>

        <h1 class="lesson-page-title"><?= sanitize($lesson['title']) ?></h1>
        <p style="color:var(--text-muted);max-width:640px;font-size:1rem;line-height:1.7">
          <?= sanitize(substr($lesson['preview_content'] ?? '', 0, 200)) ?>…
        </p>
      </div>

      <!-- Hero thumbnail (only if no video — video player will show below) -->
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

<div class="section-sm">
  <div class="container">
    <div style="display:grid;grid-template-columns:1fr 320px;gap:40px;align-items:flex-start" class="lesson-layout">

      <!-- ── Main content ── -->
      <div style="min-width:0">
        <?php if (!$isLocked): ?>

          <?php if ($hasAudio): ?>
          <!-- ── Custom Audio Player (desktop: full UI, mobile: compact button) ── -->

          <!-- DESKTOP full player — hidden on mobile via CSS -->
          <div id="lessonAudioPlayer" class="audio-player-desktop" style="background:var(--bg-card);border:1.5px solid rgba(139,92,246,0.35);border-radius:var(--radius-lg);padding:20px 24px;margin-bottom:28px;box-shadow:0 0 24px rgba(139,92,246,0.08)">
            <div style="display:flex;align-items:center;gap:16px">
              <!-- Play/Pause button -->
              <button id="audioPlayBtn"
                onclick="toggleAudio()"
                style="width:46px;height:46px;border-radius:50%;background:var(--primary);border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all 0.2s;box-shadow:0 0 18px rgba(139,92,246,0.4)"
                onmouseover="this.style.background='var(--primary-bright)';this.style.transform='scale(1.06)'"
                onmouseout="this.style.background='var(--primary)';this.style.transform='scale(1)'">
                <!-- Play icon (default) -->
                <svg id="iconPlay" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="white" stroke="none"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                <!-- Pause icon (hidden) -->
                <svg id="iconPause" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="white" stroke="none" style="display:none"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
              </button>

              <!-- Track info + progress -->
              <div style="flex:1;min-width:0">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                  <div style="display:flex;align-items:center;gap:7px">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--primary-bright)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
                    <span style="font-size:0.78rem;font-weight:700;color:var(--primary-bright);text-transform:uppercase;letter-spacing:0.05em">Audio Lesson</span>
                  </div>
                  <div style="font-size:0.75rem;color:var(--text-muted);font-family:var(--font-mono)">
                    <span id="audioCurrentTime">0:00</span> / <span id="audioDuration">--:--</span>
                  </div>
                </div>

                <!-- Progress bar -->
                <div id="audioProgressBar"
                     style="width:100%;height:5px;background:var(--border);border-radius:3px;cursor:pointer;position:relative;overflow:hidden"
                     onclick="seekAudio(event)">
                  <div id="audioProgressFill"
                       style="height:100%;width:0%;background:linear-gradient(90deg,var(--primary),var(--primary-bright));border-radius:3px;transition:width 0.15s linear;pointer-events:none"></div>
                </div>

                <!-- Loading indicator -->
                <div id="audioLoading" style="display:none;font-size:0.72rem;color:var(--text-muted);margin-top:6px">Loading audio…</div>
              </div>
            </div>
          </div>

          <!-- MOBILE compact play/pause button — hidden on desktop via CSS -->
          <div class="audio-player-mobile">
            <button id="audioPlayBtnMobile"
              onclick="toggleAudio()"
              aria-label="Play / Pause audio lesson">
              <span class="mobile-audio-icon mobile-icon-play">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="white" stroke="none"><polygon points="5 3 19 12 5 21 5 3"/></svg>
              </span>
              <span class="mobile-audio-icon mobile-icon-pause" style="display:none">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="white" stroke="none"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
              </span>
            </button>
          </div>

          <!-- Hidden native audio element — not preloaded -->
          <audio id="lessonAudio" preload="none" src="<?= $audioSrc ?>"></audio>
          <?php endif; ?>

          <?php if ($hasVideo): ?>
          <!-- ── Embedded YouTube player (only on detail page) ── -->
          <div style="position:relative;width:100%;padding-bottom:56.25%;border-radius:var(--radius-lg);overflow:hidden;margin-bottom:32px;border:1.5px solid var(--border);box-shadow:var(--shadow-glow)">
            <iframe
              src="<?= youtubeEmbedUrl($lesson['video_id']) ?>"
              title="<?= sanitize($lesson['title']) ?>"
              allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
              allowfullscreen
              style="position:absolute;top:0;left:0;width:100%;height:100%;border:none"
              loading="lazy"
            ></iframe>
          </div>
          <?php endif; ?>

          <!-- ── Text-to-Speech Controls ── -->
          <div id="tts-controls" style="display:flex;align-items:center;gap:10px;margin-bottom:20px;flex-wrap:wrap">
            <button id="tts-play-btn"
              class="tts-play-btn"
              onclick="ttsPlay()"
              style="display:inline-flex;align-items:center;gap:8px;padding:9px 18px;background:var(--bg-card);border:1.5px solid var(--border);border-radius:50px;cursor:pointer;font-size:0.82rem;font-weight:600;color:var(--text);transition:all 0.2s">
              <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
              <span id="tts-play-label">🔊 Play Lesson</span>
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
              <span style="display:inline-block;width:6px;height:6px;background:var(--primary-bright);border-radius:50%;margin-right:5px;animation:ttsPulse 1s ease-in-out infinite"></span>
              Speaking…
            </span>
          </div>

          <!-- Full content -->
          <div id="lesson-content" class="lesson-content-body lesson-content-scroll">
            <?php render_lesson_content($lesson); ?>
          </div>

          <?php include __DIR__ . '/../includes/partials/lesson_engagement.php'; ?>

          <!-- Prev / Next navigation -->
          <div style="display:flex;gap:12px;margin-top:40px;flex-wrap:wrap">
            <?php if ($prevLesson): ?>
            <?php $prevLocked = $prevLesson['access_level']==='premium' && !isPremium(); ?>
            <a href="<?= $prevLocked ? SITE_URL.'/upgrade.php' : SITE_URL.'/learn/lesson/'.$prevLesson['id'] ?>"
               style="flex:1;min-width:0;display:flex;align-items:center;gap:12px;padding:16px 20px;background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);text-decoration:none;transition:all 0.2s;group"
               onmouseover="this.style.borderColor='var(--primary)';this.style.background='var(--glow-soft)'"
               onmouseout="this.style.borderColor='var(--border)';this.style.background='var(--bg-card)'">
              <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-muted);flex-shrink:0"><polyline points="15 18 9 12 15 6"/></svg>
              <div style="min-width:0">
                <div style="font-size:0.72rem;color:var(--text-muted);margin-bottom:2px">Previous</div>
                <div style="font-size:0.88rem;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= sanitize($prevLesson['title']) ?></div>
              </div>
            </a>
            <?php endif; ?>

            <?php if ($nextLesson): ?>
            <?php $nextLocked = $nextLesson['access_level']==='premium' && !isPremium(); ?>
            <a href="<?= $nextLocked ? SITE_URL.'/upgrade.php' : SITE_URL.'/learn/lesson/'.$nextLesson['id'] ?>"
               style="flex:1;min-width:0;display:flex;align-items:center;justify-content:flex-end;gap:12px;padding:16px 20px;background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);text-decoration:none;transition:all 0.2s"
               onmouseover="this.style.borderColor='var(--primary)';this.style.background='var(--glow-soft)'"
               onmouseout="this.style.borderColor='var(--border)';this.style.background='var(--bg-card)'">
              <div style="min-width:0;text-align:right">
                <div style="font-size:0.72rem;color:var(--text-muted);margin-bottom:2px">Next <?= $nextLocked ? '🔒' : '' ?></div>
                <div style="font-size:0.88rem;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= sanitize($nextLesson['title']) ?></div>
              </div>
              <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-muted);flex-shrink:0"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
            <?php else: ?>
            <!-- Last lesson — back to all lessons -->
            <a href="<?= SITE_URL ?>/learn.php"
               style="flex:1;min-width:0;display:flex;align-items:center;justify-content:center;gap:10px;padding:16px 20px;background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);text-decoration:none;transition:all 0.2s"
               onmouseover="this.style.borderColor='var(--primary)';this.style.background='var(--glow-soft)'"
               onmouseout="this.style.borderColor='var(--border)';this.style.background='var(--bg-card)'">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:#86efac"><polyline points="20 6 9 17 4 12"/></svg>
              <div>
                <div style="font-size:0.72rem;color:var(--text-muted);margin-bottom:2px">You've finished!</div>
                <div style="font-size:0.88rem;font-weight:600;color:#86efac">Browse all lessons</div>
              </div>
            </a>
            <?php endif; ?>
          </div>

          <?php render_ai_study_card($lesson['id'], 'learn'); ?>

        <?php else: ?>
          <!-- ── LOCKED: Premium upgrade wall ── -->
          <?php if (!empty($lesson['audio_url'])): ?>
          <!-- Locked audio indicator -->
          <div style="display:flex;align-items:center;gap:14px;padding:16px 20px;background:rgba(139,92,246,0.06);border:1.5px solid rgba(139,92,246,0.2);border-radius:var(--radius-lg);margin-bottom:24px">
            <div style="width:44px;height:44px;border-radius:50%;background:rgba(139,92,246,0.15);display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--primary-bright)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </div>
            <div>
              <div style="font-size:0.85rem;font-weight:700;color:var(--primary-bright)">Audio narration included</div>
              <div style="font-size:0.78rem;color:var(--text-muted)">Upgrade to unlock the audio lesson for this topic.</div>
            </div>
          </div>
          <?php endif; ?>

          <?php if ($hasVideo): ?>
          <!-- Blurred thumbnail instead of player -->
          <div style="position:relative;width:100%;padding-bottom:56.25%;border-radius:var(--radius-lg);overflow:hidden;margin-bottom:32px;border:1.5px solid var(--border)">
            <img
              src="<?= youtubeThumbnailUrl($lesson['video_id'], 'maxresdefault') ?>"
              alt="<?= sanitize($lesson['title']) ?>"
              loading="lazy"
              style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;filter:blur(6px) brightness(0.4)"
              onerror="ytThumbFallback(this,
                '<?= youtubeThumbnailUrl($lesson['video_id'], 'hqdefault') ?>',
                '<?= youtubeThumbnailUrl($lesson['video_id'], 'mqdefault') ?>'
              )">
            <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px">
              <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--primary-bright)"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
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
                <p>This premium lesson includes the full video, detailed notes, and practical exercises that will accelerate your Web3 journey.</p>
                <p>Premium members get instant access to all content — no waiting.</p>
              </div>
            </div>
            <div class="lock-overlay">
              <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--primary-bright);margin-bottom:12px"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <div class="lock-title">Premium Content Locked</div>
              <div class="lock-subtitle">Upgrade for full access to all <?= $db->query("SELECT COUNT(*) FROM lessons WHERE access_level='premium'")->fetchColumn() ?>+ premium lessons.</div>
              <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center">
                <a href="<?= SITE_URL ?>/upgrade.php" class="btn-upgrade btn-lg">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                  Upgrade — <?= getPremiumPriceDisplay() ?>
                </a>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- ── Sidebar ── -->
      <aside style="min-width:0">
        <?php if (isLoggedIn()): ?>
        <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);padding:22px;margin-bottom:20px;position:sticky;top:100px">
          <h3 style="font-size:0.82rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);margin-bottom:16px;display:flex;align-items:center;gap:6px">
            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            Lesson Info
          </h3>
          <?php $meta = [
            ['Category', sanitize($lesson['category'])],
            ['Duration',  sanitize($lesson['duration'] ?? '')],
            ['Access',    $isPremiumLesson ? '<span style="color:#d8b4fe">⭐ Premium</span>' : '<span style="color:#86efac">✓ Free</span>'],
            ['Format',    implode(' &nbsp;', array_filter([
              $hasVideo ? '<span style="color:#f87171">▶ Video</span>' : null,
              !empty($lesson['audio_url']) ? '<span style="color:var(--primary-bright)">♫ Audio</span>' : null,
              (!$hasVideo && empty($lesson['audio_url'])) ? 'Text' : null,
            ]))],
            ['Added',     date('M j, Y', strtotime($lesson['created_at']))],
          ]; foreach ($meta as [$label, $val]): ?>
          <div style="display:flex;justify-content:space-between;font-size:0.84rem;padding:8px 0;border-bottom:1px solid var(--border-subtle)">
            <span style="color:var(--text-muted)"><?= $label ?></span>
            <span><?= $val ?></span>
          </div>
          <?php endforeach; ?>
          <?php if ($isLocked): ?>
          <a href="<?= SITE_URL ?>/upgrade.php" class="btn-upgrade" style="width:100%;justify-content:center;padding:11px;margin-top:16px;display:flex">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
            Unlock Premium
          </a>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Related lessons -->
        <?php if (!empty($related)): ?>
        <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);padding:22px">
          <h3 style="font-size:0.82rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);margin-bottom:14px">Related Lessons</h3>
          <?php foreach ($related as $r):
            $rLocked = $r['access_level']==='premium' && !isPremium();
            $rHasVid = !empty($r['video_id']);
          ?>
          <a href="<?= $rLocked ? SITE_URL.'/upgrade.php' : SITE_URL.'/learn/lesson/'.$r['id'] ?>"
             style="display:flex;align-items:center;gap:10px;padding:10px;border-radius:var(--radius);margin-bottom:3px;transition:background 0.15s;text-decoration:none"
             onmouseover="this.style.background='var(--glow-soft)'" onmouseout="this.style.background='transparent'">
            <div style="position:relative;width:44px;height:34px;flex-shrink:0;border-radius:6px;overflow:hidden;border:1px solid var(--border)">
              <?php if ($rHasVid): ?>
              <img src="<?= youtubeThumbnailUrl($r['video_id'],'mqdefault') ?>"
                   style="width:100%;height:100%;object-fit:cover" loading="lazy" alt="">
              <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.3)">
                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="white" stroke="none"><polygon points="5 3 19 12 5 21 5 3"/></svg>
              </div>
              <?php elseif (!empty($r['thumbnail'])): ?>
              <img src="<?= imageUrl($r['thumbnail'],'lesson') ?>" style="width:100%;height:100%;object-fit:cover" loading="lazy" alt="">
              <?php else: ?>
              <div style="width:100%;height:100%;background:var(--bg-card2);display:flex;align-items:center;justify-content:center">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-muted)"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/></svg>
              </div>
              <?php endif; ?>
            </div>
            <div style="min-width:0">
              <div style="font-size:0.83rem;font-weight:600;color:var(--text);line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= sanitize($r['title']) ?></div>
              <div style="font-size:0.72rem;color:var(--text-muted)"><?= $r['duration'] ?><?= $rLocked?' 🔒':'' ?></div>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </aside>
    </div>
  </div>
</div>

<?php if ($hasAudio): ?>
<script>
(function() {
  const audio    = document.getElementById('lessonAudio');
  const playBtn  = document.getElementById('audioPlayBtn');
  const iconPlay = document.getElementById('iconPlay');
  const iconPause= document.getElementById('iconPause');
  const fill     = document.getElementById('audioProgressFill');
  const curTime  = document.getElementById('audioCurrentTime');
  const durEl    = document.getElementById('audioDuration');
  const loading  = document.getElementById('audioLoading');

  function fmt(s) {
    if (!isFinite(s)) return '--:--';
    const m = Math.floor(s / 60);
    const sec = String(Math.floor(s % 60)).padStart(2, '0');
    return m + ':' + sec;
  }

  audio.addEventListener('loadedmetadata', () => {
    durEl.textContent = fmt(audio.duration);
    loading.style.display = 'none';
  });

  audio.addEventListener('timeupdate', () => {
    if (!audio.duration) return;
    const pct = (audio.currentTime / audio.duration) * 100;
    fill.style.width = pct + '%';
    curTime.textContent = fmt(audio.currentTime);
  });

  audio.addEventListener('waiting', () => { loading.style.display = 'block'; });
  audio.addEventListener('playing', () => { loading.style.display = 'none'; });
  audio.addEventListener('ended',   () => setPlayState(false));

  function setPlayState(playing) {
    // Desktop icons
    iconPlay.style.display  = playing ? 'none'  : 'block';
    iconPause.style.display = playing ? 'block' : 'none';
    // Mobile button icons
    const mPlay  = document.querySelector('.mobile-icon-play');
    const mPause = document.querySelector('.mobile-icon-pause');
    if (mPlay)  mPlay.style.display  = playing ? 'none'  : 'inline-flex';
    if (mPause) mPause.style.display = playing ? 'inline-flex' : 'none';
  }

  window.toggleAudio = function() {
    if (audio.paused) {
      audio.play();
      setPlayState(true);
      loading.style.display = 'block';
    } else {
      audio.pause();
      setPlayState(false);
    }
  };

  window.seekAudio = function(e) {
    const bar  = document.getElementById('audioProgressBar');
    const rect = bar.getBoundingClientRect();
    const pct  = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
    if (audio.duration) {
      audio.currentTime = pct * audio.duration;
    }
  };

  // Stop audio on page leave
  window.addEventListener('beforeunload', () => audio.pause());
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') audio.pause();
  });
})();
</script>
<?php endif; ?>

<style>
/* Lesson layout: stack on tablet/mobile */
@media(max-width:900px){
  .lesson-layout, .lesson-hero-grid {
    grid-template-columns: 1fr !important;
    gap: 24px !important;
  }
  .lesson-layout > *, .lesson-hero-grid > * { min-width: 0; }
}
@media(max-width:768px){.lesson-hero-grid div:last-child{display:none}}

/* ── Audio player: full UI on desktop, compact button on mobile ── */

/* Default (desktop ≥481px): show full player, hide compact button */
.audio-player-desktop { display: block; }
.audio-player-mobile  { display: none;  }

/* Mobile (≤480px): hide full player, show compact button */
@media (max-width: 480px) {
  .audio-player-desktop { display: none !important; }

  .audio-player-mobile {
    display: block !important;
    margin-bottom: 20px;
  }

  .audio-player-mobile button#audioPlayBtnMobile {
    display: flex !important;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    padding: 0;
    background: var(--primary);
    border: none;
    border-radius: 50%;
    cursor: pointer;
    box-shadow: 0 0 14px rgba(139,92,246,0.4);
    transition: background 0.2s, transform 0.15s;
    flex-shrink: 0;
  }

  .audio-player-mobile button#audioPlayBtnMobile:active {
    background: var(--primary-bright);
    transform: scale(0.92);
  }

  .mobile-audio-icon {
    display: inline-flex;
    align-items: center;
    flex-shrink: 0;
  }
}
</style>

<?php
// Quiz unlock data is now returned live from the complete_lesson API.
// No server-side precompute needed — avoids stale data and repeat modals.
?>

<?php if (!($isLocked ?? false) && isLoggedIn()): ?>
<!-- ── Gamification: Complete Lesson Button ─────────────────────────────────── -->
<style>
/* ── Complete button — solid purple, high contrast, both modes ── */
#completeLessonWrap {
    position: fixed;
    bottom: 24px;
    right: 20px;
    z-index: 900;
    /* keep above page content but below modals */
}
#completeLessonBtn {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    padding: 13px 26px;
    border-radius: 50px;
    border: none;
    cursor: pointer;
    /* Solid violet — readable on dark AND light backgrounds */
    background: #7c3aed;
    color: #ffffff;
    font-size: 0.9rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    box-shadow: 0 4px 24px rgba(124,58,237,0.55), 0 0 0 2px rgba(255,255,255,0.12);
    transition: transform 0.15s, box-shadow 0.15s, background 0.15s;
    text-shadow: 0 1px 2px rgba(0,0,0,0.3);
}
#completeLessonBtn:hover:not(:disabled) {
    background: #6d28d9;
    transform: translateY(-2px);
    box-shadow: 0 8px 32px rgba(109,40,217,0.65), 0 0 0 2px rgba(255,255,255,0.18);
}
#completeLessonBtn:disabled { opacity: 0.7; cursor: not-allowed; }

/* Done chip */
.lesson-done-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 11px 22px;
    border-radius: 50px;
    background: rgba(34,197,94,0.15);
    border: 1.5px solid rgba(34,197,94,0.4);
    color: #86efac;
    font-size: 0.88rem;
    font-weight: 700;
    box-shadow: 0 4px 20px rgba(0,0,0,0.25);
}

/* ── Modals shared ── */
.gm-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.78);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.gm-overlay.show { display: flex; }
.gm-box {
    background: var(--bg-card);
    border: 1.5px solid rgba(139,92,246,0.4);
    border-radius: var(--radius-lg);
    padding: 36px 28px;
    text-align: center;
    width: 100%;
    max-width: 380px;
    box-shadow: 0 0 60px rgba(139,92,246,0.25);
    animation: gmPop 0.38s cubic-bezier(0.34,1.56,0.64,1);
}
@keyframes gmPop { from{transform:scale(0.65);opacity:0} to{transform:scale(1);opacity:1} }

/* ── Quiz inside modal ── */
.gm-quiz-opts label {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 13px;
    border-radius: var(--radius);
    border: 1px solid var(--border);
    cursor: pointer;
    margin-bottom: 8px;
    font-size: 0.84rem;
    text-align: left;
    transition: border-color 0.15s, background 0.15s;
    line-height: 1.4;
}
.gm-quiz-opts label:hover { border-color: var(--primary); background: var(--glow-soft); }
.gm-quiz-opts input[type=radio] { accent-color: var(--primary); flex-shrink: 0; margin-top: 2px; }

/* ── Badge modal icon ── */
.gm-badge-icon {
    font-size: 4rem;
    display: block;
    margin-bottom: 12px;
    animation: badgeSpin 0.65s cubic-bezier(0.34,1.56,0.64,1);
}
@keyframes badgeSpin { from{transform:rotate(-200deg) scale(0.3)} to{transform:rotate(0deg) scale(1)} }

/* ── Confetti ── */
.confetti-piece {
    position: fixed;
    pointer-events: none;
    z-index: 10000;
    border-radius: 2px;
    animation: confettiFall linear forwards;
}
@keyframes confettiFall {
    0%   { transform:translateY(-10px) rotate(0deg); opacity:1 }
    100% { transform:translateY(110vh) rotate(720deg); opacity:0 }
}

@media (max-width: 480px) {
    #completeLessonWrap { bottom: 16px; right: 12px; left: 12px; }
    #completeLessonBtn  { width: 100%; justify-content: center; }
    .lesson-done-chip   { width: 100%; justify-content: center; }
}
</style>

<div id="completeLessonWrap">
<?php if ($lessonAlreadyDone): ?>
  <div class="lesson-done-chip">
    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
    Lesson Completed
  </div>
<?php else: ?>
  <!-- Reading progress hint — shown while user hasn't scrolled enough -->
  <div id="readingHint" style="
    display:inline-flex;align-items:center;gap:8px;
    padding:11px 20px;border-radius:50px;
    background:rgba(124,58,237,0.12);border:1.5px solid rgba(124,58,237,0.35);
    color:var(--text-muted);font-size:0.85rem;font-weight:600;
    box-shadow:0 4px 20px rgba(0,0,0,0.18);
    transition:opacity 0.4s;
  ">
    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="7 13 12 18 17 13"/><polyline points="7 6 12 11 17 6"/></svg>
    Read the lesson to continue
  </div>
  <button id="completeLessonBtn"
    onclick="completeLessonAction(<?= $lesson['id'] ?>)"
    style="display:none"
    aria-label="Mark lesson as complete">
    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
    Mark as Complete
  </button>
<?php endif; ?>
</div>

<!-- ── Badge earned modal ─────────────────────────────────────────────────── -->
<div class="gm-overlay" id="badgeModal">
  <div class="gm-box">
    <span class="gm-badge-icon" id="bmIcon"></span>
    <p style="color:var(--primary-bright);font-size:0.72rem;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;margin-bottom:6px">Badge Earned!</p>
    <h2 id="bmName" style="font-size:1.35rem;font-weight:800;margin-bottom:8px"></h2>
    <p id="bmDesc" style="color:var(--text-muted);font-size:0.875rem;margin-bottom:24px;line-height:1.5"></p>
    <button onclick="closeBadgeModal()" style="width:100%;padding:12px;border:none;border-radius:var(--radius);background:#7c3aed;color:#fff;font-weight:700;font-size:0.95rem;cursor:pointer;letter-spacing:0.02em">
      Awesome! 🎉
    </button>
  </div>
</div>

<!-- ── Quiz unlock modal (fires when final lesson completed) ──────────────── -->
<div class="gm-overlay" id="quizUnlockModal">
  <div class="gm-box" style="max-width:460px;text-align:left">
    <!-- Header -->
    <div style="text-align:center;margin-bottom:20px">
      <div style="font-size:2.2rem;margin-bottom:8px">🎉</div>
      <h2 style="font-size:1.2rem;font-weight:800;margin-bottom:6px">Section Complete!</h2>
      <p style="color:var(--text-muted);font-size:0.85rem;line-height:1.5" id="quizUnlockSubtitle"></p>
    </div>

    <!-- Quiz form (injected by JS) -->
    <div id="quizModalBody"></div>

    <!-- Result banner -->
    <div id="quizModalResult" style="display:none;padding:12px 14px;border-radius:var(--radius);font-size:0.875rem;font-weight:600;margin-bottom:14px"></div>

    <!-- Buttons -->
    <div id="quizModalBtns" style="display:flex;gap:10px;margin-top:16px">
      <button id="quizModalStartBtn" onclick="startQuizInModal()"
        style="flex:1;padding:11px;border:none;border-radius:var(--radius);background:#7c3aed;color:#fff;font-weight:700;cursor:pointer;font-size:0.9rem">
        Start Quiz
      </button>
      <button onclick="skipQuiz()"
        style="flex:1;padding:11px;border:1.5px solid var(--border);border-radius:var(--radius);background:none;color:var(--text-muted);font-weight:600;cursor:pointer;font-size:0.9rem">
        Skip For Now
      </button>
    </div>

    <div id="quizModalSubmitRow" style="display:none;gap:10px;margin-top:16px">
      <button id="quizModalSubmitBtn" onclick="submitModalQuiz()"
        style="width:100%;padding:12px;border:none;border-radius:var(--radius);background:#7c3aed;color:#fff;font-weight:700;cursor:pointer;font-size:0.9rem">
        Submit Quiz
      </button>
    </div>
  </div>
</div>

<!-- ── Quiz reminder modal (for skipped quizzes) ─────────────────────────── -->
<div class="gm-overlay" id="quizReminderModal">
  <div class="gm-box">
    <div style="font-size:2rem;margin-bottom:10px">⏰</div>
    <h2 style="font-size:1.1rem;font-weight:800;margin-bottom:8px">Quiz Ready</h2>
    <p style="color:var(--text-muted);font-size:0.85rem;margin-bottom:20px;line-height:1.5" id="quizReminderText"></p>
    <a id="quizReminderLink" href="<?= SITE_URL ?>/guide.php"
       style="display:block;width:100%;padding:11px;border:none;border-radius:var(--radius);background:#7c3aed;color:#fff;font-weight:700;font-size:0.9rem;text-align:center;text-decoration:none;margin-bottom:8px">
      Continue Quiz →
    </a>
    <button onclick="document.getElementById('quizReminderModal').classList.remove('show')"
      style="width:100%;padding:10px;border:1.5px solid var(--border);border-radius:var(--radius);background:none;color:var(--text-muted);font-weight:600;cursor:pointer;font-size:0.85rem">
      Later
    </button>
  </div>
</div>

<script>
// ── Scroll-to-read detection ──────────────────────────────────────────────────
// Reveals "Mark as Complete" only after user has read ~95% of lesson content.
(function initReadingGate() {
    // Already completed — no gate needed
    if (!document.getElementById('completeLessonBtn')) return;

    const btn  = document.getElementById('completeLessonBtn');
    const hint = document.getElementById('readingHint');

    function revealButton() {
        if (btn.style.display !== 'none') return; // already shown
        if (hint) { hint.style.opacity = '0'; setTimeout(() => { if(hint) hint.style.display='none'; }, 400); }
        btn.style.display = 'inline-flex';
        // Animate in
        btn.style.opacity = '0';
        btn.style.transform = 'translateY(8px)';
        btn.style.transition = 'opacity 0.4s, transform 0.4s';
        requestAnimationFrame(() => requestAnimationFrame(() => {
            btn.style.opacity = '1';
            btn.style.transform = 'translateY(0)';
        }));
    }

    // Find the lesson content container
    const contentEl = document.querySelector('.lesson-content-body');

    if (!contentEl) {
        // No content container found — reveal immediately
        revealButton();
        return;
    }

    // On desktop, the lesson content scrolls inside its own fixed-height
    // container (see lesson-reading.css). On mobile/tablet that container
    // expands naturally and the whole page scrolls instead. Detect which
    // mode is active right now so reading progress is tracked correctly.
    function isInternalScrollActive() {
        return contentEl.scrollHeight > contentEl.clientHeight + 4
            && window.getComputedStyle(contentEl).overflowY !== 'visible';
    }

    function checkScrollProgress() {
        if (isInternalScrollActive()) {
            // Internal container scroll (desktop, long lesson)
            const nearBottom = contentEl.scrollTop + contentEl.clientHeight >= contentEl.scrollHeight - 40;
            if (nearBottom) revealButton();
            return;
        }

        // Fallback: whole-page scroll (mobile/tablet, or a lesson short
        // enough that it doesn't overflow the fixed-height container)
        const rect   = contentEl.getBoundingClientRect();
        const elBot  = rect.bottom;           // bottom of content relative to viewport
        const vpBot  = window.innerHeight;    // bottom of viewport

        // Also handle short content: if content fits entirely in viewport, reveal immediately
        const contentHeight = contentEl.offsetHeight;
        const viewportHeight = window.innerHeight;

        if (contentHeight <= viewportHeight * 1.1) {
            // Short lesson — content fits on screen
            revealButton();
            return;
        }

        // For longer lessons: reveal when user has scrolled to within 120px of bottom
        if (elBot <= vpBot + 120) {
            revealButton();
        }
    }

    // Check immediately (handles short pages)
    checkScrollProgress();

    // Listen on window scroll (mobile / short desktop content)
    window.addEventListener('scroll', checkScrollProgress, { passive: true });

    // Listen on the content container's own scroll (desktop internal scroll)
    contentEl.addEventListener('scroll', checkScrollProgress, { passive: true });

    // Also check on resize (orientation change, switching desktop/mobile layout, etc.)
    window.addEventListener('resize', checkScrollProgress, { passive: true });
})();

// ── Quiz unlock state (populated from API response) ──────────────────────────
let _quizUnlock = null;

// ── Complete lesson action ─────────────────────────────────────────────────────
function completeLessonAction(lessonId) {
    const btn = document.getElementById('completeLessonBtn');
    if (!btn || btn.disabled) return;
    btn.disabled = true;
    btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="animation:spin360 0.8s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Saving…';

    const form = new FormData();
    form.append('lesson_id', lessonId);

    fetch('<?= SITE_URL ?>/api/complete_lesson.php', { method:'POST', body:form })
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                btn.disabled = false;
                btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Mark as Complete';
                return;
            }

            // Swap button → done chip
            document.getElementById('completeLessonWrap').innerHTML =
                '<div class="lesson-done-chip">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>' +
                'Lesson Completed</div>';

            // Store quiz unlock data from API
            if (d.quiz_unlock) _quizUnlock = d.quiz_unlock;

            // Show badge first, then quiz unlock
            if (d.new_badges && d.new_badges.length > 0) {
                const b = d.new_badges[0];
                setTimeout(() => {
                    showBadgeModal(b.icon, b.name, b.desc, () => {
                        if (_quizUnlock) setTimeout(showQuizUnlockModal, 400);
                    });
                }, 600);
            } else if (_quizUnlock) {
                setTimeout(showQuizUnlockModal, 700);
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Mark as Complete';
        });
}

// ── Badge modal ────────────────────────────────────────────────────────────────
let _badgeCloseCallback = null;
function showBadgeModal(icon, name, desc, onClose) {
    _badgeCloseCallback = onClose || null;
    document.getElementById('bmIcon').textContent = icon || '🏆';
    document.getElementById('bmName').textContent = name;
    document.getElementById('bmDesc').textContent = desc;
    document.getElementById('badgeModal').classList.add('show');
    launchConfetti();
}
function closeBadgeModal() {
    document.getElementById('badgeModal').classList.remove('show');
    if (_badgeCloseCallback) { _badgeCloseCallback(); _badgeCloseCallback = null; }
}

// ── Quiz unlock modal ─────────────────────────────────────────────────────────
function showQuizUnlockModal() {
    if (!_quizUnlock) return;
    const sub = document.getElementById('quizUnlockSubtitle');
    sub.textContent = 'You have unlocked the "' + _quizUnlock.title + '". Pass it to earn' +
        (_quizUnlock.badge_name ? ' the ' + _quizUnlock.badge_icon + ' ' + _quizUnlock.badge_name + ' badge.' : ' a badge.');
    document.getElementById('quizUnlockModal').classList.add('show');
    // Reset state
    document.getElementById('quizModalBody').innerHTML = '';
    document.getElementById('quizModalResult').style.display = 'none';
    document.getElementById('quizModalBtns').style.display = 'flex';
    document.getElementById('quizModalSubmitRow').style.display = 'none';
}

function startQuizInModal() {
    if (!_quizUnlock || !_quizUnlock.questions.length) return;
    document.getElementById('quizModalBtns').style.display = 'none';
    document.getElementById('quizModalSubmitRow').style.display = 'block';

    const body = document.getElementById('quizModalBody');
    body.innerHTML = '';
    _quizUnlock.questions.forEach((q, qi) => {
        const div = document.createElement('div');
        div.style.marginBottom = '16px';
        div.innerHTML = '<p style="font-weight:700;font-size:0.84rem;margin-bottom:8px;line-height:1.4">' + (qi+1) + '. ' + escHtml(q.question) + '</p>' +
            '<div class="gm-quiz-opts">' +
            ['a','b','c','d'].map(opt =>
                '<label><input type="radio" name="gmq_' + q.id + '" value="' + opt + '"> <span>' + opt.toUpperCase() + '. ' + escHtml(q[opt]) + '</span></label>'
            ).join('') +
            '</div>';
        body.appendChild(div);
    });
}

function skipQuiz() {
    document.getElementById('quizUnlockModal').classList.remove('show');
    // Store skipped state — show reminder after a beat
    setTimeout(() => {
        if (_quizUnlock) {
            document.getElementById('quizReminderText').textContent =
                'The "' + _quizUnlock.title + '" quiz is ready whenever you are. Head to the Guide page to take it.';
            document.getElementById('quizReminderLink').href = '<?= SITE_URL ?>/guide.php?guide=' + _quizUnlock.guide_id;
            document.getElementById('quizReminderModal').classList.add('show');
        }
    }, 600);
}

function submitModalQuiz() {
    if (!_quizUnlock) return;
    const btn = document.getElementById('quizModalSubmitBtn');
    const resultEl = document.getElementById('quizModalResult');

    const answers = {};
    document.querySelectorAll('#quizModalBody input[type=radio]:checked').forEach(inp => {
        const qid = inp.name.replace('gmq_', '');
        answers[qid] = inp.value;
    });

    const totalQs = _quizUnlock.questions.length;
    if (Object.keys(answers).length < totalQs) {
        resultEl.style.cssText = 'display:block;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);color:#fca5a5;padding:12px 14px;border-radius:var(--radius);font-size:0.875rem;font-weight:600';
        resultEl.textContent = 'Please answer all questions.';
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Submitting…';

    const form = new FormData();
    form.append('quiz_id', _quizUnlock.id);
    Object.entries(answers).forEach(([k,v]) => form.append('answers['+k+']', v));

    fetch('<?= SITE_URL ?>/api/submit_quiz.php', { method:'POST', body:form })
        .then(r => r.json())
        .then(d => {
            if (!d.success) { btn.disabled=false; btn.textContent='Submit Quiz'; return; }

            resultEl.style.display = 'block';
            document.getElementById('quizModalSubmitRow').style.display = 'none';

            if (d.passed) {
                resultEl.style.cssText = 'display:block;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);color:#86efac;padding:12px 14px;border-radius:var(--radius);font-size:0.875rem;font-weight:700;margin-bottom:14px';
                resultEl.textContent = '🎉 You passed! Score: ' + d.score + '% (' + d.correct + '/' + d.total + ' correct)';
                // Close quiz modal, show badge
                setTimeout(() => {
                    document.getElementById('quizUnlockModal').classList.remove('show');
                    if (d.new_badge) {
                        setTimeout(() => showBadgeModal(d.new_badge.icon, d.new_badge.name, d.new_badge.desc), 500);
                    }
                }, 1200);
            } else {
                resultEl.style.cssText = 'display:block;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);color:#fca5a5;padding:12px 14px;border-radius:var(--radius);font-size:0.875rem;font-weight:600;margin-bottom:14px';
                resultEl.textContent = 'Score: ' + d.score + '% — you need ' + d.pass_percent + '% to pass.';

                // Show retry / continue buttons
                const row = document.getElementById('quizModalSubmitRow');
                row.style.display = 'flex';
                row.innerHTML = '<button onclick="retryModalQuiz()" style="flex:1;padding:11px;border:none;border-radius:var(--radius);background:#7c3aed;color:#fff;font-weight:700;cursor:pointer;font-size:0.85rem">Retry Quiz</button>' +
                    '<button onclick="document.getElementById(\'quizUnlockModal\').classList.remove(\'show\')" style="flex:1;padding:11px;border:1.5px solid var(--border);border-radius:var(--radius);background:none;color:var(--text-muted);font-weight:600;cursor:pointer;font-size:0.85rem">Continue Learning</button>';
            }
        })
        .catch(() => { btn.disabled=false; btn.textContent='Submit Quiz'; });
}

function retryModalQuiz() {
    document.getElementById('quizModalResult').style.display = 'none';
    // Re-render quiz body
    startQuizInModal();
    document.getElementById('quizModalSubmitRow').innerHTML =
        '<button id="quizModalSubmitBtn" onclick="submitModalQuiz()" style="width:100%;padding:12px;border:none;border-radius:var(--radius);background:#7c3aed;color:#fff;font-weight:700;cursor:pointer;font-size:0.9rem">Submit Quiz</button>';
    document.getElementById('quizModalSubmitRow').style.display = 'block';
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function launchConfetti() {
    const colors = ['#a855f7','#7c3aed','#22d3ee','#86efac','#fbbf24','#f87171'];
    for (let i = 0; i < 50; i++) {
        setTimeout(() => {
            const el = document.createElement('div');
            el.className = 'confetti-piece';
            const size = 6 + Math.random() * 7;
            el.style.cssText = 'left:' + Math.random()*100 + 'vw;top:-10px;' +
                'background:' + colors[Math.floor(Math.random()*colors.length)] + ';' +
                'animation-duration:' + (1.5+Math.random()*2.5) + 's;' +
                'animation-delay:' + Math.random()*0.5 + 's;' +
                'width:' + size + 'px;height:' + size + 'px;' +
                'border-radius:' + (Math.random()>0.5?'50%':'3px') + ';';
            document.body.appendChild(el);
            setTimeout(() => el.remove(), 5000);
        }, i * 28);
    }
}

// Close overlay on backdrop click
['badgeModal','quizUnlockModal','quizReminderModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('show');
    });
});

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
            /* Button visibility is driven by a state class on #tts-controls,
               NOT by per-button display toggling — this keeps Stop permanently
               visible and eliminates the disappearing-button bug. */
            #tts-controls .tts-stop-btn  { display:inline-flex; }
            #tts-controls .tts-play-btn  { display:inline-flex; }
            #tts-controls .tts-status-el { display:none; }

            #tts-controls.tts-playing .tts-play-btn  { opacity:0.5; pointer-events:none; }
            #tts-controls.tts-playing .tts-status-el { display:inline-flex; }

            #tts-controls.tts-idle .tts-play-btn  { opacity:1; pointer-events:auto; }
            #tts-controls.tts-idle .tts-status-el { display:none; }

            /* Sentence highlight */
            .tts-sentence { border-radius:3px; transition:background 0.15s; }
            .tts-sentence.tts-highlight {
                background: rgba(139,92,246,0.22);
                outline: 1px solid rgba(139,92,246,0.35);
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
    let _sentenceMap = [];  // [{el, start, end}]
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
        if (label) label.textContent = '🔊 Playing…';
    }
    function setIdleUI() {
        const c = document.getElementById('tts-controls');
        if (c) { c.classList.remove('tts-playing'); c.classList.add('tts-idle'); }
        const label = document.getElementById('tts-play-label');
        if (label) label.textContent = '🔊 Play Lesson';
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
            if (e.error === 'interrupted') return;  // we cancelled intentionally
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
        // Refresh voices in case Chrome Android hasn't fired onvoiceschanged yet
        if (!_voices.length) { const v = window.speechSynthesis.getVoices(); if (v.length) _voices = v; }
        speakChunk(0);
    };

    window.ttsStop = function () {
        window._ttsActive = false;   // must be set BEFORE cancel() so onerror 'interrupted' is ignored cleanly
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

    // Boot into idle state
    setIdleUI();
})();
// ─────────────────────────────────────────────────────────────────────────────
</script>
<?php endif; ?>

<script>
  // Lets the AI Tutor (popup + page) know what the user is currently reading,
  // so answers can be grounded in this lesson first. No UI impact.
  window.__WEB3EDU_LESSON_CONTEXT__ = { lesson_id: <?= (int)$lesson['id'] ?>, lesson_type: 'learn' };
</script>
<script src="<?= SITE_URL ?>/assets/js/study-card.js"></script>

<?php include __DIR__ . '/../includes/partials/related_content.php'; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
