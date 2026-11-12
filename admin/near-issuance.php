<?php
/**
 * Web3Edu NEAR — Issuer Dashboard
 *
 * Admin-only page for viewing pending NEAR credential issuance requests
 * and submitting transaction hashes after local signing with near-cli-rs.
 *
 * Access restricted to the designated issuer admin (NEAR_ISSUER_ADMIN_ID).
 * Never handles, displays, or transmits the issuer's private key.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/near-issuance.php';

use function Web3Edu\Near\Issuance\getPendingIssuances;
use function Web3Edu\Near\Issuance\isIssuerAdmin;
use function Web3Edu\Near\Issuance\generateIssuanceCommand;
use function Web3Edu\Near\Issuance\getBackfillCandidates;
use function Web3Edu\Near\Issuance\queueCertificateForNear;

if (!isIssuerAdmin()) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><title>Forbidden</title></head><body>';
    echo '<h1>403 Forbidden</h1>';
    echo '<p>You are not the designated NEAR credential issuer.</p>';
    echo '</body></html>';
    exit;
}

require_once __DIR__ . '/../includes/csrf.php';

$adminTitle = 'NEAR Credential Issuance';

// Admin-controlled backfill: queue SELECTED existing certificates. Every
// selected id is re-checked server-side by queueCertificateForNear() (revoked,
// expired, no wallet, already queued/on-chain are all rejected), so a forged or
// stale form can never queue an ineligible certificate.
$backfillReport = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backfill') {
    requireCsrf();
    $ids = array_unique(array_map('intval', (array)($_POST['cert_ids'] ?? [])));
    $report = ['queued' => 0, 'skipped' => [], 'errors' => 0];
    foreach ($ids as $cid) {
        if ($cid <= 0) { continue; }
        $r = queueCertificateForNear($cid, 'admin_backfill');
        if ($r['status'] === 'queued') {
            $report['queued']++;
        } elseif ($r['status'] === 'error') {
            $report['errors']++;
        } else {
            $report['skipped'][] = "#{$cid}: {$r['reason']}";
        }
    }
    $_SESSION['near_backfill_report'] = $report;
    header('Location: ' . SITE_URL . '/admin/near-issuance.php');
    exit;
}
if (!empty($_SESSION['near_backfill_report'])) {
    $backfillReport = $_SESSION['near_backfill_report'];
    unset($_SESSION['near_backfill_report']);
}
$backfillCandidates = getBackfillCandidates();

$requests = getPendingIssuances();

// Build CLI command info for each pending request
$commandInfo = [];
foreach ($requests as $req) {
    $argsFilePath = sprintf(
        'C:\\Users\\maxin\\AppData\\Local\\Temp\\opencode\\near_issuance_args_%d.json',
        (int)$req['id']
    );
    $commandInfo[(int)$req['id']] = generateIssuanceCommand(
        $req['near_account_id'],
        $req['course_id'],
        $req['metadata_hash'],
        $argsFilePath
    );
}

require_once __DIR__ . '/header.php';
?>

<h1 class="admin-page-title">NEAR Credential Issuance</h1>

<?php if ($backfillReport): ?>
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:16px 20px;margin-bottom:16px;font-size:0.85rem">
    Backfill: <strong><?= (int)$backfillReport['queued'] ?></strong> queued<?php if ($backfillReport['errors']): ?>,
    <strong style="color:#dc2626"><?= (int)$backfillReport['errors'] ?> database error(s) &mdash; see PHP error log</strong><?php endif; ?>.
    <?php if ($backfillReport['skipped']): ?>
        <div style="color:var(--text-muted);margin-top:6px">Skipped: <?= htmlspecialchars(implode('; ', $backfillReport['skipped'])) ?></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="admin-dash-grid">
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <h2 style="font-size:1rem;font-weight:700">Pending Issuance Requests</h2>
            <a href="<?= SITE_URL ?>/admin/certificates-issued.php" class="btn-ghost" style="padding:6px 14px;font-size:0.78rem">All Certificates</a>
        </div>

        <?php if (empty($requests)): ?>
        <p style="color:var(--text-muted);font-size:0.875rem;text-align:center;padding:20px">No pending NEAR issuance requests.</p>
        <?php else: ?>
        <table class="admin-table">
            <thead>
            <tr>
                <th style="width:14%">Certificate ID</th>
                <th style="width:18%">Learner</th>
                <th style="width:22%">NEAR Account</th>
                <th style="width:18%">Course</th>
                <th style="width:12%">Status</th>
                <th style="width:16%">Created</th>
                <th style="width:auto">Action</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($requests as $r): ?>
            <tr>
                <td style="font-size:0.82rem;word-break:break-all"><?= htmlspecialchars($r['certificate_code']) ?></td>
                <td style="font-size:0.82rem"><?= htmlspecialchars($r['username'] ?? 'Unknown') ?></td>
                <td style="font-size:0.82rem;word-break:break-all"><?= htmlspecialchars($r['near_account_id']) ?></td>
                <td style="font-size:0.82rem"><?= htmlspecialchars($r['course_id']) ?></td>
                <td style="font-size:0.82rem">
                    <span style="padding:3px 8px;border-radius:20px;font-size:0.7rem;font-weight:700;
                        <?= $r['status'] === 'REQUESTED' ? 'background:rgba(245,158,11,0.15);color:#d97706' : 'background:rgba(99,102,241,0.15);color:#818cf8' ?>">
                        <?= htmlspecialchars($r['status']) ?>
                    </span>
                </td>
                <td style="font-size:0.82rem;color:var(--text-muted)"><?= date('M j, H:i', strtotime($r['created_at'])) ?></td>
                <td>
                    <?php if ($r['status'] === 'REQUESTED'): ?>
                    <button type="button"
                            class="btn btn-primary btn-sm issue-btn"
                            data-request-id="<?= (int)$r['id'] ?>"
                            data-args-json="<?= htmlspecialchars($commandInfo[(int)$r['id']]['args_json'], ENT_QUOTES) ?>"
                            data-command="<?= htmlspecialchars($commandInfo[(int)$r['id']]['command'], ENT_QUOTES) ?>">
                        Show CLI Command
                    </button>
                    <?php endif; ?>
                    <?php if (in_array($r['status'], ['REQUESTED', 'UNCERTAIN'], true)): ?>
                    <form method="POST" action="<?= SITE_URL ?>/api/near-issuer-submit.php" style="display:inline" onsubmit="return submitTxHash(this)">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES) ?>">
                        <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                        <input type="text" name="transaction_hash" placeholder="tx hash" style="width:120px;padding:4px;font-size:0.78rem" required>
                        <button type="submit" class="btn btn-ghost btn-sm">Submit</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <h2 style="font-size:1rem;font-weight:700">Issuer CLI Command</h2>
        </div>
        <p style="color:var(--text-muted);font-size:0.875rem;margin-bottom:16px">
            Copy the command below and run it locally in PowerShell to sign and broadcast
            the NEAR transaction. The issuer's private key never leaves your machine.
        </p>
        <div style="background:var(--bg-dark);border:1px solid var(--border);border-radius:var(--radius);padding:16px;font-family:monospace;font-size:0.75rem;color:var(--text);overflow-x:auto" id="cliCommandDisplay">
            <!-- Command displayed here after selection -->
        </div>
        <p style="color:var(--text-muted);font-size:0.8rem;margin:16px 0 8px">Save this JSON to the <code>file-args</code> path used in the command:</p>
        <pre style="background:var(--bg-dark);border:1px solid var(--border);border-radius:var(--radius);padding:16px;font-size:0.75rem;color:var(--text);overflow-x:auto;margin:0" id="argsJsonDisplay"></pre>
        <div style="margin-top:12px">
            <button type="button" class="btn btn-ghost btn-sm" id="copyCmdBtn" disabled>Copy Command</button>
            <span class="field-hint" style="margin-left:8px">Gas: 300 TGas (initial estimate)</span>
        </div>
    </div>
</div>

<?php
$reasonLabels = [
    'no_near_wallet'         => 'Learner has no linked NEAR wallet',
    'revoked'                => 'Certificate revoked',
    'expired'                => 'Certificate expired',
    'incomplete_certificate' => 'Missing title or code',
    'learner_not_found'      => 'Learner account missing',
];
$eligibleCount = count(array_filter($backfillCandidates, fn($c) => $c['eligible']));
?>
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px;margin-top:24px">
    <h2 style="font-size:1rem;font-weight:700;margin-bottom:6px">Existing Certificates Not Yet Queued</h2>
    <p style="color:var(--text-muted);font-size:0.82rem;margin-bottom:16px">
        Certificates issued before NEAR queueing existed, or issued while the learner had no linked wallet.
        Nothing is queued automatically: tick the ones you want and queue them. Eligibility is re-checked on submit.
    </p>
    <?php if (empty($backfillCandidates)): ?>
        <p style="color:var(--text-muted);font-size:0.875rem;text-align:center;padding:12px">Every certificate is already queued or on-chain.</p>
    <?php else: ?>
    <form method="POST" action="<?= SITE_URL ?>/admin/near-issuance.php">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="backfill">
        <table class="admin-table">
            <thead><tr>
                <th style="width:5%"></th><th>Certificate</th><th>Learner</th><th>Title</th><th>Issued</th><th>NEAR Account</th><th>Eligibility</th>
            </tr></thead>
            <tbody>
            <?php foreach ($backfillCandidates as $c): ?>
            <tr style="<?= $c['eligible'] ? '' : 'opacity:0.6' ?>">
                <td><?php if ($c['eligible']): ?><input type="checkbox" name="cert_ids[]" value="<?= (int)$c['id'] ?>" checked><?php endif; ?></td>
                <td style="font-size:0.82rem;word-break:break-all"><?= htmlspecialchars($c['certificate_code']) ?></td>
                <td style="font-size:0.82rem"><?= htmlspecialchars($c['username']) ?></td>
                <td style="font-size:0.82rem"><?= htmlspecialchars((string)$c['achievement_title']) ?></td>
                <td style="font-size:0.82rem;color:var(--text-muted)"><?= date('M j, Y', strtotime($c['issued_at'])) ?></td>
                <td style="font-size:0.82rem;word-break:break-all"><?= htmlspecialchars((string)($c['near_account_id'] ?? '—')) ?></td>
                <td style="font-size:0.78rem"><?= $c['eligible'] ? 'Eligible' : htmlspecialchars($reasonLabels[$c['reason']] ?? $c['reason']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div style="margin-top:12px">
            <button type="submit" class="btn btn-primary btn-sm" <?= $eligibleCount ? '' : 'disabled' ?>>Queue selected (<?= (int)$eligibleCount ?> eligible)</button>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
let currentCommand = '';
let currentArgsJson = '';

document.querySelectorAll('.issue-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        currentCommand = this.dataset.command;
        currentArgsJson = this.dataset.argsJson;
        const display = document.getElementById('cliCommandDisplay');
        display.textContent = currentCommand;
        document.getElementById('argsJsonDisplay').textContent = currentArgsJson;
        document.getElementById('copyCmdBtn').disabled = false;
    });
});

document.getElementById('copyCmdBtn').addEventListener('click', function() {
    navigator.clipboard.writeText(currentCommand).then(() => {
        const orig = this.textContent;
        this.textContent = 'Copied!';
        setTimeout(() => { this.textContent = orig; }, 1500);
    });
});

function submitTxHash(form) {
    const txHash = form.querySelector('input[name="transaction_hash"]').value.trim();
    if (!txHash) {
        alert('Please enter a transaction hash');
        return false;
    }
    const csrfToken = form.querySelector('input[name="csrf_token"]').value;
    fetch('<?= SITE_URL ?>/api/near-issuer-submit.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({
            request_id: form.querySelector('input[name="request_id"]').value,
            transaction_hash: txHash
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('Transaction verified and recorded: ' + data.near_credential_id);
            location.reload();
        } else {
            alert('Verification failed: ' + (data.error || 'unknown error'));
        }
    })
    .catch(() => alert('Network error'));
    return false;
}

</script>

<?php require_once __DIR__ . '/footer.php'; ?>