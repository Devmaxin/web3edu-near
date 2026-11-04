<?php
/**
 * Web3Edu NEAR — Skill Passport (Public Profile)
 *
 * Public page displaying a user's NEAR-linked credentials and learning achievements.
 * Accessible via:
 *   /skill-passport.php?near=<near_account_id>
 *   /skill-passport.php?u=<user_id>
 *
 * Reads credential data from the NEAR smart contract via RPC and displays
 * the user's Skill Passport with QR verification URL for each credential.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/csrf.php';

$db = getDB();

$nearAccountId = trim($_GET['near'] ?? '');
$userId = intval($_GET['u'] ?? '');

// Resolve user identity
$targetUserId = null;
$nearAccountIdResolved = null;

if (!empty($nearAccountId)) {
    $stmt = $db->prepare("SELECT user_id FROM user_wallets WHERE near_account_id = ?");
    $stmt->execute([$nearAccountId]);
    $row = $stmt->fetch();
    if ($row) {
        $targetUserId = (int)$row['user_id'];
        $nearAccountIdResolved = $nearAccountId;
    } else {
        http_response_code(404);
        $pageTitle = 'Skill Passport Not Found';
        require_once __DIR__ . '/includes/header.php';
        echo '<section class="container" style="text-align:center;padding:80px 20px">
                <div style="font-size:2.5rem;margin-bottom:12px">🔍</div>
                <h1>Skill Passport Not Found</h1>
                <p style="color:var(--text-muted);margin-top:8px">No account linked to this NEAR wallet.</p>
              </section>';
        require_once __DIR__ . '/includes/footer.php';
        exit;
    }
} elseif ($userId > 0) {
    $targetUserId = (int)$userId;
    $stmt = $db->prepare("SELECT near_account_id FROM user_wallets WHERE user_id = ? ORDER BY is_primary DESC, created_at ASC LIMIT 1");
    $stmt->execute([$targetUserId]);
    $row = $stmt->fetch();
    $nearAccountIdResolved = $row ? $row['near_account_id'] : null;
} else {
    http_response_code(400);
    echo 'Missing parameter: ?near=<account_id> or ?u=<user_id>';
    exit;
}

// Fetch user profile
$stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$targetUserId]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    $pageTitle = 'Skill Passport Not Found';
    require_once __DIR__ . '/includes/header.php';
    echo '<section class="container" style="text-align:center;padding:80px 20px">
            <div style="font-size:2.5rem;margin-bottom:12px">🔍</div>
            <h1>Skill Passport Not Found</h1>
            <p style="color:var(--text-muted);margin-top:8px">User account not found.</p>
          </section>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Fetch linked NEAR wallets
$nearWallets = [];
$stmt = $db->prepare("SELECT id, near_account_id, public_key, is_primary, created_at FROM user_wallets WHERE user_id = ? ORDER BY is_primary DESC, created_at ASC");
$stmt->execute([$targetUserId]);
$nearWallets = $stmt->fetchAll();

// Fetch credentials from NEAR smart contract via RPC
$nearCredentials = [];
$nearCredentialIds = [];

$nearNetwork = getenv('NEAR_NETWORK') ?: 'testnet';
$nearRpcUrl = 'https://test.rpc.fastnear.com';
$nearContractId = getenv('NEAR_CONTRACT_ID') ?: '';

if ($nearAccountIdResolved && $nearContractId) {

    // Decode NEAR call_function result bytes into JSON.
    $decodeNearResult = function ($rpcData) {
        $result = $rpcData['result']['result'] ?? null;

        if (!is_array($result)) {
            return null;
        }

        $decodedStr = implode('', array_map('chr', $result));
        return json_decode($decodedStr, true);
    };

    // Get credential IDs belonging to this learner.
    $rpcPayload = json_encode([
        'jsonrpc' => '2.0',
        'id' => 'passport',
        'method' => 'query',
        'params' => [
            'request_type' => 'call_function',
            'finality' => 'final',
            'account_id' => $nearContractId,
            'method_name' => 'get_learner_credentials',
            'args_base64' => base64_encode(json_encode([
                'learner_id' => $nearAccountIdResolved,
                'from_index' => null,
                'limit' => null,
            ])),
        ],
    ]);

    $ch = curl_init($nearRpcUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $rpcPayload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);

    $rpcResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($rpcResponse && $httpCode === 200) {
        $rpcData = json_decode($rpcResponse, true);

        if ($rpcData && !isset($rpcData['error'])) {
            $decodedIds = $decodeNearResult($rpcData);

            if (is_array($decodedIds)) {
                $nearCredentialIds = $decodedIds;
            }
        }
    }

    // Fetch the details of each credential.
    foreach ($nearCredentialIds as $certId) {
        $rpcPayload2 = json_encode([
            'jsonrpc' => '2.0',
            'id' => 'passport',
            'method' => 'query',
            'params' => [
                'request_type' => 'call_function',
                'finality' => 'final',
                'account_id' => $nearContractId,
                'method_name' => 'get_credential',
                'args_base64' => base64_encode(json_encode([
                    'credential_id' => $certId,
                ])),
            ],
        ]);

        $ch2 = curl_init($nearRpcUrl);
        curl_setopt_array($ch2, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $rpcPayload2,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);

        $rpcResponse2 = curl_exec($ch2);
        $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);

        if ($rpcResponse2 && $httpCode2 === 200) {
            $rpcData2 = json_decode($rpcResponse2, true);

            if ($rpcData2 && !isset($rpcData2['error'])) {
                $certJson = $decodeNearResult($rpcData2);

                if (is_array($certJson) && !empty($certJson['credential_id'])) {
                    $issuedAt = isset($certJson['issued_at'])
                        ? date(
                            'Y-m-d H:i:s',
                            (int) floor((float) $certJson['issued_at'] / 1000000000)
                        )
                        : null;

                    $nearCredentials[] = [
                        'certificate_code' => $certJson['credential_id'],
                        'user_id' => $certJson['learner_id'] ?? '',
                        'issuer_id' => $certJson['issuer_id'] ?? '',
                        'achievement_title' => $certJson['course_id'] ?? 'Certificate',
                        'issued_at' => $issuedAt,
                        'is_revoked' => (bool) ($certJson['revoked'] ?? false),
                        'source' => 'near',
                    ];
                }
            }
        }
    }
}

// Fallback: if no NEAR contract data, check local database for any stored credentials
// (This is a fallback for testing without a deployed contract)
if (empty($nearCredentials)) {
    $stmt = $db->prepare("SELECT * FROM certificates WHERE user_id = ? ORDER BY issued_at DESC");
    $stmt->execute([$targetUserId]);
    $localCerts = $stmt->fetchAll();
    foreach ($localCerts as $cert) {
        $nearCredentials[] = [
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

$pageTitle = htmlspecialchars($user['username']) . "'s Skill Passport";
require_once __DIR__ . '/includes/header.php';
?>

<div class="container skill-passport">
  <div class="passport-card">
    <div class="passport-header">
      <div class="passport-avatar">
        <img src="<?= htmlspecialchars(avatarUrl($user['avatar'] ?? '')) ?>" alt="Avatar">
      </div>
      <div class="passport-info">
        <h1><?= htmlspecialchars($user['username']) ?></h1>
        <span class="badge <?= isPremium() ? 'badge-accent' : '' ?>"><?= isPremium() ? 'Premium' : 'Free' ?></span>
        <div class="member-since">Member since <?= date('F Y', strtotime($user['created_at'])) ?></div>
      </div>
      <div class="passport-qr">
        <?php if ($nearAccountIdResolved): ?>
          <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=<?= urlencode(SITE_URL . '/verify-near.php?id=' . $nearAccountIdResolved) ?>" alt="Verify">
          <div class="qr-label">Verify Profile</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="passport-section">
      <h3>🔗 Connected NEAL Wallets</h3>
      <?php if (empty($nearWallets)): ?>
        <p class="field-hint">No NEAR wallets linked yet.</p>
      <?php else: ?>
        <div class="wallet-list">
          <?php foreach ($nearWallets as $wallet): ?>
            <div class="wallet-item">
              <span class="wallet-address"><?= htmlspecialchars($wallet['near_account_id']) ?></span>
              <?php if ($wallet['is_primary']): ?>
                <span class="badge badge-accent" style="font-size:var(--text-xs)">Primary</span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="passport-section">
      <h3>🎓 Credentials</h3>
      <?php if (empty($nearCredentials)): ?>
        <div class="alert alert-info">No credentials issued yet.</div>
      <?php else: ?>
        <div class="credential-list">
          <?php foreach ($nearCredentials as $cert): ?>
            <div class="credential-card">
              <div class="credential-header">
                <span class="badge <?php echo $cert['is_revoked'] ? 'badge-danger' : 'badge-accent'; ?>">
                  <?php echo $cert['is_revoked'] ? 'Revoked' : 'Valid'; ?>
                </span>
                <span class="credential-id"><?= htmlspecialchars($cert['certificate_code']) ?></span>
              </div>
              <h4><?= htmlspecialchars($cert['achievement_title'] ?? 'Certificate') ?></h4>
              <p class="field-hint"><?= htmlspecialchars($cert['course_id'] ?? 'Course') ?></p>
              <div class="credential-meta">
                <span>Issued: <?= date('M j, Y', isset($cert['issued_at']) ? strtotime($cert['issued_at']) : time()) ?></span>
                <?php if (!empty($cert['metadata_hash'])): ?>
                  <span>Metadata: <?= htmlspecialchars($cert['metadata_hash']) ?></span>
                <?php endif; ?>
              </div>
              <div class="credential-actions">
                <a href="<?= SITE_URL ?>/verify-near.php?id=<?= urlencode($cert['certificate_code']) ?>" class="btn btn-ghost btn-sm">Verify</button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<style>
.skill-passport {
  max-width: 700px;
  margin: 0 auto;
  padding: var(--space-4);
}
.passport-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius-xl);
  padding: var(--space-6);
  box-shadow: var(--shadow-card);
}
.passport-header {
  display: flex;
  align-items: center;
  gap: var(--space-4);
  margin-bottom: var(--space-6);
}
.passport-avatar {
  width: 80px;
  height: 80px;
  border-radius: 50%;
  overflow: hidden;
  border: 3px solid var(--primary-bright);
}
.passport-avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.passport-info h1 {
  margin: 0;
  font-size: var(--text-2xl);
  font-weight: 700;
}
.passport-info .badge {
  margin-top: var(--space-1);
}
.member-since {
  font-size: var(--text-sm);
  color: var(--text-muted);
  margin-top: var(--space-1);
}
.passport-qr {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-left: auto;
}
.passport-qr img {
  width: 100px;
  height: 100px;
  border-radius: var(--radius);
}
.passport-qr .qr-label {
  font-size: var(--text-xs);
  color: var(--text-muted);
  margin-top: var(--space-1);
}
.passport-section {
  margin-top: var(--space-6);
}
.passport-section h3 {
  font-size: var(--text-lg);
  font-weight: 600;
  margin-bottom: var(--space-3);
  color: var(--text);
}
.wallet-list {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}
.wallet-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-2) var(--space-3);
  background: var(--bg-card2);
  border-radius: var(--radius);
  border: 1px solid var(--border);
}
.wallet-address {
  font-family: monospace;
  font-size: var(--text-sm);
  color: var(--text-secondary);
  word-break: break-all;
}
.credential-list {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}
.credential-card {
  padding: var(--space-4);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
}
.credential-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: var(--space-1);
}
.credential-id {
  font-family: monospace;
  font-size: var(--text-xs);
  color: var(--text-muted);
}
.credential-meta {
  display: flex;
  gap: var(--space-3);
  font-size: var(--text-xs);
  color: var(--text-muted);
  margin-bottom: var(--space-2);
}
.credential-actions {
  display: flex;
  gap: var(--space-2);
}
@media (max-width: 640px) {
  .passport-header {
    flex-direction: column;
    align-items: center;
    text-align: center;
  }
  .passport-qr {
    margin-left: 0;
    margin-top: var(--space-4);
  }
  .passport-card {
    padding: var(--space-4);
  }
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>