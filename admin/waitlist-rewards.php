<?php
/**
 * Admin — Waitlist Rewards
 *
 * Admin-managed replacement for the old automatic waitlist integration. An admin records a waitlist member's email and
 * qualifying referral count here; when that email registers on Web3Edu the reward is credited exactly once
 * (see includes/waitlist_rewards.php for the crediting transaction and its guarantees).
 *
 * Authorization: this project has one admin level (admins table, requireAdmin()). Every state-changing action is a POST
 * protected by requireCsrf(), uses prepared statements, escapes output, and writes an audit row.
 * No public or user-facing route exposes any of this.
 */
$adminTitle = 'Waitlist Rewards';

// Auth + POST handling run BEFORE admin/header.php prints HTML so redirects work (same pattern as admin/maintenance.php).
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/waitlist_rewards.php';
requireAdmin();

$adminId = (int)$_SESSION['admin_id'];
$db      = getDB();

function wlrE($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function wlrFlash(string $msg, bool $ok = true): void { $_SESSION['wlr_flash'] = ['m' => $msg, 'ok' => $ok]; }
function wlrGo(string $url): never { header('Location: ' . $url, true, 303); exit; }
function wlrWhen(?string $ts): string { return $ts ? date('M j, Y g:ia', strtotime($ts)) : '—'; }

$tab = in_array($_GET['tab'] ?? 'records', ['records', 'rules', 'audit'], true) ? ($_GET['tab'] ?? 'records') : 'records';
$id  = (int)($_GET['id'] ?? 0);
$errors = [];
$old    = ['email' => '', 'referral_count' => '0', 'referred_someone' => '', 'admin_notes' => ''];

$creditMessages = [
    'credited'              => 'Credited.',
    'already'               => 'Already credited. Nothing changed.',
    'taken'                 => 'This record was already credited to a different account. Nothing changed.',
    'no_record'             => 'Record not found.',
    'excluded'              => 'This record is excluded from rewards.',
    'rules_not_ready'       => 'Not credited: the reward rules are not ready. Open the Rules tab.',
    'no_account'            => 'No registered account matches this email yet (or the email differs after exact comparison).',
    'user_already_credited' => 'That account has already received a different waitlist record. Nothing changed.',
    'unsafe_engine'         => 'Not credited: a required database table is not InnoDB, so the credit cannot be made atomic.',
    'busy_transaction'      => 'Not credited: a database transaction was already open. Try again.',
    'conflict'              => 'Not credited: a uniqueness guard blocked it (already credited elsewhere). Nothing changed.',
    'error'                 => 'Not credited because of a database error. Nothing changed; see the server error log.',
    'no_user'               => 'The matched account no longer exists.',
    'no_email'              => 'The account has no usable email.',
];

// ---------------------------------------------------------------- POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act = (string)($_POST['action'] ?? '');
    $pid = (int)($_POST['id'] ?? 0);

    if ($act === 'add_record') {
        $r = wlrSaveRecord(null, $_POST, $adminId);
        if ($r['ok']) { wlrFlash('Record created.'); wlrGo('waitlist-rewards.php?id=' . (int)$r['id']); }
        $errors = $r['errors'];
        $old = ['email' => (string)($_POST['email'] ?? ''), 'referral_count' => (string)($_POST['referral_count'] ?? '0'), 'referred_someone' => !empty($_POST['referred_someone']) ? '1' : '', 'admin_notes' => (string)($_POST['admin_notes'] ?? '')];
        if (!empty($r['existing_id'])) $errors['email'] .= ' <a href="waitlist-rewards.php?id=' . (int)$r['existing_id'] . '">Open it</a>.';
    } elseif ($act === 'save_record' && $pid > 0) {
        $r = wlrSaveRecord($pid, $_POST, $adminId);
        if ($r['ok']) { wlrFlash($r['message'] ?? 'Saved.'); wlrGo('waitlist-rewards.php?id=' . $pid); }
        wlrFlash(implode(' ', array_map('strip_tags', $r['errors'])), false);
        wlrGo('waitlist-rewards.php?id=' . $pid);
    } elseif (in_array($act, ['exclude', 'include'], true) && $pid > 0) {
        $r = wlrSetActive($pid, $act === 'include', $adminId, (string)($_POST['reason'] ?? ''));
        $msg = ['ok' => $act === 'include' ? 'Record re-included.' : 'Record excluded from rewards.', 'credited' => 'Already credited: excluding does not claw back XP. Use “Correct a credited reward”.', 'noop' => 'No change.', 'missing' => 'Record not found.'][$r['status']] ?? 'Could not update.';
        wlrFlash($msg, $r['status'] === 'ok' || $r['status'] === 'noop');
        wlrGo('waitlist-rewards.php?id=' . $pid);
    } elseif (in_array($act, ['flag_mismatch', 'unflag_mismatch'], true) && $pid > 0) {
        $r = wlrSetMismatchFlag($pid, $act === 'flag_mismatch', $adminId);
        wlrFlash($r['status'] === 'ok' ? 'Updated.' : 'Could not update.', $r['status'] === 'ok');
        wlrGo('waitlist-rewards.php?id=' . $pid);
    } elseif ($act === 'credit_now' && $pid > 0) {
        $r = wlrAdminCreditNow($pid, $adminId);
        if ($r['status'] === 'credited') wlrFlash('Credited ' . (int)$r['total_xp'] . ' XP (' . (int)$r['signup_xp'] . ' signup + ' . (int)$r['referral_xp'] . ' referral).');
        else wlrFlash($creditMessages[$r['status']] ?? 'Not credited.', in_array($r['status'], ['already'], true));
        wlrGo('waitlist-rewards.php?id=' . $pid);
    } elseif ($act === 'correct' && $pid > 0) {
        $newRaw = trim((string)($_POST['new_count'] ?? ''));
        $expRaw = trim((string)($_POST['expected_count'] ?? ''));
        if (!ctype_digit($newRaw) || !ctype_digit($expRaw) || strlen($newRaw) > 7 || strlen($expRaw) > 7) {
            wlrFlash('Enter a whole number for the new referral count.', false);
        } elseif (trim((string)($_POST['confirm'] ?? '')) !== 'CORRECT') {
            wlrFlash('Type CORRECT to confirm. Nothing changed.', false);
        } else {
            $r = wlrApplyCorrection($pid, (int)$newRaw, (int)$expRaw, (string)($_POST['reason'] ?? ''), $adminId);
            $m = [
                'ok' => 'Correction applied: ' . (int)($r['applied'] ?? 0) . ' XP change recorded.' . (($r['applied'] ?? 0) !== ($r['delta'] ?? 0) ? ' (Reduced so the balance stays at 0.)' : ''),
                'bad_reason' => 'A reason (max 300 characters) is required.', 'bad_count' => 'That referral count is out of range.',
                'stale' => 'The record changed since you opened it (credited count is now ' . (int)($r['current'] ?? 0) . '). Reload and try again. Nothing changed.',
                'no_change' => 'The new count equals the credited count. Nothing changed.', 'not_credited' => 'This record has not been credited, so edit it normally.',
                'no_rate' => 'No “XP per referral” rate is available (the rule was inactive at credit time and is inactive now). Activate the rule first.',
                'too_large' => 'That correction exceeds the safety limit.', 'missing' => 'Record not found.', 'no_user' => 'The credited account no longer exists.',
                'unsafe_engine' => 'A required table is not InnoDB; correction refused.', 'error' => 'Database error. Nothing changed.', 'conflict' => 'A uniqueness guard blocked this correction (likely a double submit). Nothing changed.',
            ][$r['status']] ?? 'Could not apply.';
            wlrFlash($m, $r['status'] === 'ok');
        }
        wlrGo('waitlist-rewards.php?id=' . $pid);
    } elseif ($act === 'save_rules') {
        $new = [];
        foreach ([WLR_RULE_WELCOME, WLR_RULE_SIGNUP, WLR_RULE_REFERRAL] as $k) {
            $raw = trim((string)($_POST['xp'][$k] ?? ''));
            $new[$k] = ['xp' => ctype_digit($raw) && strlen($raw) <= 7 ? (int)$raw : -1, 'active' => !empty($_POST['active'][$k])];
        }
        $r = wlrSaveRules($new, !empty($_POST['credit_unverified']), $adminId);
        wlrFlash($r['ok'] ? 'Rules saved. Existing credits are not changed.' : implode(' ', $r['errors']), $r['ok']);
        wlrGo('waitlist-rewards.php?tab=rules');
    } else {
        wlrFlash('Unknown or incomplete request. Nothing changed.', false);
        wlrGo('waitlist-rewards.php');
    }
}

