<?php
/**
 * Web3Edu NEAR — Public Credential Verification Page
 *
 * Public page to verify NEAR-issued credentials against the smart contract.
 * Accessible via:
 *   /verify-near.php?id=<credential_id>
 *   /verify-near.php?code=<certificate_code> (legacy alias)
 *
 * Reads credential status from the NEAR smart contract via RPC and displays
 * the verification status (Valid / Revoked / Not Found) with a QR code.
 */

declare(strict_types=1);

define('DISABLE_INTERNAL_EMAIL_RUNNER', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/near-issuance.php';


$credentialId = trim($_GET['id'] ?? $_GET['code'] ?? '');
$pageTitle = 'Verify Credential';

// The identifier is normally a Web3Edu certificate code (WEB3-YYYY-NNNNNN). It is
// resolved locally -> issuance record -> credential on the NEAR contract, which is
// always re-read and compared (see verifyCertificateOnNear()). A NEAR credential ID
// (e.g. "cred-0") is still accepted.
$result = null;
if ($credentialId !== '') {
    try {
        $result = \Web3Edu\Near\Issuance\verifyCertificateOnNear($credentialId);
    } catch (\Throwable $e) {
        error_log('[web3edu-near] verify-near failed: ' . $e->getMessage());
        $result = null;
        $verifyError = true;
    }
}

$pageTitle = 'Verify Credential';
define('SKIP_MAINTENANCE_DB_CHECK', true);
require_once __DIR__ . '/includes/header.php';
?>

<div class="container verify-container">
  <div class="verify-card">
    <div class="verify-header">
      <div class="verify-icon">🔍</div>
      <h1>Verify Credential</h1>
      <p class="verify-subtitle">Confirm that a Web3Edu certificate is genuine.</p>
    </div>

    <?php if (!$credentialId): ?>
      <form method="GET" class="verify-form">
        <div class="field">
          <label>Certificate ID</label>
          <input type="text" name="id" class="input" placeholder="WEB3-2026-000012" required value="<?= htmlspecialchars($credentialId) ?>">
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%">Verify</button>
      </form>
    <?php elseif (!$result): ?>
      <div class="verify-result revoked">
        <div class="verify-icon">⚠️</div>
        <h3>Verification temporarily unavailable</h3>
        <p>We could not complete the check. Please try again shortly.</p>
        <a href="<?= SITE_URL ?>/verify-near.php" class="btn btn-ghost">Try Another ID</a>
      </div>
    <?php elseif ($result['overall'] === 'not_found'): ?>
      <div class="verify-result not-found">
        <div class="verify-icon">❌</div>
        <h3>Certificate Not Found</h3>
        <p>"<?= htmlspecialchars($credentialId) ?>" doesn't match any certificate we've issued.</p>
        <?php if (($result['near']['status'] ?? '') === 'unavailable'): ?>
          <p>The NEAR network could not be reached, so credential IDs could not be checked either.</p>
        <?php endif; ?>
        <a href="<?= SITE_URL ?>/verify-near.php" class="btn btn-ghost">Try Another ID</a>
      </div>
    <?php else: ?>
      <?php
      $local = $result['local'];
      $near  = $result['near'];
      $oc    = $near['on_chain'] ?? null;
      $overall = $result['overall'];
      $good = ($overall === 'verified');
      $banner = [
          'verified'      => ['✓ Valid · Verified on NEAR', true],
          'local_only'    => ['✓ Certificate valid · NEAR not verified', true],
          'near_failed'   => ['✕ NEAR verification failed', false],
          'local_invalid' => [$local && $local['status'] === 'expired' ? '⚠ Certificate expired' : '✕ Certificate revoked', false],
          'on_chain_only' => [$near['status'] === 'revoked_on_chain' ? '✕ Revoked on NEAR' : 'On-chain credential · no local record', $near['status'] !== 'revoked_on_chain'],
      ][$overall];
      $statusLabel = $banner[0];
      $okColor = $banner[1];
      $statusColor = $okColor ? 'var(--success)' : 'var(--danger)';
      $statusBg = $okColor ? 'var(--success-soft)' : 'var(--danger-soft)';
      $nearLabels = [
          'verified'           => '✓ Verified on NEAR',
          'not_issued'         => 'No NEAR credential issued',
          'pending'            => 'NEAR issuance pending',
          'not_configured'     => 'NEAR verification not configured',
          'unavailable'        => 'Could not reach NEAR — try again',
          'not_found_on_chain' => '✕ Credential not found on NEAR',
          'revoked_on_chain'   => '✕ Revoked on NEAR',
          'mismatch'           => '✕ On-chain data does not match',
          'on_chain_only'      => 'Exists on NEAR · no local record',
      ];
      $localLabels = ['valid' => '✓ Valid', 'revoked' => '✕ Revoked', 'expired' => '⚠ Expired'];
      $checkLabels = ['learner' => 'Learner account', 'course' => 'Course', 'metadata_hash' => 'Metadata hash', 'issuer' => 'Issuer'];
      $title = $local['achievement_title'] ?? ($oc['course_id'] ?? 'Certificate');
      $learner = $local['username'] ?? ($oc['learner_id'] ?? 'Unknown');
      $code = $local['certificate_code'] ?? $credentialId;
      ?>
      <div class="verify-result <?= $okColor ? 'valid' : 'revoked' ?>">
        <div class="verify-icon"><?= $good ? '✅' : ($okColor ? 'ℹ️' : '❌') ?></div>
        <div style="display:inline-block;padding:4px 14px;border-radius:20px;font-weight:700;font-size:0.85rem;background:<?= $statusBg ?>;color:<?= $statusColor ?>">
          <?= htmlspecialchars($statusLabel) ?>
        </div>
        <h2 style="margin-top:var(--space-2)"><?= htmlspecialchars((string)$title) ?></h2>
        <p style="color:var(--text-muted);margin-bottom:var(--space-4)">Issued to <strong><?= htmlspecialchars((string)$learner) ?></strong></p>

        <div class="credential-details">
          <div class="detail-row">
            <span class="label">Certificate ID</span>
            <span class="value mono"><?= htmlspecialchars((string)$code) ?></span>
          </div>
          <?php if ($local): ?>
          <div class="detail-row">
            <span class="label">Web3Edu certificate</span>
            <span class="value" style="<?= $local['status'] === 'valid' ? '' : 'color:var(--danger)' ?>"><?= htmlspecialchars($localLabels[$local['status']]) ?></span>
          </div>
          <div class="detail-row">
            <span class="label">Date Issued</span>
            <span class="value"><?= !empty($local['issued_at']) ? date('F j, Y', strtotime((string)$local['issued_at'])) : 'N/A' ?></span>
          </div>
          <?php endif; ?>
          <div class="detail-row">
            <span class="label">NEAR credential</span>
            <span class="value" style="<?= in_array($near['status'], ['mismatch','revoked_on_chain','not_found_on_chain'], true) ? 'color:var(--danger)' : '' ?>"><?= htmlspecialchars($nearLabels[$near['status']] ?? $near['status']) ?></span>
          </div>
          <?php if (!empty($near['credential_id'])): ?>
          <div class="detail-row">
            <span class="label">NEAR credential ID</span>
            <span class="value mono"><?= htmlspecialchars((string)$near['credential_id']) ?></span>
          </div>
          <?php endif; ?>
          <?php if ($oc): ?>
          <div class="detail-row">
            <span class="label">On-chain learner</span>
            <span class="value mono"><?= htmlspecialchars((string)($oc['learner_id'] ?? '')) ?></span>
          </div>
          <div class="detail-row">
            <span class="label">Issuer</span>
            <span class="value mono"><?= htmlspecialchars((string)($oc['issuer_id'] ?? 'Unknown')) ?></span>
          </div>
          <?php endif; ?>
          <?php foreach ($near['checks'] as $k => $ok): ?>
          <div class="detail-row">
            <span class="label"><?= htmlspecialchars($checkLabels[$k] ?? $k) ?> matches certificate</span>
            <span class="value" style="<?= $ok ? '' : 'color:var(--danger)' ?>"><?= $ok ? '✓ Match' : '✕ Mismatch' ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php if ($near['message'] !== ''): ?>
          <p><?= htmlspecialchars($near['message']) ?></p>
        <?php endif; ?>

        <div class="verify-qr">
          <img src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=<?= urlencode(SITE_URL . '/verify-near.php?id=' . urlencode((string)$code)) ?>" width="140" height="140" alt="Verification QR code">
          <div class="qr-label">Scan to verify</div>
        </div>

        <?php if ($local && $local['status'] === 'valid'): ?>
          <a href="<?= SITE_URL ?>/certificate-view.php?code=<?= urlencode((string)$code) ?>" class="btn btn-primary" style="margin-top:var(--space-4)">View Full Certificate</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<style>
.verify-container {
  max-width: 640px;
  margin: 0 auto;
  padding: var(--space-4);
}
.verify-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  padding: var(--space-6);
  box-shadow: var(--shadow-card);
}
.verify-header {
  text-align: center;
  margin-bottom: var(--space-6);
}
.verify-icon {
  font-size: 2.5rem;
  margin-bottom: var(--space-2);
}
.verify-header h1 {
  margin: 0 0 var(--space-2) 0;
  font-size: var(--text-2xl);
  font-weight: 700;
}
.verify-subtitle {
  color: var(--text-muted);
  font-size: var(--text-sm);
  margin: 0;
}
.verify-result {
  text-align: center;
  padding: var(--space-6);
  border-radius: var(--radius-lg);
  background: var(--surface-raised);
  border: 1px solid var(--border);
}
.verify-result.valid {
  background: #f0fdf4;
  border-color: #bbf7d0;
}
.verify-result.revoked {
  background: #fef2f2;
  border-color: #fecaca;
}
.verify-result .verify-icon {
  font-size: 3rem;
  margin-bottom: var(--space-2);
}
.verify-result h2 {
  font-size: var(--text-xl);
  font-weight: 700;
  margin-bottom: var(--space-2);
}
.verify-result p {
  color: var(--text-muted);
  margin-bottom: var(--space-4);
}
.credential-details {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  margin-bottom: var(--space-4);
}
.detail-row {
  display: flex;
  justify-content: space-between;
  padding: var(--space-2);
  background: var(--surface);
  border-radius: var(--radius-md);
  border: 1px solid var(--border);
}
.label {
  font-size: var(--text-sm);
  color: var(--text-secondary);
}
.value {
  font-weight: 600;
  color: var(--text-primary);
}
.mono {
  font-family: var(--font-mono);
  font-size: var(--text-sm);
}
.verify-qr {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-top: var(--space-4);
}
.verify-qr img {
  width: 140px;
  height: 140px;
  border-radius: var(--radius-md);
}
.verify-qr .qr-label {
  font-size: var(--text-xs);
  color: var(--text-muted);
  margin-top: var(--space-1);
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>