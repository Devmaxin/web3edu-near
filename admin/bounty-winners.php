<?php
/**
 * Admin — Select Winners (feature 8). Official Platform Bounties: only
 * admins choose winners (there's no host to do it). Hosted bounties: the
 * host normally does this from their Host Dashboard, but canSelectWinners()
 * lets an admin override here too. External bounties never reach this page
 * — winners are announced on the external platform instead.
 */
$adminTitle = 'Select Winners';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/bounty_auth.php';

$adminId = (int)($_SESSION['admin_id'] ?? 0);
$bountyId = (int)($_GET['id'] ?? 0);
$bounty = $bountyId ? getBounty($bountyId) : null;

if (!$bounty || !canSelectWinners($adminId, $bountyId)) {
    echo '<div class="admin-alert admin-alert-error">This bounty is not eligible for on-platform winner selection.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$errors = []; $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'announce_winners') {
    requireCsrf();
    $winners = [];
    foreach (($_POST['winner_submission_id'] ?? []) as $i => $subId) {
        if (!$subId) continue;
        $winners[] = ['submission_id' => (int)$subId, 'placement' => (int)$_POST['winner_placement'][$i], 'prize' => trim($_POST['winner_prize'][$i] ?? '')];
    }
    if (!empty($winners)) {
        announceBountyWinners($bountyId, $winners);
        $success = 'Winners announced! Certificates and notifications are on their way.';
        $bounty = getBounty($bountyId);
    } else {
        $errors[] = 'Select at least one winner.';
    }
}

$subs = getBountySubmissions($bountyId);
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0">Select Winners — <?= sanitize($bounty['title']) ?></h1>
    <a href="<?= SITE_URL ?>/admin/bounties.php" class="btn-ghost btn-sm"><?= lucide('arrow-left', ['size' => 13]) ?> Bounties</a>
</div>

<?php if ($success): ?><div class="admin-alert admin-alert-success"><?= sanitize($success) ?></div><?php endif; ?>
<?php if (!empty($errors)): ?><div class="admin-alert admin-alert-error"><?php foreach ($errors as $e): ?><div><?= sanitize($e) ?></div><?php endforeach; ?></div><?php endif; ?>

<?php if ($bounty['status'] === 'winners_announced'): ?>
    <div class="admin-alert admin-alert-success">Winners have already been announced for this bounty.</div>
<?php elseif (empty($subs)): ?>
    <p class="admin-empty">No submissions to pick winners from yet.</p>
<?php else: ?>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="announce_winners">
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead><tr><th>Winner?</th><th>User</th><th>Submission</th><th>Placement</th><th>Prize</th></tr></thead>
                <tbody>
                <?php foreach ($subs as $i => $s): ?>
                    <tr>
                        <td><input type="checkbox" onchange="document.getElementById('sid<?= $i ?>').value = this.checked ? '<?= $s['id'] ?>' : ''"><input type="hidden" name="winner_submission_id[]" id="sid<?= $i ?>" value=""></td>
                        <td><?= sanitize($s['username']) ?></td>
                        <td><a href="<?= sanitize($s['submission_url']) ?>" target="_blank" class="btn-ghost btn-sm"><?= lucide('external-link', ['size' => 11]) ?> Link</a></td>
                        <td><input type="number" name="winner_placement[]" class="form-input" min="1" value="<?= $i + 1 ?>" style="width:80px"></td>
                        <td><input type="text" name="winner_prize[]" class="form-input" placeholder="e.g. 1,800 USDC"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <button type="submit" class="btn-primary" style="margin-top:16px;display:inline-flex;align-items:center;gap:6px" onclick="return confirm('Announce winners? This issues certificates and notifies everyone — it cannot be undone.')"><?= lucide('trophy', ['size' => 15]) ?> Announce Winners</button>
    </form>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
