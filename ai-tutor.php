<?php
/**
 * Web3Edu — AI Tutor Page (v2 design system)
 * Uses the same backend (api/ai_chat.php, api/ai_conversations.php) and the
 * same assets/js/ai-tutor.js as before — only the markup/CSS changed.
 * Every element ID and class below is depended on by ai-tutor.js; do not rename.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/ai_helpers.php';

if (!isLoggedIn()) {
    $_SESSION['redirect_after_login'] = SITE_URL . '/ai-tutor.php';
    setFlash('info', 'Sign in to chat with the Web3Edu AI Tutor.');
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}
requireProfileComplete();

$userId        = (int)$_SESSION['user_id'];
$userIsPremium = isPremium();
$rate          = aiChatRateLimitStatus($userId, $userIsPremium);
$conversations = aiListConversations($userId, $userIsPremium);

$pageTitle = 'AI Tutor';
$extraCss  = [SITE_URL . '/assets/css/ai-tutor.css'];
$extraJs   = [SITE_URL . '/assets/js/ai-tutor.js'];

require_once __DIR__ . '/includes/header.php';
?>

<div class="container">

  <div class="ai-tutor-hero">
    <h1 class="ai-tutor-title">Web3Edu <span class="ai-gradient-text">AI Tutor</span> <span class="ai-sparkle" aria-hidden="true">✨</span></h1>
    <p class="ai-tutor-subtitle">Ask anything about Web3, blockchain, smart contracts, wallets, DeFi and more.</p>

    <div class="ai-suggestions" id="aiSuggestions">
      <button class="ai-suggestion-chip" type="button">What is Web3?</button>
      <button class="ai-suggestion-chip" type="button">What are Memecoins?</button>
      <button class="ai-suggestion-chip" type="button">Explain blockchain like I'm 10</button>
      <button class="ai-suggestion-chip" type="button">Roadmap to become a Solidity developer</button>
      <button class="ai-suggestion-chip" type="button">Explain gas fees</button>
    </div>
  </div>

  <!-- Mobile history drawer toggle -->
  <button class="ai-history-toggle" id="aiHistoryToggle" type="button">
    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    Conversation History
  </button>

  <div class="ai-tutor-layout">

    <!-- Desktop sidebar -->
    <aside class="ai-history-sidebar" aria-label="Conversation history">
      <div class="ai-history-title">
        <span>History</span>
        <?php if (!$userIsPremium): ?><span title="Free plan shows your 5 most recent">Recent</span><?php endif; ?>
      </div>
      <button class="ai-history-new" id="aiNewChatBtnDesktop" type="button">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        New conversation
      </button>
      <div id="aiHistoryListDesktop">
        <?php foreach ($conversations as $c): ?>
        <div class="ai-history-item" data-conversation-id="<?= (int)$c['id'] ?>"><?= sanitize($c['title']) ?></div>
        <?php endforeach; ?>
        <?php if (empty($conversations)): ?>
        <div style="font-size:var(--text-xs);color:var(--text-muted);padding:8px 10px">No conversations yet.</div>
        <?php endif; ?>
      </div>
      <?php if (!$userIsPremium): ?>
      <div class="ai-history-locked">⭐ Premium unlocks unlimited saved history.</div>
      <?php endif; ?>
    </aside>

    <!-- Chat panel -->
    <section class="ai-chat-panel" aria-label="AI Tutor chat">
      <div class="ai-chat-messages" id="aiChatMessages">
        <div class="ai-chat-empty" id="aiChatEmpty">
          🧠 Ask a question above or pick a suggestion to get started.
        </div>
      </div>
      <div class="ai-chat-composer" id="aiChatComposer">
        <?php if ($rate['allowed']): ?>
        <div class="ai-input-row">
          <textarea id="aiTutorInput" rows="1" placeholder="Ask about Web3, blockchain, smart contracts, wallets, DeFi…"></textarea>
          <button class="ai-send-btn" id="aiTutorSend" aria-label="Send question">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          </button>
        </div>
        <?php if (!$userIsPremium): ?>
        <div class="ai-remaining-badge" id="aiRemainingBadge"><?= max(0, $rate['limit'] - $rate['used']) ?> free question<?= $rate['limit'] === 1 ? '' : 's' ?> left today</div>
        <?php endif; ?>
        <?php else: ?>
        <div class="ai-upgrade-banner" id="aiUpgradeBanner">
          <h4>⭐ Daily free question used</h4>
          <p>Free members get <?= (int)$rate['limit'] ?> AI Tutor question per day. Upgrade to Premium for unlimited conversations, unlimited saved history, and full access to every lesson.</p>
          <a href="<?= SITE_URL ?>/upgrade.php" class="btn btn-primary btn-sm">Upgrade — <?= htmlspecialchars(getPremiumPriceDisplay()) ?></a>
        </div>
        <?php endif; ?>
      </div>
    </section>

  </div>
</div>

<!-- Mobile drawer -->
<div class="ai-history-drawer-overlay" id="aiDrawerOverlay"></div>
<div class="ai-history-drawer" id="aiHistoryDrawer">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-4)">
    <div class="ai-history-title" style="margin-bottom:0">History</div>
    <button class="ai-history-drawer-close" id="aiDrawerClose" aria-label="Close history">
      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>
  <button class="ai-history-new" id="aiNewChatBtnMobile" type="button">
    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    New conversation
  </button>
  <div id="aiHistoryListMobile">
    <?php foreach ($conversations as $c): ?>
    <div class="ai-history-item" data-conversation-id="<?= (int)$c['id'] ?>"><?= sanitize($c['title']) ?></div>
    <?php endforeach; ?>
    <?php if (empty($conversations)): ?>
    <div style="font-size:var(--text-xs);color:var(--text-muted);padding:8px 10px">No conversations yet.</div>
    <?php endif; ?>
  </div>
  <?php if (!$userIsPremium): ?>
  <div class="ai-history-locked">⭐ Premium unlocks unlimited saved history.</div>
  <?php endif; ?>
</div>

<script>
  window.__AI_TUTOR_CONFIG__ = {
    rateLimitAllowed: <?= $rate['allowed'] ? 'true' : 'false' ?>,
    isPremium: <?= $userIsPremium ? 'true' : 'false' ?>,
    limit: <?= $rate['limit'] === null ? 'null' : (int)$rate['limit'] ?>
  };
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
