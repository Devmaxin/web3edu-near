</main>

<?php if (($currentPath ?? '') !== 'ai-tutor.php'): ?>
<!-- ============================================================
     Floating AI Button + Quick Chat Popup — sitewide.
     Uses the SAME backend (api/ai_chat.php) as the full AI Tutor
     page, so history and rate limits stay in sync. Powered by
     assets/js/ai-widget.js (unchanged) — IDs below are required by it.
     ============================================================ -->
<button class="ai-fab" id="aiFabBtn" aria-label="Open AI Tutor chat" aria-haspopup="dialog" aria-expanded="false" title="Ask the Web3Edu AI Tutor">
  <span class="ai-fab-pulse" aria-hidden="true"></span>
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>
  </svg>
</button>

<div class="ai-popup" id="aiPopup" role="dialog" aria-modal="false" aria-label="AI Tutor quick chat">
  <div class="ai-popup-header">
    <span class="ai-avatar" aria-hidden="true">
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>
    </span>
    <div>
      <div class="ai-popup-header-title">Web3Edu AI Tutor</div>
      <div class="ai-popup-header-sub">Ask a quick Web3 question</div>
    </div>
    <button class="ai-popup-close" id="aiPopupClose" aria-label="Close chat">
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>
  <div class="ai-popup-body" id="aiPopupBody">
    <div class="ai-popup-welcome">
      👋 Hi! I'm your Web3Edu AI Tutor. Ask me anything about blockchain, wallets, DeFi, smart contracts, or Web3 in general.
    </div>
  </div>
  <div class="ai-popup-footer">
    <div class="ai-input-row">
      <textarea id="aiPopupInput" rows="1" placeholder="<?= isLoggedIn() ? 'Ask a Web3 question…' : 'Sign in to ask a question…' ?>" <?= isLoggedIn() ? '' : 'disabled' ?>></textarea>
      <button class="ai-send-btn" id="aiPopupSend" aria-label="Send question" <?= isLoggedIn() ? '' : 'disabled' ?>>
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
      </button>
    </div>
    <?php if (!isLoggedIn()): ?>
      <a href="<?= SITE_URL ?>/login.php" class="ai-popup-cta">Sign in to chat with the AI Tutor →</a>
    <?php else: ?>
      <a href="<?= SITE_URL ?>/ai-tutor.php" class="ai-popup-cta">Open full AI Tutor for a longer conversation →</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<footer class="site-footer">
  <div class="container">
    <div class="chain-divider"></div>
    <div class="site-footer-grid">
      <div class="site-footer-brand">
        <div class="site-logo"><span class="site-logo-mark" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg></span><span class="site-logo-word">Web3Edu<span class="logo-accent"> NEAR</span></span></div>
        <p>Structured Web3 education on NEAR — lessons, quizzes, on-chain credentials, and an AI tutor that's read every lesson.</p>
      </div>
      <div class="site-footer-col">
        <h4>Platform</h4>
        <a href="<?= SITE_URL ?>/learn.php">Learn</a>
        <a href="<?= SITE_URL ?>/airdrops.php">Airdrops</a>
        <a href="<?= SITE_URL ?>/upgrade.php">Premium</a>
        <a href="<?= SITE_URL ?>/blog.php">Blog</a>
      </div>
      <div class="site-footer-col">
        <h4>Company</h4>
        <a href="<?= SITE_URL ?>/about.php">About</a>
        <a href="<?= SITE_URL ?>/terms-of-service.php">Terms</a>
        <a href="<?= SITE_URL ?>/privacy-policy.php">Privacy</a>
      </div>
    </div>
    <div class="site-footer-bottom">
      &copy; <?= date('Y') ?> Web3Edu NEAR. All rights reserved.
    </div>
  </div>
</footer>

<script src="<?= SITE_URL ?>/assets/js/main.js"></script>
<?php if (($currentPath ?? '') !== 'ai-tutor.php'): ?>
<script src="<?= SITE_URL ?>/assets/js/ai-widget.js"></script>
<?php endif; ?>
<?php if (!empty($extraJs)): foreach ((array)$extraJs as $js): ?>
<script src="<?= htmlspecialchars($js) ?>"></script>
<?php endforeach; endif; ?>
</body>
</html>
