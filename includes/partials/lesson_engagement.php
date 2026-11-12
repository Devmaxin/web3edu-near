<?php
/**
 * Shared lesson engagement block: bookmark, private Notes, text Highlights.
 * Included identically by guides/lesson.php and learn/lesson.php so the
 * experience never diverges between the two lesson viewers.
 *
 * Expects in scope: $lesson (array with 'id'), $userId (int), and — only
 * meaningful in guide context — $guideId (int|null).
 */
$engLessonId = (int)$lesson['id'];
$engGuideId  = isset($guideId) ? (int)$guideId : 0;
$engNote     = getLessonNote($userId, $engLessonId);
$engHighlights = getLessonHighlights($userId, $engLessonId);
?>
<!-- Floating highlight toolbar, shown on text selection -->
<div id="highlightToolbar" class="hl-toolbar" style="display:none">
  <?php foreach (['yellow'=>'#facc15','green'=>'#0B7958','blue'=>'#60a5fa','pink'=>'#f472b6'] as $colorKey => $hex): ?>
  <button type="button" onclick="LessonEngagement.applyHighlight('<?= $colorKey ?>')" style="background:<?= $hex ?>" title="Highlight <?= $colorKey ?>"></button>
  <?php endforeach; ?>
</div>

<div class="card lesson-engagement-card">
  <div class="lesson-engagement-row">
    <h3 class="lesson-engagement-title"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;margin-right:6px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><polyline points="8 14 6 14"/></svg> My Notes <span>(private)</span></h3>
    <div style="display:flex;align-items:center;gap:10px">
      <span id="noteSaveStatus" class="lesson-engagement-status"></span>
      <button type="button" class="bookmark-btn <?= isBookmarked('lesson', $engLessonId) ? 'active' : '' ?>"
              data-type="lesson" data-id="<?= $engLessonId ?>"
              aria-pressed="<?= isBookmarked('lesson', $engLessonId) ? 'true' : 'false' ?>"
              title="<?= isBookmarked('lesson', $engLessonId) ? 'Remove bookmark' : 'Save for later' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
      </button>
    </div>
  </div>
  <textarea id="lessonNoteInput" class="form-input" rows="4" placeholder="Jot down anything you want to remember about this lesson…"><?= sanitize($engNote['content'] ?? '') ?></textarea>
</div>

<script>
  window.__LESSON_ENGAGEMENT__ = {
    lessonId: <?= $engLessonId ?>,
    savedHighlights: <?= json_encode(array_map(fn($h) => ['text' => $h['highlighted_text'], 'color' => $h['color']], $engHighlights)) ?>,
  };
</script>
