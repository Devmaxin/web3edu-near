<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/certificates.php';
requireLogin();
requireProfileComplete();

$pageTitle = 'My Certificates';
$db = getDB();
$userId = (int)$_SESSION['user_id'];

$stmt = $db->prepare("
    SELECT c.*, ct.name AS template_name, cty.label AS type_label, cty.icon AS type_icon
    FROM certificates c
    LEFT JOIN certificate_templates ct ON ct.id = c.template_id
    LEFT JOIN certificate_types cty ON cty.id = c.certificate_type_id
    WHERE c.user_id=? AND c.is_revoked=0
    ORDER BY c.issued_at DESC
");
$stmt->execute([$userId]);
$certificates = $stmt->fetchAll();

foreach ($certificates as &$cert) {
    $cert['is_expired'] = !empty($cert['expires_at']) && strtotime($cert['expires_at']) < time();
    $meta = json_decode($cert['metadata'] ?? '{}', true) ?: [];
    $cert['allow_redownload'] = !array_key_exists('allow_redownload', $meta) || $meta['allow_redownload'];
    $cert['display_title'] = $cert['achievement_title'] ?: ($cert['template_name'] ?? 'Certificate');
}
unset($cert);

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header container">
  <div class="section-label">Your achievements</div>
  <h1 class="page-header-title">Certificates</h1>
  <p class="page-header-subtitle">Every certificate you've earned — guides, quizzes, badges, and special achievements — verifiable by anyone with the link.</p>
</div>

<div class="container section" style="padding-top:0">
  <?php if (empty($certificates)): ?>
    <div class="learn-empty">
      <h3>No certificates yet</h3>
      <p>Complete a learning path to earn your first certificate.</p>
      <a href="<?= SITE_URL ?>/learn.php" class="btn btn-primary" style="margin-top:var(--space-3)">Browse guides →</a>
    </div>
  <?php else: ?>
    <div class="grid-3">
      <?php foreach ($certificates as $cert): ?>
        <div class="card card-interactive" style="text-align:center;<?= $cert['is_expired'] ? 'opacity:0.6' : '' ?>">
          <div style="font-size:2.5rem;margin-bottom:var(--space-3)"><?= $cert['type_icon'] ?: '🎓' ?></div>
          <span class="badge badge-accent"><?= htmlspecialchars($cert['type_label'] ?? 'Certificate') ?></span>
          <h3 style="font-size:var(--text-lg);margin:var(--space-2) 0"><?= htmlspecialchars($cert['display_title']) ?></h3>
          <div class="content-card-meta" style="justify-content:center"><span class="mono"><?= htmlspecialchars($cert['certificate_code']) ?></span></div>
          <p style="font-size:var(--text-sm);margin:var(--space-2) 0">
            Issued <?= date('F j, Y', strtotime($cert['issued_at'])) ?>
            <?php if ($cert['is_expired']): ?><br><span style="color:var(--color-danger, #f87171)">Expired <?= date('F j, Y', strtotime($cert['expires_at'])) ?></span><?php endif; ?>
          </p>
          <a href="<?= SITE_URL ?>/certificate-view.php?code=<?= urlencode($cert['certificate_code']) ?>" class="btn btn-primary" style="width:100%;justify-content:center;margin-bottom:var(--space-2)">View & Download</a>
          <a href="<?= SITE_URL ?>/certificate/verify.php?id=<?= urlencode($cert['certificate_code']) ?>" class="btn btn-ghost" style="width:100%;justify-content:center" target="_blank">Verify link ↗</a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
