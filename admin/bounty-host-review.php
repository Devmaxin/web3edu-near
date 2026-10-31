<?php
$adminTitle = 'Review Host Application';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/bounties.php';

$db = getDB();
$id = intval($_GET['id'] ?? 0);
$adminId = $_SESSION['admin_id'] ?? null;

$host = getBountyHostById($id);
if (!$host) { header('Location: ' . SITE_URL . '/admin/bounty-hosts.php'); exit; }

$applicant = $db->prepare("SELECT username, email FROM users WHERE id = ?");
$applicant->execute([$host['user_id']]);
$applicant = $applicant->fetch();

$bountyCount = (int)$db->query("SELECT COUNT(*) FROM bounties WHERE host_id = " . (int)$id . " AND deleted_at IS NULL")->fetchColumn();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    $message = trim($_POST['message_to_host'] ?? '');
    $internalNotes = trim($_POST['internal_notes'] ?? '');
    $verified = isset($_POST['is_verified']);
    $feePolicy = ($_POST['fee_policy'] ?? 'standard') === 'exempt' ? 'exempt' : 'standard';

    if ($action === 'approve') {
        approveHostApplication($id, $adminId, $verified, $feePolicy, $internalNotes, $message);
        header('Location: ' . SITE_URL . '/admin/bounty-hosts.php?approved=1'); exit;
    }
    if ($action === 'request_changes') {
        if (!$message) { $errors[] = 'A message to the host is required so they know what to fix.'; }
        else {
            requestHostChanges($id, $adminId, $message, $internalNotes);
            header('Location: ' . SITE_URL . '/admin/bounty-hosts.php?changes_requested=1'); exit;
        }
    }
    if ($action === 'reject') {
        if (!$message) { $errors[] = 'A rejection reason is required.'; }
        else {
            rejectHostApplication($id, $adminId, $message, $internalNotes);
            header('Location: ' . SITE_URL . '/admin/bounty-hosts.php?rejected=1'); exit;
        }
    }
    if ($action === 'suspend') {
        if (!$message) { $errors[] = 'A suspension reason is required.'; }
        else {
            suspendHostApplication($id, $adminId, $message, $internalNotes);
            header('Location: ' . SITE_URL . '/admin/bounty-hosts.php?suspended=1'); exit;
        }
    }
    // Saving notes/verification/fee policy without changing status (e.g. on an already-approved host).
    if ($action === 'save_settings') {
        $db->prepare("UPDATE bounty_hosts SET is_verified=?, fee_policy=?, internal_notes=? WHERE id=?")
           ->execute([$verified ? 1 : 0, $feePolicy, $internalNotes ?: null, $id]);
        header('Location: ' . SITE_URL . '/admin/bounty-host-review.php?id=' . $id . '&saved=1'); exit;
    }

    $host = getBountyHostById($id); // re-fetch in case of validation error above
}
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <h1 class="admin-page-title" style="margin:0">Review Host Application</h1>
    <a href="<?= SITE_URL ?>/admin/bounty-hosts.php" class="btn-ghost btn-sm"><?= lucide('arrow-left', ['size' => 13]) ?> Host Applications</a>
</div>