// ---------------------------------------------------------------- data for rendering
$flash = $_SESSION['wlr_flash'] ?? null;
unset($_SESSION['wlr_flash']);
$rules = wlrLoadRules($db);
$creditUnverified = getSetting('waitlist_credit_unverified_email', '1') === '1';

$page   = max(1, (int)($_GET['page'] ?? 1));
$search = trim((string)($_GET['q'] ?? ''));
$fstat  = (string)($_GET['status'] ?? '');
$record = null;
if ($id > 0) $record = wlrGet($id);
if ($id === 0 && $tab === 'records') $list = wlrList($search, $fstat, $page);

require_once __DIR__ . '/header.php';
?>
<style>
.wlr-badge{display:inline-block;padding:2px 10px;border-radius:999px;font-size:.72rem;font-weight:700;white-space:nowrap;border:1px solid var(--border)}
.wlr-credited{background:rgba(11,121,88,.15);color:#0B7958;border-color:rgba(11,121,88,.4)}
.wlr-ready{background:rgba(99,102,241,.16);color:var(--primary-bright);border-color:rgba(99,102,241,.45)}
.wlr-waiting{background:rgba(148,163,184,.14);color:var(--text-muted)}
.wlr-excluded{background:rgba(248,113,113,.14);color:#f87171;border-color:rgba(248,113,113,.4)}
.wlr-mismatch{background:rgba(245,158,11,.15);color:#f59e0b;border-color:rgba(245,158,11,.4)}
.wlr-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;margin-bottom:18px}
.wlr-card h2{font-size:.92rem;font-weight:700;margin:0 0 12px}
.wlr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px}
.wlr-kv{width:100%;border-collapse:collapse;font-size:.86rem}
.wlr-kv th{text-align:left;color:var(--text-muted);font-weight:600;padding:6px 10px 6px 0;width:46%}
.wlr-kv td{padding:6px 0}
.wlr-row{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}
.wlr-muted{color:var(--text-muted);font-size:.78rem}
.wlr-err{color:#f87171;font-size:.8rem;margin-top:4px}
.wlr-warn{border:1px solid rgba(245,158,11,.45);background:rgba(245,158,11,.1);border-radius:var(--radius-lg);padding:12px 16px;margin-bottom:18px;font-size:.86rem}
@media (max-width:640px){.wlr-kv th{width:auto}}
</style>

<?php if ($flash): ?><div class="admin-alert <?= $flash['ok'] ? 'admin-alert-success' : 'admin-alert-error' ?>"><?= wlrE($flash['m']) ?></div><?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0">Waitlist Rewards</h1>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="?tab=records" class="btn-<?= ($tab === 'records') ? 'primary' : 'ghost' ?> btn-sm">Records</a>
        <a href="?tab=rules" class="btn-<?= $tab === 'rules' ? 'primary' : 'ghost' ?> btn-sm">Rules &amp; settings</a>
        <a href="?tab=audit" class="btn-<?= $tab === 'audit' ? 'primary' : 'ghost' ?> btn-sm">Audit log</a>
    </div>
</div>

<?php
$sigOn = $rules[WLR_RULE_SIGNUP]['active']; $refOn = $rules[WLR_RULE_REFERRAL]['active'];
if (!$sigOn || !$refOn): ?>
<div class="wlr-warn"><b>Waitlist rewards are not fully enabled.</b>
    <?= !$sigOn ? 'The “Waitlist signup reward” rule is inactive, so no record can be credited. ' : '' ?>
    <?= !$refOn ? 'The “per referral” rule is inactive, so records with referrals cannot be credited. ' : '' ?>
    Nothing is paid until you set values and activate them in <a href="?tab=rules">Rules &amp; settings</a>. Matching members are never lost while rules are off: they stay “ready to credit”.</div>
<?php endif; ?>

<?php if ($id > 0): /* ============================== DETAIL ============================== */ ?>
<?php if (!$record): ?>
    <div class="wlr-card">Record not found. <a href="waitlist-rewards.php">Back to records</a></div>
<?php else:
    $credited = $record['credited_at'] !== null;
    $calc = wlrCalculate((int)$record['referral_count'], $rules);
    $acct = null;
    if ($record['matched_user_id']) { $q = $db->prepare('SELECT id, username, email FROM users WHERE id = ?'); $q->execute([(int)$record['matched_user_id']]); $acct = $q->fetch() ?: null; }
    $credAcct = null;
    if ($record['credited_user_id']) { $q = $db->prepare('SELECT id, username, email, total_xp FROM users WHERE id = ?'); $q->execute([(int)$record['credited_user_id']]); $credAcct = $q->fetch() ?: null; }
    $aud = $db->prepare('SELECT * FROM waitlist_reward_audit WHERE reward_id = ? ORDER BY id DESC LIMIT 200'); $aud->execute([$id]); $auditRows = $aud->fetchAll();
    $ledger = [];
    if ($credAcct) {
        $lq = $db->prepare("SELECT * FROM user_activity_log WHERE user_id = ? AND ((reference_type = 'waitlist_reward' AND reference_id = ?) OR reference_type = 'waitlist_reward_audit') ORDER BY id DESC LIMIT 50");
        $lq->execute([(int)$credAcct['id'], $id]); $ledger = $lq->fetchAll();
    }
    $ready = !$credited && (int)$record['is_active'] === 1 && $acct !== null && $calc['ready'];
?>
<p><a href="waitlist-rewards.php" class="btn-ghost btn-sm">← All records</a></p>
<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px">
    <h2 style="margin:0;font-size:1.15rem;word-break:break-all"><?= wlrE($record['email_normalized']) ?></h2>
    <span class="wlr-badge wlr-<?= wlrE($record['status']) ?>"><?= wlrE(WLR_STATUSES[$record['status']] ?? $record['status']) ?></span>
</div>

<div class="wlr-grid">
    <div class="wlr-card">
        <h2><?= $credited ? 'Credited reward (snapshot)' : 'Calculated reward (at current rules)' ?></h2>
        <table class="wlr-kv">
        <?php if ($credited): ?>
            <tr><th>Waitlist signup reward</th><td><b><?= (int)$record['credited_signup_xp'] ?></b> XP</td></tr>
            <tr><th>Referral reward</th><td><?= (int)$record['credited_referral_count'] ?> × <?= (int)$record['credited_referral_rate'] ?> = <b><?= (int)$record['credited_referral_xp'] ?></b> XP</td></tr>
            <tr><th>Net total credited</th><td><b><?= (int)$record['credited_total_xp'] ?></b> XP <?php if ((int)$record['correction_count'] > 0): ?><span class="wlr-muted">(after <?= (int)$record['correction_count'] ?> correction<?= (int)$record['correction_count'] > 1 ? 's' : '' ?>)</span><?php endif; ?></td></tr>
            <tr><th>Credited</th><td><?= wlrE(wlrWhen($record['credited_at'])) ?> via <?= wlrE($record['credited_via']) ?></td></tr>
            <tr><th>Account</th><td><?= $credAcct ? '#' . (int)$credAcct['id'] . ' · ' . wlrE($credAcct['username'] ?: $credAcct['email']) . ' <span class="wlr-muted">(balance ' . (int)$credAcct['total_xp'] . ' XP)</span>' : '<span class="wlr-muted">account no longer exists</span>' ?></td></tr>
        <?php else: ?>
            <tr><th>Waitlist signup reward</th><td><b><?= (int)$calc['signup_xp'] ?></b> XP <?= $sigOn ? '' : '<span class="wlr-muted">(rule inactive)</span>' ?></td></tr>
            <tr><th>Referral reward</th><td><?= (int)$record['referral_count'] ?> × <?= (int)$rules[WLR_RULE_REFERRAL]['xp'] ?> = <b><?= (int)$calc['referral_xp'] ?></b> XP <?= $refOn ? '' : '<span class="wlr-muted">(rule inactive)</span>' ?></td></tr>
            <tr><th>Total eligible waitlist XP</th><td><b><?= (int)$calc['total_xp'] ?></b> XP</td></tr>
            <tr><th>Ready to pay?</th><td><?= $calc['ready'] ? 'Yes' : 'No — ' . wlrE(wlrReasonText($calc['reason'])) ?></td></tr>
            <tr><th>Matched account</th><td><?= $acct ? '#' . (int)$acct['id'] . ' · ' . wlrE($acct['username'] ?: $acct['email']) : '<span class="wlr-muted">none yet</span>' ?></td></tr>
        <?php endif; ?>
            <tr><th>Referred someone (note)</th><td><?= (int)$record['referred_someone'] ? 'Yes' : 'No' ?> <span class="wlr-muted">· the numeric count is what pays</span></td></tr>
            <tr><th>Created / updated</th><td class="wlr-muted"><?= wlrE(wlrWhen($record['created_at'])) ?> / <?= wlrE(wlrWhen($record['updated_at'])) ?></td></tr>
        </table>
        <p class="wlr-muted" style="margin:12px 0 0">The Web3Edu registration welcome bonus is separate and is not included here.</p>
    </div>

    <div class="wlr-card">
        <h2><?= $credited ? 'Notes' : 'Edit record' ?></h2>
        <form method="POST">
            <?= csrfField() ?><input type="hidden" name="action" value="save_record"><input type="hidden" name="id" value="<?= (int)$id ?>">
            <div class="form-group"><label class="form-label">Email</label>
                <input type="email" name="email" class="form-input" required maxlength="254" value="<?= wlrE($record['email_normalized']) ?>" <?= $credited ? 'readonly' : '' ?>></div>
            <div class="form-group"><label class="form-label">Qualifying referrals</label>
                <input type="number" name="referral_count" class="form-input" min="0" max="<?= WLR_MAX_REFERRALS ?>" step="1" required value="<?= (int)$record['referral_count'] ?>" <?= $credited ? 'readonly' : '' ?>></div>
            <div class="form-group"><label style="display:flex;gap:8px;align-items:center;font-size:.84rem"><input type="checkbox" name="referred_someone" value="1" <?= (int)$record['referred_someone'] ? 'checked' : '' ?>> Member referred someone <span class="wlr-muted">(optional note)</span></label></div>
            <div class="form-group"><label class="form-label">Admin notes</label>
                <textarea name="admin_notes" class="form-input" rows="3" maxlength="<?= WLR_MAX_NOTES ?>"><?= wlrE($record['admin_notes']) ?></textarea></div>
            <button class="btn-primary btn-sm" type="submit"><?= $credited ? 'Save notes' : 'Save changes' ?></button>
            <?php if ($credited): ?><span class="wlr-muted">Email and count are locked after crediting.</span><?php endif; ?>
        </form>
    </div>
</div>

<?php if (!$credited): ?>
<div class="wlr-card">
    <h2>Actions</h2>
    <div class="wlr-row">
        <?php if ($ready): ?>
        <form method="POST" onsubmit="return confirm('Credit <?= (int)$calc['total_xp'] ?> XP to account #<?= (int)$acct['id'] ?> now? This happens once and is audited.');">
            <?= csrfField() ?><input type="hidden" name="action" value="credit_now"><input type="hidden" name="id" value="<?= (int)$id ?>">
            <button class="btn-primary btn-sm" type="submit">Credit <?= (int)$calc['total_xp'] ?> XP now</button>
        </form>
        <?php endif; ?>
        <form method="POST" class="wlr-row">
            <?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
            <?php if ((int)$record['is_active'] === 1): ?>
                <input type="hidden" name="action" value="exclude">
                <input type="text" name="reason" class="form-input" placeholder="Reason (optional)" maxlength="300" style="width:240px">
                <button class="btn-danger btn-sm" type="submit">Exclude from rewards</button>
            <?php else: ?>
                <input type="hidden" name="action" value="include">
                <button class="btn-ghost btn-sm" type="submit">Re-include in rewards</button>
            <?php endif; ?>
        </form>
        <?php if (!$acct): ?>
        <form method="POST">
            <?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
            <input type="hidden" name="action" value="<?= (int)$record['email_mismatch_flag'] ? 'unflag_mismatch' : 'flag_mismatch' ?>">
            <button class="btn-ghost btn-sm" type="submit"><?= (int)$record['email_mismatch_flag'] ? 'Clear “email does not match” flag' : 'Flag: member registered with a different email' ?></button>
        </form>
        <?php endif; ?>
    </div>
    <?php if (!$acct && (int)$record['email_mismatch_flag']): ?><p class="wlr-muted" style="margin-top:10px">Flagged: the member says they registered with another address. Correct the email above to the exact address on their Web3Edu account, then the record becomes “ready to credit”.</p><?php endif; ?>
</div>
<?php else: ?>
<div class="wlr-card">
    <h2>Correct a credited reward</h2>
    <p class="wlr-muted" style="margin-top:0">The referral count and XP are locked after crediting. If the original count was wrong, record a correction here. It changes the account&rsquo;s XP by (new count − credited count) × <?= (int)($record['credited_referral_rate'] > 0 ? $record['credited_referral_rate'] : $rules[WLR_RULE_REFERRAL]['xp']) ?> XP, writes an XP-ledger entry and an audit entry, and can be negative. It can never pay the same reward twice.</p>
    <form method="POST" class="wlr-row" onsubmit="return confirm('Apply this correction to the member\'s XP? It is audited and cannot be undone except by another correction.');">
        <?= csrfField() ?><input type="hidden" name="action" value="correct"><input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="expected_count" value="<?= (int)$record['credited_referral_count'] ?>">
        <div class="form-group"><label class="form-label">Credited count: <?= (int)$record['credited_referral_count'] ?> → new count</label>
            <input type="number" name="new_count" class="form-input" min="0" max="<?= WLR_MAX_REFERRALS ?>" step="1" required style="width:150px"></div>
        <div class="form-group" style="flex:1;min-width:220px"><label class="form-label">Reason (required)</label>
            <input type="text" name="reason" class="form-input" required maxlength="300"></div>
        <div class="form-group"><label class="form-label">Type CORRECT</label>
            <input type="text" name="confirm" class="form-input" required autocomplete="off" style="width:120px"></div>
        <div class="form-group"><button class="btn-danger btn-sm" type="submit">Apply correction</button></div>
    </form>
</div>
<?php if ($ledger): ?>
<div class="wlr-card">
    <h2>XP ledger entries for this reward</h2>
    <div class="admin-table-wrap"><table class="admin-table">
        <thead><tr><th>Action</th><th>XP</th><th>Reference</th><th>When</th></tr></thead><tbody>
        <?php foreach ($ledger as $l): ?><tr><td><?= wlrE($l['action_key']) ?></td><td style="font-weight:700"><?= (int)$l['xp_awarded'] >= 0 ? '+' : '' ?><?= (int)$l['xp_awarded'] ?></td><td class="wlr-muted"><?= wlrE($l['reference_type']) ?> #<?= (int)$l['reference_id'] ?></td><td class="wlr-muted"><?= wlrE(wlrWhen($l['created_at'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="wlr-card">
    <h2>Audit history</h2>
    <div class="admin-table-wrap"><table class="admin-table">
        <thead><tr><th>When</th><th>Action</th><th>By</th><th>XP</th><th>Details</th></tr></thead><tbody>
        <?php foreach ($auditRows as $a): ?><tr>
            <td class="wlr-muted"><?= wlrE(wlrWhen($a['created_at'])) ?></td><td><?= wlrE($a['action']) ?></td>
            <td class="wlr-muted"><?= wlrE($a['admin_label'] ?? ($a['action'] === 'credited' ? 'automatic' : '—')) ?></td>
            <td><?= (int)$a['xp_delta'] ? ((int)$a['xp_delta'] > 0 ? '+' : '') . (int)$a['xp_delta'] : '—' ?></td>
            <td class="wlr-muted" style="max-width:420px;word-break:break-word;font-size:.74rem"><?= wlrE($a['details'] ?? '') ?></td></tr><?php endforeach; ?>
        <?php if (!$auditRows): ?><tr><td colspan="5" class="wlr-muted" style="text-align:center;padding:18px">No history.</td></tr><?php endif; ?>
        </tbody></table></div>
</div>
<?php endif; /* record */ ?>

<?php elseif ($tab === 'rules'): /* ============================== RULES ============================== */ ?>
<div class="wlr-card">
    <h2>Reward rules</h2>
    <p class="wlr-muted" style="margin-top:0">These use the platform&rsquo;s existing XP rules table. All three start <b>inactive at 0 XP</b>; nothing is paid until you set a value and tick Active. Changing a rule never edits XP that was already credited.</p>
    <form method="POST">
        <?= csrfField() ?><input type="hidden" name="action" value="save_rules">
        <div class="admin-table-wrap"><table class="admin-table">
            <thead><tr><th>Rule</th><th>XP</th><th>Active</th></tr></thead><tbody>
            <?php $desc = [
                WLR_RULE_WELCOME  => 'Welcome bonus: paid to EVERY new account when it registers (password or Google). Not specific to the waitlist.',
                WLR_RULE_SIGNUP   => 'Waitlist signup reward: paid once to a member who has an eligible record, when they register.',
                WLR_RULE_REFERRAL => 'XP per qualifying referral: record\'s referral count × this value, paid with the signup reward.',
            ]; foreach ($desc as $k => $d): ?>
            <tr><td style="max-width:420px"><b><?= wlrE($rules[$k]['label']) ?></b><div class="wlr-muted"><?= wlrE($d) ?></div></td>
                <td><input type="number" class="form-input" name="xp[<?= wlrE($k) ?>]" min="0" max="<?= WLR_MAX_RULE_XP ?>" step="1" required value="<?= (int)$rules[$k]['xp'] ?>" style="width:120px"></td>
                <td><label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="active[<?= wlrE($k) ?>]" value="1" <?= $rules[$k]['active'] ? 'checked' : '' ?>> Active</label></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <p class="wlr-muted">Waitlist reward = signup XP + (qualifying referrals × XP per referral). A record is credited only when every rule that applies to it is Active.</p>
        <div class="form-group" style="margin-top:14px"><label style="display:flex;gap:8px;align-items:flex-start;font-size:.86rem"><input type="checkbox" name="credit_unverified" value="1" <?= $creditUnverified ? 'checked' : '' ?> style="margin-top:3px">
            <span>Credit password registrations immediately<br><span class="wlr-muted">Email+password sign-ups do not verify email ownership, so someone could register with another person&rsquo;s waitlist email. Untick to auto-credit only Google (verified) sign-ups; other matching members then show “ready to credit” for you to credit manually.</span></span></label></div>
        <button class="btn-primary btn-sm" type="submit">Save rules</button>
    </form>
</div>

<?php elseif ($tab === 'audit'): /* ============================== AUDIT ============================== */
    $allAudit = $db->query('SELECT a.*, r.email_normalized FROM waitlist_reward_audit a LEFT JOIN waitlist_rewards r ON r.id = a.reward_id ORDER BY a.id DESC LIMIT 200')->fetchAll(); ?>
<div class="admin-table-wrap"><table class="admin-table">
    <thead><tr><th>When</th><th>Record</th><th>Action</th><th>By</th><th>XP</th><th>Details</th></tr></thead><tbody>
    <?php foreach ($allAudit as $a): ?><tr>
        <td class="wlr-muted"><?= wlrE(wlrWhen($a['created_at'])) ?></td>
        <td><?= $a['reward_id'] ? '<a href="waitlist-rewards.php?id=' . (int)$a['reward_id'] . '">' . wlrE($a['email_normalized']) . '</a>' : '<span class="wlr-muted">settings</span>' ?></td>
        <td><?= wlrE($a['action']) ?></td><td class="wlr-muted"><?= wlrE($a['admin_label'] ?? ($a['action'] === 'credited' ? 'automatic' : '—')) ?></td>
        <td><?= (int)$a['xp_delta'] ? ((int)$a['xp_delta'] > 0 ? '+' : '') . (int)$a['xp_delta'] : '—' ?></td>
        <td class="wlr-muted" style="max-width:420px;word-break:break-word;font-size:.74rem"><?= wlrE($a['details'] ?? '') ?></td></tr><?php endforeach; ?>
    <?php if (!$allAudit): ?><tr><td colspan="6" class="wlr-muted" style="text-align:center;padding:24px">No history yet.</td></tr><?php endif; ?>
    </tbody></table></div>

<?php else: /* ============================== RECORDS LIST ============================== */ ?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:20px">
    <?php foreach (WLR_STATUSES as $k => $lbl): ?>
    <a href="?status=<?= wlrE($k) ?>" class="admin-stat-card" style="text-decoration:none"><div class="value"><?= (int)$list['counts'][$k] ?></div><div class="label"><?= wlrE($lbl) ?></div></a>
    <?php endforeach; ?>
</div>

<div class="wlr-card">
    <h2>Add a waitlist member</h2>
    <p class="wlr-muted" style="margin-top:0">The person does not need to have registered yet. Use the exact email they will register with on Web3Edu (case and surrounding spaces are normalized).</p>
    <?php if (!empty($errors['form'])): ?><div class="wlr-err"><?= wlrE($errors['form']) ?></div><?php endif; ?>
    <form method="POST" class="wlr-row">
        <?= csrfField() ?><input type="hidden" name="action" value="add_record">
        <div class="form-group" style="flex:2;min-width:240px"><label class="form-label">Email</label>
            <input type="email" name="email" class="form-input" required maxlength="254" value="<?= wlrE($old['email']) ?>" placeholder="member@example.com">
            <?php if (!empty($errors['email'])): ?><div class="wlr-err"><?= $errors['email'] /* contains an internal link built from an integer id */ ?></div><?php endif; ?></div>
        <div class="form-group" style="width:150px"><label class="form-label">Qualifying referrals</label>
            <input type="number" name="referral_count" class="form-input" min="0" max="<?= WLR_MAX_REFERRALS ?>" step="1" value="<?= wlrE($old['referral_count']) ?>">
            <?php if (!empty($errors['referral_count'])): ?><div class="wlr-err"><?= wlrE($errors['referral_count']) ?></div><?php endif; ?></div>
        <div class="form-group" style="flex:2;min-width:200px"><label class="form-label">Notes (optional)</label>
            <input type="text" name="admin_notes" class="form-input" maxlength="<?= WLR_MAX_NOTES ?>" value="<?= wlrE($old['admin_notes']) ?>">
            <?php if (!empty($errors['admin_notes'])): ?><div class="wlr-err"><?= wlrE($errors['admin_notes']) ?></div><?php endif; ?></div>
        <div class="form-group"><label style="display:flex;gap:6px;align-items:center;font-size:.84rem;padding-bottom:10px"><input type="checkbox" name="referred_someone" value="1" <?= $old['referred_someone'] ? 'checked' : '' ?>> Referred someone</label></div>
        <div class="form-group"><button class="btn-primary btn-sm" type="submit">Add record</button></div>
    </form>
</div>

<form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px">
    <input type="text" name="q" class="form-input" placeholder="Search by email…" value="<?= wlrE($search) ?>" style="width:260px">
    <select name="status" class="form-select" style="width:280px" onchange="this.form.submit()">
        <option value="">All statuses</option>
        <?php foreach (WLR_STATUSES as $k => $lbl): ?><option value="<?= wlrE($k) ?>" <?= $fstat === $k ? 'selected' : '' ?>><?= wlrE($lbl) ?></option><?php endforeach; ?>
    </select>
    <button class="btn-primary btn-sm" type="submit">Filter</button>
    <?php if ($search !== '' || $fstat !== ''): ?><a class="btn-ghost btn-sm" href="waitlist-rewards.php">Clear</a><?php endif; ?>
</form>

<div class="admin-table-wrap"><table class="admin-table">
    <thead><tr><th>Email</th><th>Referrals</th><th>Signup XP</th><th>Referral XP</th><th>Total XP</th><th>Status</th><th>Account</th><th>Credited</th><th></th></tr></thead><tbody>
    <?php foreach ($list['rows'] as $r):
        $cr = $r['credited_at'] !== null;
        $c  = wlrCalculate((int)$r['referral_count'], $rules);
        $sx = $cr ? (int)$r['credited_signup_xp'] : $c['signup_xp'];
        $rx = $cr ? (int)$r['credited_referral_xp'] : $c['referral_xp'];
        $tx = $cr ? (int)$r['credited_total_xp'] : $c['total_xp'];
        $uid = $cr ? $r['credited_user_id'] : $r['matched_user_id']; ?>
    <tr>
        <td style="font-weight:600;word-break:break-all"><a href="?id=<?= (int)$r['id'] ?>"><?= wlrE($r['email_normalized']) ?></a></td>
        <td><?= (int)$r['referral_count'] ?></td>
        <td><?= $cr || $rules[WLR_RULE_SIGNUP]['active'] ? (int)$sx : '<span class="wlr-muted">rule off</span>' ?></td>
        <td><?= $cr || $rules[WLR_RULE_REFERRAL]['active'] || (int)$r['referral_count'] === 0 ? (int)$rx : '<span class="wlr-muted">rule off</span>' ?></td>
        <td style="font-weight:700"><?= (int)$tx ?></td>
        <td><span class="wlr-badge wlr-<?= wlrE($r['status']) ?>"><?= wlrE(WLR_STATUSES[$r['status']] ?? $r['status']) ?></span></td>
        <td class="wlr-muted"><?= $uid ? '#' . (int)$uid : '—' ?></td>
        <td class="wlr-muted"><?= $cr ? wlrE(wlrWhen($r['credited_at'])) : '—' ?></td>
        <td><a class="btn-ghost btn-sm" href="?id=<?= (int)$r['id'] ?>">Open</a></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$list['rows']): ?><tr><td colspan="9" style="text-align:center;color:var(--text-muted);padding:24px">No matching records.</td></tr><?php endif; ?>
    </tbody></table></div>

<?php if ($list['pages'] > 1): ?>
<div style="display:flex;gap:6px;margin-top:16px;flex-wrap:wrap">
    <?php for ($i = 1; $i <= $list['pages']; $i++): ?>
    <a href="?page=<?= $i ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($fstat) ?>" class="btn-<?= $i === $list['page'] ? 'primary' : 'ghost' ?> btn-sm"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>
<p class="wlr-muted" style="margin-top:10px"><?= (int)$list['total'] ?> record<?= $list['total'] === 1 ? '' : 's' ?> match.</p>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
