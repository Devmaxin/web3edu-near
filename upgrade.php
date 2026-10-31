<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/subscriptions.php';
requireLogin();

$pageTitle = 'Go Premium';
$extraCss  = [SITE_URL . '/assets/css/premium.css'];
$plans     = getActivePlans();
$user      = getCurrentUser();
$currentSub = getUserSubscription($user['id']);

// ── Start checkout: create a pending purchase row, hand off to Paystack JS ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_checkout') {
    $planId = (int)($_POST['plan_id'] ?? 0);
    $plan   = getPlanById($planId);

    if (!$plan || !$plan['is_active']) {
        echo json_encode(['error' => 'Invalid plan selected.']); exit;
    }

    $reference = 'W3E-' . strtoupper(bin2hex(random_bytes(6))) . '-' . time();
    $db = getDB();
    $db->prepare("INSERT INTO purchases (user_id, reference, amount, status, plan_id, provider, billing_cycle) VALUES (?, ?, ?, 'pending', ?, 'paystack', ?)")
       ->execute([$user['id'], $reference, $plan['price_kobo'] / 100, $plan['id'], $plan['billing_cycle']]);

    $init = getPaymentProvider()->initializeTransaction($user, $plan, $reference);
    if (!empty($init['success'])) {
        Analytics::track('checkout_started', $user['id'], [
            'plan_id' => $plan['id'], 'plan_name' => $plan['name'], 'billing_cycle' => $plan['billing_cycle'],
            'amount_kobo' => $plan['price_kobo'], 'reference' => $reference,
        ]);
    }
    header('Content-Type: application/json');
    echo json_encode($init);
    exit;
}

require_once __DIR__ . '/includes/header.php';

Analytics::track('premium_page_viewed', $user['id']);
?>

<div class="page-header container" style="text-align:center" data-reveal>
  <div class="section-label">Simple pricing</div>
  <h1 class="page-header-title">Go <span class="gradient-text">Premium</span></h1>
  <p class="page-header-subtitle" style="margin:0 auto">Unlock every premium lesson, guide, and unlimited AI Tutor access.</p>
</div>

<?php if ($currentSub && $currentSub['status'] === 'active'): ?>
<div class="container" style="margin-bottom:var(--space-6)">
  <div class="alert alert-success" style="justify-content:space-between;align-items:center">
    <div>
      You're on the <strong><?= htmlspecialchars($currentSub['plan_name']) ?></strong> plan.
      <?= $currentSub['expires_at'] ? 'Renews ' . date('M j, Y', strtotime($currentSub['renews_at'])) . '.' : 'Permanent access — never expires.' ?>
    </div>
    <a href="<?= SITE_URL ?>/dashboard.php?tab=subscription" class="btn btn-secondary btn-sm">Manage subscription</a>
  </div>
</div>
<?php endif; ?>

