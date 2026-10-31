<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/bounty_auth.php';
require_once __DIR__ . '/includes/countries.php';
require_once __DIR__ . '/includes/payments/PaystackProvider.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$bountyId = (int)($_GET['id'] ?? 0);
$bounty = $bountyId ? getBounty($bountyId) : null;

if (!$bounty || !canManageBounty($userId, $bountyId)) {
    http_response_code(404);
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="container section"><div class="learn-empty"><h3>Not found</h3><p>This payment page isn\'t available.</p></div></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$pageTitle = 'Pay Listing Fee';
$extraCss  = [SITE_URL . '/assets/css/airdrops.css', SITE_URL . '/assets/css/bounty.css'];

$db = getDB();
$errors = []; $success = '';

// Latest payment attempt (if any) for status display
$payStmt = $db->prepare("SELECT * FROM bounty_payments WHERE bounty_id = ? ORDER BY created_at DESC LIMIT 1");
$payStmt->execute([$bountyId]);
$latestPayment = $payStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_crypto_proof') {
    requireCsrf();
    $screenshotPath = null;
    if (!empty($_FILES['screenshot']['name'])) {
        $screenshotPath = handleImageUpload('screenshot', 'bounty-payments', $errors);
    }
    if (empty($_POST['tx_hash']) || empty($_POST['network']) || empty($_POST['amount'])) {
        $errors[] = 'Transaction hash, network, and amount are required.';
    } else {
        submitCryptoBountyPayment($bountyId, $userId, [
            'network' => $_POST['network'], 'tx_hash' => $_POST['tx_hash'], 'amount' => $_POST['amount'], 'notes' => $_POST['notes'] ?? '',
        ], $screenshotPath);
        $success = 'Thanks! Your payment is under review — we\'ll notify you once it\'s approved.';
        $payStmt->execute([$bountyId]);
        $latestPayment = $payStmt->fetch();
    }
}

$listingFeeKobo = (int)$bounty['listing_fee_kobo'];
$listingFeeNaira = number_format($listingFeeKobo / 100, 2);
$currency = bountyListingFeeCurrency();

$solanaWallet = getSetting('bounty_solana_wallet', '');
$ethWallet    = getSetting('bounty_ethereum_wallet', '');
$tonWallet    = getSetting('bounty_ton_wallet', '');

$solanaSuggested = getSetting('bounty_solana_suggested_amount', '');
$ethSuggested    = getSetting('bounty_ethereum_suggested_amount', '');
$tonSuggested    = getSetting('bounty_ton_suggested_amount', '');

$paystackOn = bountyPaymentMethodEnabled('paystack');
$cryptoOn   = bountyPaymentMethodEnabled('crypto');

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/bounty_profile_modal.php';
?>

