<?php
/**
 * AI Study Card — shared UI partial.
 *
 * Renders the "🧠 AI Study Card" call-to-action section + the
 * flashcard modal markup. Used identically by /learn/lesson.php and
 * /guides/lesson.php so both surfaces stay in sync with one
 * implementation (DRY — see Code Quality requirements).
 *
 * Completely free, unlimited — no premium gating here at all.
 */
function render_ai_study_card(int $lessonId, string $lessonType): void
{
    ?>
    <div class="ai-sc-section">
      <div class="ai-sc-section-text">
        <h3>🧠 AI Study Card</h3>
        <p>Review this lesson in under 30 seconds.</p>
      </div>
      <button type="button" class="ai-sc-generate-btn" id="aiScGenerateBtn"
              data-lesson-id="<?= $lessonId ?>" data-lesson-type="<?= sanitize($lessonType) ?>">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>
        Generate AI Study Card
      </button>
    </div>

    <!-- ── AI Study Card flashcard modal ── -->
    <div class="ai-sc-overlay" id="aiScOverlay" role="dialog" aria-modal="true" aria-label="AI Study Card">
      <div class="ai-sc-scene">
        <div class="ai-sc-card">
          <button class="ai-sc-close" id="aiScClose" aria-label="Close study card">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
          <div class="ai-sc-flip" id="aiScFlip">

            <!-- FRONT -->
            <div class="ai-sc-face ai-sc-front">
              <div id="aiScFrontContent">
                <div class="ai-sc-icon" aria-hidden="true">🧠</div>
                <h3>Web3edu AI</h3>
                <h3>AI Study Card</h3>
                <p class="ai-sc-hint">Click the card to reveal your lesson summary.</p>
                <span class="ai-sc-flip-label">Click to Flip</span>
              </div>
              <div id="aiScFrontLoading" class="ai-sc-loading" style="display:none">
                <div class="ai-sc-spinner" aria-hidden="true"></div>
                <span>Generating your study card…</span>
              </div>
              <div id="aiScFrontError" class="ai-sc-error" style="display:none"></div>
            </div>

            <!-- BACK -->
            <div class="ai-sc-face ai-sc-back">
              <h4>Lesson Summary</h4>
              <ul class="ai-sc-bullets" id="aiScBullets"></ul>
              <div class="ai-sc-countdown" id="aiScCountdown" title="Auto-closes soon">
                <svg viewBox="0 0 36 36">
                  <circle class="track" cx="18" cy="18" r="15.5"></circle>
                  <circle class="progress" id="aiScCountdownCircle" cx="18" cy="18" r="15.5" stroke-dasharray="97.4" stroke-dashoffset="0"></circle>
                </svg>
              </div>
            </div>

          </div>
        </div>
      </div>
    </div>
    <?php
}