<div class="container section" style="padding-top:0">
  <div class="grid-3 pricing-grid">
    <?php foreach ($plans as $plan):
      $isCurrentPlan = $currentSub && $currentSub['status'] === 'active' && (int)$currentSub['plan_id'] === (int)$plan['id'];
      $priceNaira = $plan['price_kobo'] / 100;
      $cycleLabel = ['monthly' => '/month', 'yearly' => '/year'][$plan['billing_cycle']] ?? '';
    ?>
      <div class="card pricing-card <?= $plan['is_recommended'] ? 'featured' : '' ?>" data-reveal>
        <?php if ($plan['is_recommended']): ?><span class="pricing-badge">★ Most popular</span><?php endif; ?>
        <div style="text-transform:uppercase;font-size:var(--text-xs);letter-spacing:0.08em;color:var(--text-muted);font-weight:700"><?= htmlspecialchars($plan['name']) ?></div>
        <div class="pricing-amount">₦<?= number_format($priceNaira, $priceNaira == floor($priceNaira) ? 0 : 2) ?><span><?= $cycleLabel ?></span></div>
        <p style="font-size:var(--text-sm)"><?= htmlspecialchars($plan['description']) ?></p>

        <ul class="pricing-features">
          <?php foreach ($plan['features'] as $feature): ?>
            <li><span class="check">✓</span> <?= htmlspecialchars($feature) ?></li>
          <?php endforeach; ?>
        </ul>

        <?php if ($isCurrentPlan): ?>
          <button class="btn btn-secondary" style="width:100%" disabled>Current plan</button>
        <?php else: ?>
          <button class="btn <?= $plan['is_recommended'] ? 'btn-primary' : 'btn-secondary' ?> checkout-btn" style="width:100%" data-plan-id="<?= (int)$plan['id'] ?>">
            Choose <?= htmlspecialchars($plan['name']) ?>
          </button>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Comparison table -->
  <div class="card" style="margin-top:var(--space-7);overflow-x:auto" data-reveal>
    <h2 style="font-size:var(--text-lg);margin-bottom:var(--space-4)">Compare plans</h2>
    <table class="compare-table">
      <thead><tr><th>Feature</th><th>Free</th><?php foreach ($plans as $p): ?><th><?= htmlspecialchars($p['name']) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
        <tr><td>Free lessons & guides</td><td class="yes">✓</td><?php foreach ($plans as $p): ?><td class="yes">✓</td><?php endforeach; ?></tr>
        <tr><td>Premium lessons & guides</td><td class="no">—</td><?php foreach ($plans as $p): ?><td class="yes">✓</td><?php endforeach; ?></tr>
        <tr><td>Premium airdrop guides</td><td class="no">—</td><?php foreach ($plans as $p): ?><td class="yes">✓</td><?php endforeach; ?></tr>
        <tr><td>AI Tutor</td><td>1 question/day</td><?php foreach ($plans as $p): ?><td class="yes">Unlimited</td><?php endforeach; ?></tr>
        <tr><td>Certificates</td><td class="no">—</td><?php foreach ($plans as $p): ?><td class="yes">✓</td><?php endforeach; ?></tr>
      </tbody>
    </table>
  </div>

  <!-- FAQ -->
  <div class="card" style="margin-top:var(--space-6);max-width:720px" data-reveal>
    <h2 style="font-size:var(--text-lg);margin-bottom:var(--space-2)">Frequently asked questions</h2>
    <div class="faq-item">
      <div class="faq-question" onclick="this.parentElement.classList.toggle('open')">
        Can I cancel anytime? <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="transition:transform .2s"><polyline points="6 9 12 15 18 9"/></svg>
      </div>
      <div class="faq-answer">Yes — cancel anytime from your dashboard. You keep access until your current period ends, no partial refunds.</div>
    </div>
    <div class="faq-item">
      <div class="faq-question" onclick="this.parentElement.classList.toggle('open')">
        What happens when my plan expires? <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="transition:transform .2s"><polyline points="6 9 12 15 18 9"/></svg>
      </div>
      <div class="faq-answer">We'll email you as your renewal date approaches — 7 and 1 days out on Monthly, 30, 7, and 1 days out on Yearly — and once more if it lapses. After that, your account reverts to Free — your progress is always saved.</div>
    </div>
  </div>
</div>

<script src="https://js.paystack.co/v1/inline.js"></script>
<script>
document.querySelectorAll('.checkout-btn').forEach(btn => {
  btn.addEventListener('click', async () => {
    btn.disabled = true;
    btn.textContent = 'Preparing checkout…';
    try {
      const res = await fetch('<?= SITE_URL ?>/upgrade.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=start_checkout&plan_id=' + btn.dataset.planId
      });
      const data = await res.json();
      if (data.error) { showToast(data.error, 'error'); btn.disabled = false; btn.textContent = 'Try again'; return; }
      initPaystackPayment(data.email, data.amount, data.reference, data.public_key, '<?= SITE_URL ?>/payment-callback.php');
    } catch (e) {
      showToast('Something went wrong. Please try again.', 'error');
      btn.disabled = false;
    }
  });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
