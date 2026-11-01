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

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/csrf.php';

$db = getDB();

$credentialId = trim($_GET['id'] ?? $_GET['code'] ?? '');
$pageTitle = 'Verify Credential';

$certData = null;
$nearNetwork = getenv('NEAR_NETWORK') ?: 'testnet';
$nearRpcUrl = 'https://test.rpc.fastnear.com'; // NEAR testnet RPC
$contractId = getenv('NEAR_CONTRACT_ID') ?: '';

if ($credentialId) {
    // Query NEAR smart contract for credential status via RPC
    $rpcPayload = json_encode([
        'jsonrpc' => '2.0',
        'id' => 'verify',
        'method' => 'query',
        'params' => [
            'request_type' => 'call_function',
            'finality' => 'final',
            'account_id' => $contractId,
            'method_name' => 'get_credential',
            'args_base64' => base64_encode(json_encode(['certificate_id' => $credentialId])),
        ],
    ]);

    $ch = curl_init($nearRpcUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $rpcPayload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    $rpcResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($rpcResponse && $httpCode === 200) {
        $rpcData = json_decode($rpcResponse, true);
        if ($rpcData && !isset($rpcData['error']) && isset($rpcData['result'])) {
            $result = $rpcData['result'];
            $decoded = base64_decode($result, true);
            if ($decoded !== false) {
                $decodedStr = @mb_convert_encoding($decoded, 'UTF-8', 'UTF-8');
                $certData = json_decode($decodedStr, true);
                if ($certJson = $decodedStr) {
                    $certData = json_decode($certJson, true);
                }
            }
        }
    }

    // Fallback: check local database (for testing without deployed contract)
    if (!$certData) {
        $stmt = $db->prepare("SELECT * FROM certificates WHERE certificate_code = ? LIMIT 1");
        $stmt->execute([$credentialId]);
        $localCert = $stmt->fetch();
        if ($localCert) {
            $certData = [
                'certificate_code' => $cert['certificate_code'] ?? $cert['id'],
                'user_id' => $cert['user_id'],
                'issuer_id' => $cert['user_id'],
                'achievement_title' => $cert['achievement_title'] ?? 'Certificate',
                'issued_at' => $cert['issued_at'],
                'is_revoked' => (int)$cert['is_revoked'],
                'source' => 'local',
            ];
        }
    }
}

$pageTitle = 'Verify Credential';
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
    <?php elseif (!$certData): ?>
      <div class="verify-result not-found">
        <div class="verify-icon">❌</div>
        <h3>Certificate Not Found</h3>
        <p>"<?= htmlspecialchars($credentialId) ?>" doesn't match any certificate we've issued.</p>
        <a href="<?= SITE_URL ?>/verify-near.php" class="btn btn-ghost">Try Another ID</a>
      </div>
    <?php else: ?>
      <?php
      $isValid = !$certData['is_revoked'];
      $statusColor = $isValid ? '#22c55e' : '#f87171';
      $statusLabel = $isValid ? '✓ Valid' : '✕ Revoked';
      $statusBg = $isValid ? '#22c55e22' : '#f8717122';
      ?>
      <div class="verify-result <?php echo $isValid ? 'valid' : 'revoked'; ?>">
        <div class="verify-icon"><?php echo $isValid ? '✅' : '❌'; ?></div>
        <div style="display:inline-block;padding:4px 14px;border-radius:20px;font-weight:700;font-size:0.85rem;background:<?= $statusBg ?>;color:<?= $statusColor ?>">
          <?= $statusLabel ?>
        </div>
        <h2 style="margin-top:var(--space-2)"><?= htmlspecialchars($certData['achievement_title'] ?? 'Certificate') ?></h3>
        <p style="color:var(--text-muted);margin-bottom:var(--space-4)">Issued to <strong><?= htmlspecialchars($certData['user_id'] ?? 'Unknown') ?></strong></p>

        <div class="credential-details">
          <div class="detail-row">
            <span class="label">Certificate ID</span>
            <span class="value mono"><?= htmlspecialchars($certData['certificate_code']) ?></span>
          </div>
          <div class="detail-row">
            <span class="label">Type</span>
            <span class="value"><?= htmlspecialchars($certData['type'] ?? 'Learning Path Completion') ?></span>
          </div>
          <div class="detail-row">
            <span class="label">Date Issued</span>
            <span class="value"><?= isset($certData['issued_at']) ? date('F j, Y', strtotime($certData['issued_at'])) : 'N/A' ?></span>
          </div>
          <div class="detail-row">
            <span class="label">Issuer</span>
            <span class="value"><?= htmlspecialchars($certData['issuer_id'] ?? 'Unknown') ?></span>
          </div>
        </div>

        <div class="verify-qr">
          <img src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=<?= urlencode(SITE_URL . '/verify-near.php?id=' . urlencode($certData['certificate_code'])) ?>" width="140" height="140" alt="Verification QR code">
          <div class="qr-label">Scan to verify</div>
        </div>

        <?php if ($isValid): ?>
          <a href="<?= SITE_URL ?>/certificate-view.php?code=<?= urlencode($certData['certificate_code']) ?>" class="btn btn-primary" style="margin-top:var(--space-4)">View Full Certificate</a>
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
  background: var(--bg-card);
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
  background: var(--bg-card2);
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
  background: var(--bg-card);
  border-radius: var(--radius);
  border: 1px solid var(--border);
}
.label {
  font-size: var(--text-sm);
  color: var(--text-muted);
}
.value {
  font-weight: 600;
  color: var(--text);
}
.mono {
  font-family: monospace;
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
  border-radius: var(--radius);
}
.verify-qr .qr-label {
  font-size: var(--text-xs);
  color: var(--text-muted);
  margin-top: var(--space-1);
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>