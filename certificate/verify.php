<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/certificates.php';

$code = trim($_GET['id'] ?? $_GET['code'] ?? '');
$pageTitle = 'Verify Certificate';

$cert = $code ? verifyCertificateByCode($code, logHit: true) : null;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header container">
  <div class="section-label">Certificate Verification</div>
  <h1 class="page-header-title">Verify a Certificate</h1>
  <p class="page-header-subtitle">Confirm that a <?= htmlspecialchars(SITE_NAME) ?> certificate is genuine.</p>
</div>

<div class="container section" style="padding-top:0">
  <div style="max-width:640px;margin:0 auto">

  <?php if (!$code): ?>
    <form method="GET" class="card" style="display:flex;gap:var(--space-2)">
      <input type="text" name="id" class="form-input" placeholder="Enter certificate ID, e.g. WEB3-2026-000012" style="flex:1" required>
      <button type="submit" class="btn btn-primary">Verify</button>
    </form>

  <?php elseif (!$cert): ?>
    <div class="alert alert-danger">
      <strong>Not found.</strong> "<?= htmlspecialchars($code) ?>" doesn't match any certificate we've issued. Double-check the ID and try again.
    </div>
    <a href="<?= SITE_URL ?>/certificate/verify.php" class="btn btn-ghost" style="margin-top:var(--space-3)">← Try another ID</a>

  <?php else: ?>
    <?php
      $status = 'valid'; $statusLabel = '✓ Valid'; $statusColor = '#0B7958';
      if ($cert['is_revoked']) { $status = 'revoked'; $statusLabel = '✕ Revoked'; $statusColor = '#f87171'; }
      elseif ($cert['is_expired']) { $status = 'expired'; $statusLabel = '⚠ Expired'; $statusColor = '#fbbf24'; }
    ?>
    <div class="card" style="text-align:center;padding:var(--space-6)">
      <div style="font-size:2rem;margin-bottom:var(--space-2)"><?= $cert['type_icon'] ?: '🎓' ?></div>
      <div style="display:inline-block;padding:4px 14px;border-radius:20px;font-weight:700;font-size:0.85rem;background:<?= $statusColor ?>22;color:<?= $statusColor ?>;margin-bottom:var(--space-3)">
        <?= $statusLabel ?>
      </div>
      <h2 style="font-size:var(--text-xl);margin-bottom:var(--space-2)"><?= htmlspecialchars($cert['achievement_title'] ?: $cert['template_name']) ?></h2>
      <p style="color:var(--text-muted);margin-bottom:var(--space-4)">Issued to <strong><?= htmlspecialchars($cert['username']) ?></strong></p>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3);text-align:left;font-size:var(--text-sm);border-top:1px solid var(--border);padding-top:var(--space-4)">
        <div><span style="color:var(--text-muted);display:block;font-size:0.75rem;text-transform:uppercase">Certificate ID</span><span class="mono"><?= htmlspecialchars($cert['certificate_code']) ?></span></div>
        <div><span style="color:var(--text-muted);display:block;font-size:0.75rem;text-transform:uppercase">Type</span><?= htmlspecialchars($cert['type_label'] ?? '—') ?></div>
        <div><span style="color:var(--text-muted);display:block;font-size:0.75rem;text-transform:uppercase">Date Issued</span><?= date('F j, Y', strtotime($cert['issued_at'])) ?></div>
        <div><span style="color:var(--text-muted);display:block;font-size:0.75rem;text-transform:uppercase">Template</span><?= htmlspecialchars($cert['template_name'] ?? '—') ?></div>
        <?php if ($cert['expires_at']): ?>
        <div><span style="color:var(--text-muted);display:block;font-size:0.75rem;text-transform:uppercase">Expires</span><?= date('F j, Y', strtotime($cert['expires_at'])) ?></div>
        <?php endif; ?>
        <?php if ($cert['is_revoked'] && $cert['revoked_at']): ?>
        <div><span style="color:var(--text-muted);display:block;font-size:0.75rem;text-transform:uppercase">Revoked</span><?= date('F j, Y', strtotime($cert['revoked_at'])) ?></div>
        <?php endif; ?>
      </div>

      <div style="margin-top:var(--space-5)">
        <img src="<?= htmlspecialchars(certificateQrUrl(certificateVerifyUrl($cert['certificate_code'])), ENT_QUOTES) ?>" width="120" height="120" alt="Verification QR code">
      </div>

      <?php if ($status === 'valid'): ?>
      <a href="<?= SITE_URL ?>/certificate-view.php?code=<?= urlencode($cert['certificate_code']) ?>" class="btn btn-primary" style="margin-top:var(--space-4)">View Full Certificate</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
