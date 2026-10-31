<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/certificates.php';

$code = trim($_GET['code'] ?? '');
$db = getDB();
$pageTitle = 'Certificate';

$cert = $code ? verifyCertificateByCode($code, logHit: false) : null;

$renderedHtml = '';
$bgStyle = '';
$allowRedownload = true;
if ($cert) {
    $vars = buildCertificatePlaceholders($cert, $cert, [
        'certificate_title' => $cert['achievement_title'] ?: $cert['template_name'],
        'full_name'         => $cert['username'],
    ]);
    $renderedHtml = renderCertificateBody($cert, $vars);
    if (!empty($cert['background_image'])) {
        $bgStyle = 'background-image:url(' . htmlspecialchars(imageUrl($cert['background_image']), ENT_QUOTES) . ');background-size:cover;background-position:center;';
    }
    $meta = json_decode($cert['metadata'] ?? '{}', true) ?: [];
    $allowRedownload = !array_key_exists('allow_redownload', $meta) || $meta['allow_redownload'];
    $pageTitle = ($cert['achievement_title'] ?: 'Certificate') . ' — Certificate';
}

$isOwner = $cert && isLoggedIn() && (int)$_SESSION['user_id'] === (int)$cert['user_id'];
if ($cert) {
    Analytics::track('certificate_viewed', isLoggedIn() ? (int)$_SESSION['user_id'] : null, [
        'certificate_id' => (int)$cert['id'], 'is_owner' => $isOwner,
    ]);
}
$alreadyDownloadedOnce = $cert && (int)$cert['download_count'] > 0;
$canDownload = $cert && (!$alreadyDownloadedOnce || $allowRedownload);

require_once __DIR__ . '/includes/header.php';
?>

<div class="container section">
  <?php if (!$cert): ?>
    <div class="learn-empty" style="max-width:520px;margin:0 auto">
      <h3>Certificate not found</h3>
      <p>This certificate code doesn't match any issued certificate. Double check the link.</p>
    </div>
  <?php elseif ($cert['is_revoked']): ?>
    <div class="alert alert-danger" style="max-width:520px;margin:0 auto">
      This certificate (<?= htmlspecialchars($cert['certificate_code']) ?>) has been revoked and is no longer valid.
    </div>
  <?php elseif ($cert['is_expired']): ?>
    <div class="alert alert-danger" style="max-width:520px;margin:0 auto">
      This certificate (<?= htmlspecialchars($cert['certificate_code']) ?>) expired on <?= date('F j, Y', strtotime($cert['expires_at'])) ?>.
    </div>
    <div style="max-width:820px;margin:var(--space-5) auto 0;opacity:0.6">
      <div id="certificatePaper" style="background:#fff;border-radius:var(--radius-lg);overflow:hidden;box-shadow:var(--shadow-card);<?= $bgStyle ?>"><?= $renderedHtml ?></div>
    </div>
  <?php else: ?>
    <div style="max-width:820px;margin:0 auto">
      <div class="alert alert-success" style="margin-bottom:var(--space-5)" id="verifyBanner">
        ✓ Verified — this is a genuine <?= htmlspecialchars(SITE_NAME) ?> certificate issued to <strong><?= htmlspecialchars($cert['username']) ?></strong>.
      </div>

      <div id="certificatePaper" style="background:#fff;border-radius:var(--radius-lg);overflow:hidden;box-shadow:var(--shadow-card);<?= $bgStyle ?>">
        <?= $renderedHtml ?>
      </div>

      <div style="text-align:center;margin-top:var(--space-5)" id="certificateActions">
        <?php if ($canDownload): ?>
          <button onclick="downloadCertificatePdf()" class="btn btn-primary btn-lg" id="downloadBtn">⬇ Download PDF</button>
        <?php else: ?>
          <p style="font-size:var(--text-sm);color:var(--text-muted)">Re-download has been disabled for this certificate by the issuer. Use Print instead if you need another copy.</p>
        <?php endif; ?>
        <button onclick="window.print()" class="btn btn-ghost btn-lg">🖨 Print</button>
        <a href="<?= SITE_URL ?>/certificate/verify.php?id=<?= urlencode($cert['certificate_code']) ?>" class="btn btn-ghost btn-lg" target="_blank">🔗 Verification Page</a>
      </div>
    </div>
  <?php endif; ?>
</div>

<style>
  @media print {
    .site-nav, .site-footer, #verifyBanner, #certificateActions, .ai-fab { display: none !important; }
    body { background: #fff !important; }
    #certificatePaper { box-shadow: none !important; }
  }
  #certificatePaper { min-height: 480px; }
</style>

<?php if ($cert && !$cert['is_revoked'] && !$cert['is_expired']): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function downloadCertificatePdf() {
    const btn = document.getElementById('downloadBtn');
    const original = btn.textContent;
    btn.disabled = true; btn.textContent = 'Preparing…';

    fetch('<?= SITE_URL ?>/api/log-certificate-download.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'code=' + encodeURIComponent('<?= addslashes($cert['certificate_code']) ?>')
    }).finally(() => {
        const el = document.getElementById('certificatePaper');
        html2pdf().set({
            margin: 0,
            filename: '<?= addslashes($cert['certificate_code']) ?>.pdf',
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, useCORS: true },
            jsPDF: { unit: 'mm', format: 'a4', orientation: '<?= ($cert['orientation'] ?? 'landscape') === 'portrait' ? 'portrait' : 'landscape' ?>' }
        }).from(el).save().then(() => {
            btn.disabled = false; btn.textContent = original;
        }).catch(() => {
            btn.disabled = false; btn.textContent = original;
            alert('PDF generation failed — try Print instead.');
        });
    });
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