<?php if (isset($_GET['saved'])): ?><div class="admin-alert admin-alert-success">Saved.</div><?php endif; ?>
<?php if (!empty($errors)): ?><div class="admin-alert admin-alert-error"><?php foreach ($errors as $e): ?><div><?= sanitize($e) ?></div><?php endforeach; ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1.4fr 1fr;gap:20px;align-items:start">

  <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
      <img src="<?= sanitize(imageUrl($host['logo_path'], 'lesson')) ?>" style="width:48px;height:48px;border-radius:10px;object-fit:cover">
      <div>
        <div style="font-weight:700;font-size:1.05rem"><?= sanitize($host['organization_name']) ?></div>
        <span class="status-chip status-<?= $host['status'] ?>"><?= sanitize(hostStatusLabel($host['status'])) ?></span>
      </div>
    </div>

    <table style="width:100%;font-size:0.85rem">
      <tr><td style="color:var(--text-muted);padding:6px 0;width:170px">Applicant</td><td><?= sanitize($applicant['username'] ?? '—') ?></td></tr>
      <tr><td style="color:var(--text-muted);padding:6px 0">Registration Email</td><td><?= sanitize($applicant['email'] ?? '—') ?></td></tr>
      <tr><td style="color:var(--text-muted);padding:6px 0">Public Contact Email</td><td><?= sanitize($host['contact_email']) ?></td></tr>
      <tr><td style="color:var(--text-muted);padding:6px 0">Website</td><td><?php if ($host['website']): ?><a href="<?= sanitize($host['website']) ?>" target="_blank" style="color:var(--primary-bright)"><?= sanitize($host['website']) ?></a><?php else: ?>—<?php endif; ?></td></tr>
      <tr><td style="color:var(--text-muted);padding:6px 0">Country</td><td><?= sanitize($host['country'] ?? '—') ?></td></tr>
      <tr><td style="color:var(--text-muted);padding:6px 0">Organization Type</td><td><?= sanitize($host['organization_type'] ?? '—') ?></td></tr>
      <tr><td style="color:var(--text-muted);padding:6px 0">Social Links</td><td>
        <?php $socials = array_filter(['Twitter' => $host['twitter'], 'Discord' => $host['discord'], 'Telegram' => $host['telegram']]); ?>
        <?= $socials ? sanitize(implode(' · ', array_map(fn($k,$v)=>"$k: $v", array_keys($socials), $socials))) : '—' ?>
      </td></tr>
      <tr><td style="color:var(--text-muted);padding:6px 0">Date Submitted</td><td><?= date('M j, Y g:ia', strtotime($host['created_at'])) ?></td></tr>
    </table>

    <?php if ($host['description']): ?>
      <div style="margin-top:12px"><div style="color:var(--text-muted);font-size:0.78rem;margin-bottom:4px">Description</div><p style="font-size:0.85rem"><?= nl2br(sanitize($host['description'])) ?></p></div>
    <?php endif; ?>

    <?php if ($bountyCount): ?>
      <div style="margin-top:12px;font-size:0.8rem;color:var(--text-muted)"><?= $bountyCount ?> hosted bounty(ies) on record</div>
    <?php endif; ?>
  </div>

  <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px">
    <form method="POST">
      <?= csrfField() ?>
      <div class="form-group">
        <label style="display:flex;align-items:center;gap:8px;font-size:0.85rem;font-weight:600">
          <input type="checkbox" name="is_verified" <?= $host['is_verified'] ? 'checked' : '' ?>> Verified
        </label>
      </div>
      <div class="form-group">
        <label style="font-size:0.8rem;color:var(--text-muted)">Listing Fee Policy</label>
        <select name="fee_policy" class="form-select">
          <option value="standard" <?= $host['fee_policy']==='standard'?'selected':'' ?>>Standard Fee</option>
          <option value="exempt" <?= $host['fee_policy']==='exempt'?'selected':'' ?>>Fee Exempt</option>
        </select>
      </div>
      <div class="form-group">
        <label style="font-size:0.8rem;color:var(--text-muted)">Internal Notes <span style="opacity:.7">(admins only)</span></label>
        <textarea name="internal_notes" class="form-input" rows="2"><?= sanitize($host['internal_notes'] ?? '') ?></textarea>
      </div>
      <div class="form-group">
        <label style="font-size:0.8rem;color:var(--text-muted)">Message to Host <span style="opacity:.7">(shown to applicant — required for Request Changes / Reject / Suspend)</span></label>
        <textarea name="message_to_host" class="form-input" rows="3"><?= sanitize($host['admin_message'] ?? '') ?></textarea>
      </div>

      <div style="display:flex;flex-direction:column;gap:8px;margin-top:14px">
        <button type="submit" name="action" value="approve" class="btn-primary btn-sm" style="justify-content:center"><?= lucide('check', ['size' => 13]) ?> Approve</button>
        <button type="submit" name="action" value="request_changes" class="btn-ghost btn-sm" style="justify-content:center"><?= lucide('message-square', ['size' => 13]) ?> Request Changes</button>
        <button type="submit" name="action" value="reject" class="btn-danger btn-sm" style="justify-content:center"><?= lucide('x', ['size' => 13]) ?> Reject</button>
        <?php if ($host['status'] === 'approved' || $host['status'] === 'suspended'): ?>
          <button type="submit" name="action" value="suspend" class="btn-danger btn-sm" style="justify-content:center"><?= lucide('ban', ['size' => 13]) ?> Suspend</button>
        <?php endif; ?>
        <button type="submit" name="action" value="save_settings" class="btn-ghost btn-sm" style="justify-content:center"><?= lucide('save', ['size' => 13]) ?> Save Settings Only</button>
      </div>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