<div class="container section">
  <div class="page-header" style="padding:0 0 var(--space-5)">
    <div class="section-label">Payment</div>
    <h1 class="page-header-title"><?= htmlspecialchars($bounty['title']) ?></h1>
  </div>

  <?php if ($bounty['status'] === 'published'): ?>
    <div class="alert alert-success">This bounty is already published — no payment needed.</div>
  <?php elseif ($latestPayment && $latestPayment['status'] === 'under_review'): ?>
    <div class="alert alert-success">Your crypto payment is <?= strtolower(bountyPaymentStatusBadge('under_review')['label']) ?>. We'll email you once it's approved.</div>
  <?php elseif ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
  <?php elseif (!$paystackOn && !$cryptoOn): ?>
    <div class="alert alert-danger">Payments are temporarily unavailable — please check back shortly or contact support.</div>
  <?php else: ?>

  <div class="bounty-payment-steps" style="display:flex;gap:var(--space-2);align-items:center;margin-bottom:var(--space-4);font-size:var(--text-xs);color:var(--text-muted)">
    <span style="display:flex;align-items:center;gap:4px"><?= lucide('check-circle', ['size' => 14]) ?> Review</span>
    <span>→</span>
    <span style="color:var(--primary-bright);font-weight:700;display:flex;align-items:center;gap:4px"><?= lucide('credit-card', ['size' => 14]) ?> Listing Fee</span>
  </div>

  <div class="bounty-stat-strip">
    <div class="bounty-stat-box"><span class="value"><?= $currency ?> <?= $listingFeeNaira ?></span><span class="label">Listing Fee</span></div>
    <div class="bounty-stat-box"><span class="value"><?= htmlspecialchars($bounty['reward_display'] ?: '—') ?></span><span class="label">Prize Pool</span></div>
    <div class="bounty-stat-box"><span class="value status-chip status-<?= $bounty['status'] ?>"><?= ucwords(str_replace('_',' ',$bounty['status'])) ?></span><span class="label">Status</span></div>
  </div>

  <?php if (!empty($errors)): ?><div class="alert alert-danger" style="max-width:560px"><?php foreach ($errors as $e) echo '<div>'.htmlspecialchars($e).'</div>'; ?></div><?php endif; ?>

  <div class="grid-2" style="max-width:760px">
    <?php if ($paystackOn): ?>
    <div class="card payment-method-card" onclick="showPaystack()">
      <h3 style="font-size:var(--text-base);display:flex;align-items:center;gap:6px"><?= lucide('credit-card', ['size' => 16]) ?> Paystack</h3>
      <p style="font-size:var(--text-sm);color:var(--text-secondary)">Pay instantly by card or bank transfer. Approved automatically.</p>
    </div>
    <?php endif; ?>
    <?php if ($cryptoOn): ?>
    <div class="card payment-method-card" onclick="showCrypto()">
      <h3 style="font-size:var(--text-base);display:flex;align-items:center;gap:6px"><?= lucide('coins', ['size' => 16]) ?> Crypto</h3>
      <p style="font-size:var(--text-sm);color:var(--text-secondary)">Pay with Solana, Ethereum, or TON. Reviewed manually by our team.</p>
    </div>
    <?php endif; ?>
  </div>

  <!-- Paystack -->
  <?php if ($paystackOn): ?>
  <div class="card" id="paystackPanel" style="display:none;margin-top:var(--space-5);max-width:480px">
    <h3 style="font-size:var(--text-base)">Pay <?= $currency ?> <?= $listingFeeNaira ?> via Paystack</h3>
    <button class="btn btn-primary" style="width:100%;justify-content:center" onclick="payWithPaystack()">Pay Now</button>
  </div>
  <?php endif; ?>

  <!-- Crypto -->
  <?php if ($cryptoOn): ?>
  <div class="card" id="cryptoPanel" style="display:none;margin-top:var(--space-5);max-width:520px">
    <h3 style="font-size:var(--text-base)">Choose a Network</h3>
    <p style="font-size:var(--text-xs);color:var(--text-muted)">Listing fee: <?= $currency ?> <?= $listingFeeNaira ?>. Send the suggested amount below for your chosen network, then confirm.</p>

    <?php foreach ([
        'TON'      => ['wallet' => $tonWallet,    'suggested' => $tonSuggested],
        'Solana'   => ['wallet' => $solanaWallet, 'suggested' => $solanaSuggested],
        'Ethereum' => ['wallet' => $ethWallet,    'suggested' => $ethSuggested],
    ] as $network => $info): if (!$info['wallet']) continue; ?>
      <div class="wallet-row" style="flex-wrap:wrap;align-items:flex-start;gap:10px">
        <div style="flex:1;min-width:180px">
          <div style="font-size:var(--text-xs);color:var(--text-muted);font-weight:600"><?= $network ?></div>
          <?php if ($info['suggested']): ?>
            <div style="font-size:var(--text-sm);margin:4px 0 6px"><span style="color:var(--text-muted)">Suggested Amount:</span> <strong><?= htmlspecialchars($info['suggested']) ?></strong></div>
          <?php endif; ?>
          <div class="wallet-address" id="wallet-<?= $network ?>"><?= htmlspecialchars($info['wallet']) ?></div>
        </div>
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=<?= urlencode($info['wallet']) ?>" width="60" height="60" alt="<?= $network ?> QR code">
        <button type="button" class="btn-ghost btn-sm copy-btn" data-copied-label="Copied" data-default-label="Copy" onclick="copyWallet(this, 'wallet-<?= $network ?>')"><?= lucide('copy', ['size' => 12]) ?> <span class="copy-btn-label">Copy</span></button>
      </div>
    <?php endforeach; ?>

    <button class="btn btn-primary" style="width:100%;justify-content:center;margin-top:var(--space-3);display:flex;align-items:center;gap:6px" onclick="document.getElementById('cryptoProofForm').style.display='block'"><?= lucide('check-circle', ['size' => 15]) ?> I Have Paid</button>

    <form method="POST" id="cryptoProofForm" style="display:none;margin-top:var(--space-4)" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="submit_crypto_proof">
      <div class="form-group"><label class="form-label">Network</label>
        <select name="network" class="form-select" required>
          <?php foreach (['TON','Solana','Ethereum'] as $n): ?><option value="<?= $n ?>"><?= $n ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Transaction Hash</label><input type="text" name="tx_hash" class="form-input" required></div>
      <div class="form-group"><label class="form-label">Amount Sent</label><input type="text" name="amount" class="form-input" required></div>
      <div class="form-group"><label class="form-label">Screenshot <span style="color:var(--text-muted);font-weight:400">(optional)</span></label><input type="file" name="screenshot" class="form-input" accept="image/*"></div>
      <div class="form-group"><label class="form-label">Notes <span style="color:var(--text-muted);font-weight:400">(optional)</span></label><textarea name="notes" class="form-input" rows="2"></textarea></div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">Submit for Review</button>
    </form>
  </div>
  <?php endif; // cryptoOn ?>
  <?php endif; // published / under_review / disabled / else ?>
</div>

<script src="https://js.paystack.co/v1/inline.js"></script>
<script>
function showPaystack() { document.getElementById('paystackPanel').style.display='block'; document.getElementById('cryptoPanel').style.display='none'; }
function showCrypto() { document.getElementById('cryptoPanel').style.display='block'; document.getElementById('paystackPanel').style.display='none'; }

function copyWallet(btn, elId) {
  const text = document.getElementById(elId).textContent;
  navigator.clipboard.writeText(text).then(() => {
    const label = btn.querySelector('.copy-btn-label');
    const original = btn.dataset.defaultLabel || 'Copy';
    if (label) label.textContent = btn.dataset.copiedLabel || 'Copied';
    btn.classList.add('copied');
    clearTimeout(btn._copyResetTimer);
    btn._copyResetTimer = setTimeout(() => {
      if (label) label.textContent = original;
      btn.classList.remove('copied');
    }, 2000);
  });
}

function payWithPaystack() {
  fetch('<?= SITE_URL ?>/api/init-bounty-payment.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'bounty_id=<?= $bountyId ?>&csrf_token=<?= urlencode(csrfToken()) ?>',
  })
  .then(r => r.json())
  .then(data => {
    if (!data.success) { alert(data.error || 'Could not start payment.'); return; }
    initPaystackPayment(data.email, data.amount, data.reference, data.public_key, '<?= SITE_URL ?>/bounty-payment-callback.php?bounty_id=<?= $bountyId ?>');
  });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
