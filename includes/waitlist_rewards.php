<?php
/**
 * Waitlist Rewards — admin-managed rewards, credited when a member registers.
 *
 * Workflow
 *   1. An admin records a waitlist member's email + qualifying referral count (admin/waitlist-rewards.php).
 *   2. When someone registers on Web3Edu (password OR Google), wlrOnRegistration() runs.
 *   3. If an active record exists for that account's normalized email, the reward
 *          waitlist_signup_XP + (referral_count x XP_per_referral)
 *      is credited ONCE. All amounts come from this database and xp_rules — never from the browser.
 *
 * There is no API, claim link or token. The waitlist site is never contacted.
 *
 * EXACTLY-ONCE DESIGN (why this does not call awardXp())
 *   awardXp() de-duplicates with SELECT-then-INSERT and user_activity_log has no unique key for it, so two
 *   concurrent calls can both pay. awardXp() is deliberately NOT modified (other flows depend on it). Here the whole
 *   credit is ONE InnoDB transaction:
 *       lock the reward row (SELECT ... FOR UPDATE)  ->  re-check it is uncredited  ->
 *       insert ledger rows + bump users.total_xp + stamp the record + write the audit row  ->  COMMIT
 *   Guards that hold even if application code is wrong: UNIQUE(email_normalized), UNIQUE(credited_user_id),
 *   UNIQUE(credit_key), UNIQUE(audit.idem_key). Any failure rolls back everything, so XP, ledger, record and audit
 *   can never disagree, and a retry after failure is safe. Level recalculation (derived data) runs after COMMIT.
 *
 * The welcome bonus is NOT part of this file's credit: it is paid separately through the existing
 * awardXp('registration_welcome', ...) mechanism (once per new account), so it can never be paid twice here.
 */

require_once __DIR__ . '/gamification.php';   // awardXp(), recalculateLevel()

const WLR_RULE_SIGNUP   = 'waitlist_signup';
const WLR_RULE_REFERRAL = 'waitlist_referral';
const WLR_RULE_WELCOME  = 'registration_welcome';
const WLR_MAX_REFERRALS = 100000;      // sanity bound for an admin-entered count
const WLR_MAX_RULE_XP   = 100000;      // sanity bound for a configured rule value
const WLR_MAX_TOTAL_XP  = 10000000;    // a single record can never credit more than this
const WLR_MAX_NOTES     = 1000;

/* ------------------------------------------------------------------ */
/* Email normalisation                                                 */
/* ------------------------------------------------------------------ */

/**
 * Canonical matching form: surrounding whitespace (incl. NBSP / zero-width / BOM) removed, lower-cased (UTF-8),
 * and it must be a valid address. Deliberately NO provider tricks (gmail dots / +tags): the main platform stores
 * the address exactly as entered, so matching must follow the main platform's identity, not the waitlist's.
 * Returns null when the input is not a usable email.
 */
function wlrNormalizeEmail(?string $email): ?string
{
    $e = (string)$email;
    $e = preg_replace('/^[\s\x{00A0}\x{200B}\x{200C}\x{200D}\x{FEFF}]+|[\s\x{00A0}\x{200B}\x{200C}\x{200D}\x{FEFF}]+$/u', '', $e);
    if (!is_string($e) || $e === '' || strlen($e) > 254) return null;
    $e = mb_strtolower($e, 'UTF-8');
    if (filter_var($e, FILTER_VALIDATE_EMAIL) === false) return null;
    return $e;
}

/* ------------------------------------------------------------------ */
/* Rules and calculation                                               */
/* ------------------------------------------------------------------ */

/** @return array<string,array{xp:int,active:bool,label:string}> */
function wlrLoadRules(?PDO $db = null): array
{
    $db = $db ?? getDB();
    $out = [
        WLR_RULE_WELCOME  => ['xp' => 0, 'active' => false, 'label' => 'Registration welcome bonus'],
        WLR_RULE_SIGNUP   => ['xp' => 0, 'active' => false, 'label' => 'Waitlist signup reward'],
        WLR_RULE_REFERRAL => ['xp' => 0, 'active' => false, 'label' => 'Waitlist reward per qualifying referral'],
    ];
    $in = "'" . implode("','", array_keys($out)) . "'";
    foreach ($db->query("SELECT action_key, label, xp_value, is_active FROM xp_rules WHERE action_key IN ($in)")->fetchAll() as $r) {
        $out[$r['action_key']] = ['xp' => max(0, (int)$r['xp_value']), 'active' => (int)$r['is_active'] === 1, 'label' => (string)$r['label']];
    }
    return $out;
}

/**
 * Pure calculation. A record is creditable only when every rule that applies to it is ACTIVE:
 * the signup rule always applies; the referral rule applies when referral_count > 0.
 * Inactive rule => nothing is credited and the record is NOT consumed (it stays creditable later).
 *
 * @return array{signup_xp:int,referral_xp:int,total_xp:int,rate:int,ready:bool,reason:?string}
 */
function wlrCalculate(int $referralCount, array $rules): array
{
    $sig = $rules[WLR_RULE_SIGNUP]   ?? ['xp' => 0, 'active' => false];
    $ref = $rules[WLR_RULE_REFERRAL] ?? ['xp' => 0, 'active' => false];
    $count = max(0, $referralCount);
    $rate = (int)$ref['xp'];
    $signupXp   = $sig['active'] ? (int)$sig['xp'] : 0;
    $referralXp = ($ref['active'] && $count > 0) ? $count * $rate : 0;
    $total = $signupXp + $referralXp;

    $reason = null;
    if (!$sig['active'])                           $reason = 'signup_rule_inactive';
    elseif ($count > 0 && !$ref['active'])         $reason = 'referral_rule_inactive';
    elseif ($total > WLR_MAX_TOTAL_XP)             $reason = 'total_too_large';
    elseif ($total <= 0)                           $reason = 'nothing_to_credit';

    return ['signup_xp' => $signupXp, 'referral_xp' => $referralXp, 'total_xp' => $total, 'rate' => $rate, 'ready' => $reason === null, 'reason' => $reason];
}

function wlrReasonText(?string $reason): string
{
    return [
        'signup_rule_inactive'   => 'The “Waitlist signup reward” rule is not active.',
        'referral_rule_inactive' => 'This record has referrals but the “per referral” rule is not active.',
        'total_too_large'        => 'The calculated total exceeds the safety limit.',
        'nothing_to_credit'      => 'The calculated reward is 0 XP.',
    ][$reason ?? ''] ?? '';
}

/* ------------------------------------------------------------------ */
/* Safety helpers                                                      */
/* ------------------------------------------------------------------ */

/** The credit transaction is only atomic on InnoDB. Fail closed if a table is not; ignore if the check itself fails. */
function wlrEngineOk(PDO $db): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $rows = $db->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('users','user_activity_log','waitlist_rewards','waitlist_reward_audit')")->fetchAll();
        $ok = true;
        foreach ($rows as $r) {
            if (strcasecmp((string)$r['ENGINE'], 'InnoDB') !== 0) { error_log('[waitlist-rewards] table ' . $r['TABLE_NAME'] . ' is ' . $r['ENGINE'] . ', not InnoDB — crediting disabled'); $ok = false; }
        }
    } catch (Throwable $e) {
        error_log('[waitlist-rewards] engine check unavailable: ' . $e->getMessage());
        $ok = true;
    }
    return $ok;
}

function wlrAdminLabel(PDO $db, ?int $adminId): ?string
{
    if (!$adminId) return null;
    try {
        $st = $db->prepare('SELECT email FROM admins WHERE id = ?');
        $st->execute([$adminId]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string)$v;
    } catch (Throwable $e) { return null; }
}

/** Append an audit row. Call inside the same transaction as the change it describes. */
function wlrAudit(PDO $db, ?int $rewardId, string $action, ?int $adminId, ?int $userId, int $xpDelta, array $details = [], ?string $idemKey = null): int
{
    $db->prepare('INSERT INTO waitlist_reward_audit (reward_id, action, admin_id, admin_label, user_id, xp_delta, details, idem_key) VALUES (?,?,?,?,?,?,?,?)')
       ->execute([$rewardId, $action, $adminId, wlrAdminLabel($db, $adminId), $userId, $xpDelta, $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null, $idemKey]);
    return (int)$db->lastInsertId();
}

function wlrIsRetryable(PDOException $e): bool
{
    return in_array((int)($e->errorInfo[1] ?? 0), [1213, 1205], true);   // deadlock, lock wait timeout
}

function wlrIsDuplicate(PDOException $e): bool
{
    return (int)($e->errorInfo[1] ?? 0) === 1062;
}

/** Run $fn inside a transaction with a small retry on deadlock. $fn must return a result array and may roll back itself. */
function wlrTx(PDO $db, callable $fn): array
{
    if ($db->inTransaction()) return ['status' => 'busy_transaction'];
    for ($attempt = 1; ; $attempt++) {
        try {
            $db->beginTransaction();
            $res = $fn($db);
            if ($db->inTransaction()) $db->commit();
            return $res;
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            if (wlrIsRetryable($e) && $attempt < 4) { usleep(random_int(20000, 120000)); continue; }
            if (wlrIsDuplicate($e)) return ['status' => 'conflict', 'detail' => 'duplicate_key'];
            error_log('[waitlist-rewards] transaction failed: ' . $e->getMessage());
            return ['status' => 'error'];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[waitlist-rewards] ' . get_class($e) . ': ' . $e->getMessage());
            return ['status' => 'error'];
        }
    }
}

/* ------------------------------------------------------------------ */
/* Account matching                                                    */
/* ------------------------------------------------------------------ */

/**
 * Active (non-deactivated) account whose email normalizes to $normalized. Uses the UNIQUE email index, then
 * re-checks exactly in PHP so a collation-folded near-match (e.g. accents) is never treated as the same mailbox.
 * @return array{id:int,email:string,username:?string}|null
 */
function wlrFindAccount(string $normalized, ?PDO $db = null): ?array
{
    $db = $db ?? getDB();
    $st = $db->prepare('SELECT id, email, username FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1');
    $st->execute([$normalized]);
    $u = $st->fetch();
    if (!$u || wlrNormalizeEmail((string)$u['email']) !== $normalized) return null;
    return ['id' => (int)$u['id'], 'email' => (string)$u['email'], 'username' => $u['username'] !== null ? (string)$u['username'] : null];
}

/* ------------------------------------------------------------------ */
/* Crediting (exactly once)                                            */
/* ------------------------------------------------------------------ */

/**
 * Credit the reward for $userId's email, if an eligible record exists. Idempotent and safe under concurrency.
 * $via: 'password' | 'google' | 'admin'.  Never throws.
 *
 * @return array{status:string,reward_id?:int,signup_xp?:int,referral_xp?:int,total_xp?:int,referral_count?:int,reason?:string}
 *   status: credited | already | taken | no_record | excluded | rules_not_ready | no_user | no_email | user_already_credited
 *           | unsafe_engine | busy_transaction | conflict | error
 */
function wlrCreditForUser(int $userId, string $via = 'password', ?int $adminId = null): array
{
    try {
        $db = getDB();
        $st = $db->prepare('SELECT id, email, deleted_at FROM users WHERE id = ?');
        $st->execute([$userId]);
        $u = $st->fetch();
        if (!$u || $u['deleted_at'] !== null) return ['status' => 'no_user'];
        $norm = wlrNormalizeEmail((string)$u['email']);
        if ($norm === null) return ['status' => 'no_email'];
        if (!wlrEngineOk($db)) return ['status' => 'unsafe_engine'];
        if (!in_array($via, ['password', 'google', 'admin'], true)) $via = 'password';

        $result = wlrTx($db, static function (PDO $db) use ($userId, $norm, $via, $adminId): array {
            $st = $db->prepare('SELECT * FROM waitlist_rewards WHERE email_normalized = ? FOR UPDATE');
            $st->execute([$norm]);
            $r = $st->fetch();
            if (!$r) { $db->rollBack(); return ['status' => 'no_record']; }
            $rid = (int)$r['id'];

            if ($r['credited_at'] !== null) {
                $db->rollBack();
                return ['status' => ((int)$r['credited_user_id'] === $userId) ? 'already' : 'taken', 'reward_id' => $rid];
            }
            if ((int)$r['is_active'] !== 1) { $db->rollBack(); return ['status' => 'excluded', 'reward_id' => $rid]; }

            $other = $db->prepare('SELECT id FROM waitlist_rewards WHERE credited_user_id = ? AND id <> ? LIMIT 1 FOR UPDATE');
            $other->execute([$userId, $rid]);
            if ($other->fetchColumn() !== false) { $db->rollBack(); return ['status' => 'user_already_credited', 'reward_id' => $rid]; }

            $rules = wlrLoadRules($db);
            $calc  = wlrCalculate((int)$r['referral_count'], $rules);
            if (!$calc['ready']) {
                // Keep the record unconsumed; leave a trace so the admin can see why it did not pay.
                wlrAudit($db, $rid, 'credit_blocked', $adminId, $userId, 0, ['reason' => $calc['reason'], 'via' => $via]);
                $db->commit();
                return ['status' => 'rules_not_ready', 'reward_id' => $rid, 'reason' => (string)$calc['reason']];
            }

            $count = (int)$r['referral_count'];
            $meta = static fn(string $component, int $xp): string => json_encode([
                'reward_id' => $rid, 'component' => $component, 'via' => $via, 'referral_count' => $count,
                'xp_per_referral' => $calc['rate'], 'signup_rule_xp' => $calc['signup_xp'], 'amount' => $xp,
            ], JSON_UNESCAPED_SLASHES);
            $led = $db->prepare("INSERT INTO user_activity_log (user_id, action_key, reference_type, reference_id, xp_awarded, metadata) VALUES (?,?,'waitlist_reward',?,?,?)");
            if ($calc['signup_xp'] > 0)   $led->execute([$userId, WLR_RULE_SIGNUP,   $rid, $calc['signup_xp'],   $meta('signup',   $calc['signup_xp'])]);
            if ($calc['referral_xp'] > 0) $led->execute([$userId, WLR_RULE_REFERRAL, $rid, $calc['referral_xp'], $meta('referral', $calc['referral_xp'])]);

            $up = $db->prepare('UPDATE users SET total_xp = total_xp + ? WHERE id = ?');
            $up->execute([$calc['total_xp'], $userId]);
            if ($up->rowCount() !== 1) throw new RuntimeException('user row missing during credit');

            $mark = $db->prepare('UPDATE waitlist_rewards SET credited_at = NOW(), credited_user_id = ?, credit_key = ?, credited_via = ?,
                    credited_signup_xp = ?, credited_referral_count = ?, credited_referral_rate = ?, credited_referral_xp = ?, credited_total_xp = ?,
                    email_mismatch_flag = 0, updated_by = ? WHERE id = ? AND credited_at IS NULL');
            $mark->execute([$userId, 'wlr:' . $rid . ':credit', $via, $calc['signup_xp'], $count, $calc['rate'], $calc['referral_xp'], $calc['total_xp'], $adminId, $rid]);
            if ($mark->rowCount() !== 1) throw new RuntimeException('record changed during credit');

            wlrAudit($db, $rid, 'credited', $adminId, $userId, $calc['total_xp'], [
                'via' => $via, 'signup_xp' => $calc['signup_xp'], 'referral_count' => $count, 'xp_per_referral' => $calc['rate'],
                'referral_xp' => $calc['referral_xp'], 'total_xp' => $calc['total_xp'],
            ], 'credit:' . $rid);

            return ['status' => 'credited', 'reward_id' => $rid, 'signup_xp' => $calc['signup_xp'], 'referral_xp' => $calc['referral_xp'],
                    'total_xp' => $calc['total_xp'], 'referral_count' => $count];
        });

        if (($result['status'] ?? '') === 'credited') wlrRecalcLevelSafe($userId);
        return $result;
    } catch (Throwable $e) {
        error_log('[waitlist-rewards] credit failed for user ' . $userId . ': ' . $e->getMessage());
        return ['status' => 'error'];
    }
}

function wlrRecalcLevelSafe(int $userId): void
{
    try { recalculateLevel($userId); }
    catch (Throwable $e) { error_log('[waitlist-rewards] level recalculation failed for user ' . $userId . ': ' . $e->getMessage()); }
}

/**
 * Single shared post-registration step, called by BOTH password registration (register.php) and Google registration
 * (auth/google-callback.php, new accounts only). Never throws; never blocks registration.
 *
 * 1. Normal welcome bonus through the existing awardXp() ledger (no-op while the rule is inactive / 0 XP).
 * 2. Waitlist reward, if an eligible admin record matches this account's email.
 *
 * @return array{welcome_xp:int,waitlist_xp:int,waitlist_status:string}
 */
function wlrOnRegistration(int $userId, string $via): array
{
    $out = ['welcome_xp' => 0, 'waitlist_xp' => 0, 'waitlist_status' => 'skipped'];
    try {
        $out['welcome_xp'] = (int)awardXp($userId, WLR_RULE_WELCOME, 'registration', $userId);
    } catch (Throwable $e) {
        error_log('[waitlist-rewards] welcome bonus failed for user ' . $userId . ': ' . $e->getMessage());
    }
    try {
        // Password registration does not verify email ownership. Admins can restrict automatic credit to verified (Google) sign-ups.
        if ($via === 'password' && getSetting('waitlist_credit_unverified_email', '1') !== '1') {
            $out['waitlist_status'] = 'deferred_unverified_email';
        } else {
            $r = wlrCreditForUser($userId, $via);
            $out['waitlist_status'] = (string)$r['status'];
            if ($r['status'] === 'credited') $out['waitlist_xp'] = (int)$r['total_xp'];
        }
    } catch (Throwable $e) {
        error_log('[waitlist-rewards] registration hook failed for user ' . $userId . ': ' . $e->getMessage());
        $out['waitlist_status'] = 'error';
    }
    return $out;
}

/** Admin action: credit an existing, matched, uncredited record now (e.g. the member registered before the record existed). */
function wlrAdminCreditNow(int $rewardId, int $adminId): array
{
    $db = getDB();
    $st = $db->prepare('SELECT email_normalized, credited_at FROM waitlist_rewards WHERE id = ?');
    $st->execute([$rewardId]);
    $r = $st->fetch();
    if (!$r) return ['status' => 'no_record'];
    if ($r['credited_at'] !== null) return ['status' => 'already'];
    $acct = wlrFindAccount((string)$r['email_normalized'], $db);
    if (!$acct) return ['status' => 'no_account'];
    return wlrCreditForUser($acct['id'], 'admin', $adminId);
}

/* ------------------------------------------------------------------ */
/* Admin: create / edit records                                        */
/* ------------------------------------------------------------------ */

/** @return array{errors:array<string,string>,clean:array<string,mixed>} */
function wlrValidateInput(array $in): array
{
    $errors = [];
    $email = wlrNormalizeEmail((string)($in['email'] ?? ''));
    if ($email === null) $errors['email'] = 'Enter a valid email address.';

    $rawCount = trim((string)($in['referral_count'] ?? '0'));
    if ($rawCount === '') $rawCount = '0';
    $count = 0;
    if (!ctype_digit($rawCount) || strlen($rawCount) > 7 || (int)$rawCount > WLR_MAX_REFERRALS) {
        $errors['referral_count'] = 'Referral count must be a whole number from 0 to ' . number_format(WLR_MAX_REFERRALS) . '.';
    } else {
        $count = (int)$rawCount;
    }

    $notes = trim((string)($in['admin_notes'] ?? ''));
    $notes = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $notes) ?? '';
    if (mb_strlen($notes) > WLR_MAX_NOTES) $errors['admin_notes'] = 'Notes can be at most ' . WLR_MAX_NOTES . ' characters.';

    return ['errors' => $errors, 'clean' => [
        'email' => $email, 'referral_count' => $count, 'referred_someone' => !empty($in['referred_someone']) ? 1 : 0,
        'admin_notes' => $notes === '' ? null : $notes,
    ]];
}

/**
 * Create (id = null) or edit a record. Credited records are locked: only the admin note can change; XP-relevant changes
 * must go through wlrApplyCorrection().
 * @return array{ok:bool,errors:array<string,string>,id?:int,existing_id?:int,message?:string}
 */
function wlrSaveRecord(?int $id, array $in, int $adminId): array
{
    $v = wlrValidateInput($in);
    if ($v['errors']) return ['ok' => false, 'errors' => $v['errors']];
    $c = $v['clean'];
    $db = getDB();

    $res = wlrTx($db, static function (PDO $db) use ($id, $c, $adminId): array {
        if ($id === null) {
            $db->prepare('INSERT INTO waitlist_rewards (email_normalized, referred_someone, referral_count, admin_notes, created_by, updated_by) VALUES (?,?,?,?,?,?)')
               ->execute([$c['email'], $c['referred_someone'], $c['referral_count'], $c['admin_notes'], $adminId, $adminId]);
            $newId = (int)$db->lastInsertId();
            wlrAudit($db, $newId, 'created', $adminId, null, 0, ['email' => $c['email'], 'referral_count' => $c['referral_count'], 'referred_someone' => $c['referred_someone']]);
            return ['status' => 'ok', 'id' => $newId, 'message' => 'Record created.'];
        }
        $st = $db->prepare('SELECT * FROM waitlist_rewards WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) { $db->rollBack(); return ['status' => 'missing']; }

        if ($r['credited_at'] !== null) {
            if ($c['email'] !== $r['email_normalized'] || $c['referral_count'] !== (int)$r['referral_count']) {
                $db->rollBack();
                return ['status' => 'locked'];
            }
            $db->prepare('UPDATE waitlist_rewards SET admin_notes = ?, referred_someone = ?, updated_by = ? WHERE id = ?')
               ->execute([$c['admin_notes'], $c['referred_someone'], $adminId, $id]);
            wlrAudit($db, $id, 'updated', $adminId, (int)$r['credited_user_id'], 0, ['note_only' => true]);
            return ['status' => 'ok', 'id' => $id, 'message' => 'Notes saved (record is already credited, so XP fields are locked).'];
        }
        $before = ['email' => $r['email_normalized'], 'referral_count' => (int)$r['referral_count'], 'referred_someone' => (int)$r['referred_someone']];
        $db->prepare('UPDATE waitlist_rewards SET email_normalized = ?, referred_someone = ?, referral_count = ?, admin_notes = ?, updated_by = ? WHERE id = ? AND credited_at IS NULL')
           ->execute([$c['email'], $c['referred_someone'], $c['referral_count'], $c['admin_notes'], $adminId, $id]);
        wlrAudit($db, $id, 'updated', $adminId, null, 0, ['before' => $before, 'after' => ['email' => $c['email'], 'referral_count' => $c['referral_count'], 'referred_someone' => $c['referred_someone']]]);
        return ['status' => 'ok', 'id' => $id, 'message' => 'Record updated.'];
    });

    switch ($res['status']) {
        case 'ok':       return ['ok' => true, 'errors' => [], 'id' => (int)$res['id'], 'message' => (string)$res['message']];
        case 'missing':  return ['ok' => false, 'errors' => ['form' => 'Record not found.']];
        case 'locked':   return ['ok' => false, 'errors' => ['form' => 'This record has already been credited. The email and referral count are locked; use “Correct a credited reward” instead.']];
        case 'conflict':
            $q = $db->prepare('SELECT id FROM waitlist_rewards WHERE email_normalized = ?');
            $q->execute([$c['email']]);
            $ex = $q->fetchColumn();
            return ['ok' => false, 'errors' => ['email' => 'A record for this email already exists.'], 'existing_id' => $ex !== false ? (int)$ex : null];
        default:         return ['ok' => false, 'errors' => ['form' => 'Could not save. Please try again.']];
    }
}

/** Exclude / re-include an UNCREDITED record. Credited XP is never clawed back by excluding; use a correction. */
function wlrSetActive(int $id, bool $active, int $adminId, string $reason = ''): array
{
    $reason = mb_substr(trim($reason), 0, 300);
    $res = wlrTx(getDB(), static function (PDO $db) use ($id, $active, $adminId, $reason): array {
        $st = $db->prepare('SELECT is_active, credited_at FROM waitlist_rewards WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) { $db->rollBack(); return ['status' => 'missing']; }
        if ($r['credited_at'] !== null) { $db->rollBack(); return ['status' => 'credited']; }
        if ((int)$r['is_active'] === ($active ? 1 : 0)) { $db->rollBack(); return ['status' => 'noop']; }
        $db->prepare('UPDATE waitlist_rewards SET is_active = ?, updated_by = ? WHERE id = ?')->execute([$active ? 1 : 0, $adminId, $id]);
        wlrAudit($db, $id, $active ? 'reincluded' : 'excluded', $adminId, null, 0, $reason !== '' ? ['reason' => $reason] : []);
        return ['status' => 'ok'];
    });
    return $res;
}

function wlrSetMismatchFlag(int $id, bool $flag, int $adminId): array
{
    return wlrTx(getDB(), static function (PDO $db) use ($id, $flag, $adminId): array {
        $st = $db->prepare('SELECT email_mismatch_flag, credited_at FROM waitlist_rewards WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) { $db->rollBack(); return ['status' => 'missing']; }
        if ($r['credited_at'] !== null) { $db->rollBack(); return ['status' => 'credited']; }
        $db->prepare('UPDATE waitlist_rewards SET email_mismatch_flag = ?, updated_by = ? WHERE id = ?')->execute([$flag ? 1 : 0, $adminId, $id]);
        wlrAudit($db, $id, 'mismatch_flag', $adminId, null, 0, ['flag' => $flag]);
        return ['status' => 'ok'];
    });
}

/* ------------------------------------------------------------------ */
/* Admin: controlled correction after crediting                        */
/* ------------------------------------------------------------------ */

/**
 * The ONLY way to change XP after a record is credited. Requires a reason, applies a signed delta
 * (new count - credited count) x rate, writes a ledger row and an audit row, atomically.
 * $expectedCount must equal the credited referral count the admin was looking at (stops double submits and stale forms).
 * Rate = the rate snapshotted at credit time; if that was 0, the CURRENT active "per referral" rule is used.
 *
 * @return array{status:string,delta?:int,applied?:int,total_xp?:int,message?:string}
 */
function wlrApplyCorrection(int $rewardId, int $newCount, int $expectedCount, string $reason, int $adminId): array
{
    $reason = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $reason) ?? '');
    if ($reason === '' || mb_strlen($reason) > 300) return ['status' => 'bad_reason'];
    if ($newCount < 0 || $newCount > WLR_MAX_REFERRALS) return ['status' => 'bad_count'];

    $db = getDB();
    if (!wlrEngineOk($db)) return ['status' => 'unsafe_engine'];

    $res = wlrTx($db, static function (PDO $db) use ($rewardId, $newCount, $expectedCount, $reason, $adminId): array {
        $st = $db->prepare('SELECT * FROM waitlist_rewards WHERE id = ? FOR UPDATE');
        $st->execute([$rewardId]);
        $r = $st->fetch();
        if (!$r) { $db->rollBack(); return ['status' => 'missing']; }
        if ($r['credited_at'] === null || $r['credited_user_id'] === null) { $db->rollBack(); return ['status' => 'not_credited']; }
        $old = (int)$r['credited_referral_count'];
        if ($old !== $expectedCount) { $db->rollBack(); return ['status' => 'stale', 'current' => $old]; }
        if ($newCount === $old) { $db->rollBack(); return ['status' => 'no_change']; }

        $rate = (int)$r['credited_referral_rate'];
        if ($rate <= 0) {
            $rules = wlrLoadRules($db);
            if ($rules[WLR_RULE_REFERRAL]['active']) $rate = (int)$rules[WLR_RULE_REFERRAL]['xp'];
        }
        if ($rate <= 0) { $db->rollBack(); return ['status' => 'no_rate']; }
        $delta = ($newCount - $old) * $rate;
        if (abs($delta) > WLR_MAX_TOTAL_XP || ($newCount * $rate) > WLR_MAX_TOTAL_XP) { $db->rollBack(); return ['status' => 'too_large']; }

        $uid = (int)$r['credited_user_id'];
        $cur = $db->prepare('SELECT total_xp FROM users WHERE id = ? FOR UPDATE');
        $cur->execute([$uid]);
        $balance = $cur->fetchColumn();
        if ($balance === false) { $db->rollBack(); return ['status' => 'no_user']; }
        $applied = max($delta, -(int)$balance);   // an XP balance can never go below 0

        $n = (int)$r['correction_count'] + 1;
        $auditId = wlrAudit($db, $rewardId, 'correction', $adminId, $uid, $applied, [
            'reason' => $reason, 'referral_count_before' => $old, 'referral_count_after' => $newCount, 'xp_per_referral' => $rate,
            'requested_delta' => $delta, 'applied_delta' => $applied, 'clamped_at_zero' => $applied !== $delta,
        ], 'corr:' . $rewardId . ':' . $n);

        if ($applied !== 0) {
            $db->prepare("INSERT INTO user_activity_log (user_id, action_key, reference_type, reference_id, xp_awarded, metadata) VALUES (?, 'waitlist_correction', 'waitlist_reward_audit', ?, ?, ?)")
               ->execute([$uid, $auditId, $applied, json_encode(['reward_id' => $rewardId, 'reason' => $reason, 'referral_count_before' => $old, 'referral_count_after' => $newCount, 'xp_per_referral' => $rate], JSON_UNESCAPED_SLASHES)]);
            $u = $db->prepare('UPDATE users SET total_xp = total_xp + ? WHERE id = ?');
            $u->execute([$applied, $uid]);
            if ($u->rowCount() !== 1) throw new RuntimeException('user row missing during correction');
        }
        $newTotal = (int)$r['credited_total_xp'] + $applied;
        $db->prepare('UPDATE waitlist_rewards SET referral_count = ?, credited_referral_count = ?, credited_referral_rate = ?, credited_referral_xp = ?, credited_total_xp = ?, correction_count = ?, updated_by = ? WHERE id = ?')
           ->execute([$newCount, $newCount, $rate, $newCount * $rate, $newTotal, $n, $adminId, $rewardId]);
        return ['status' => 'ok', 'delta' => $delta, 'applied' => $applied, 'total_xp' => $newTotal, 'user_id' => $uid];
    });

    if (($res['status'] ?? '') === 'ok') wlrRecalcLevelSafe((int)$res['user_id']);
    return $res;
}

/* ------------------------------------------------------------------ */
/* Admin: configuration (rules + setting), audited                     */
/* ------------------------------------------------------------------ */

/**
 * @param array<string,array{xp:int,active:bool}> $new   keyed by the three rule keys
 * @return array{ok:bool,errors:array<int,string>}
 */
function wlrSaveRules(array $new, bool $creditUnverified, int $adminId): array
{
    $errors = [];
    $db = getDB();
    $old = wlrLoadRules($db);
    foreach ([WLR_RULE_WELCOME, WLR_RULE_SIGNUP, WLR_RULE_REFERRAL] as $k) {
        $x = $new[$k]['xp'] ?? null;
        if (!is_int($x) || $x < 0 || $x > WLR_MAX_RULE_XP) $errors[] = $old[$k]['label'] . ': enter a whole number from 0 to ' . number_format(WLR_MAX_RULE_XP) . '.';
    }
    if ($errors) return ['ok' => false, 'errors' => $errors];

    wlrTx($db, static function (PDO $db) use ($new, $old, $creditUnverified, $adminId): array {
        foreach ([WLR_RULE_WELCOME, WLR_RULE_SIGNUP, WLR_RULE_REFERRAL] as $k) {
            $x = (int)$new[$k]['xp']; $a = !empty($new[$k]['active']) ? 1 : 0;
            if ($x === $old[$k]['xp'] && ($a === 1) === $old[$k]['active']) continue;
            $db->prepare('INSERT INTO xp_rules (action_key, label, xp_value, is_active) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE xp_value = VALUES(xp_value), is_active = VALUES(is_active)')
               ->execute([$k, $old[$k]['label'], $x, $a]);
            wlrAudit($db, null, 'rules_changed', $adminId, null, 0, ['rule' => $k, 'before' => ['xp' => $old[$k]['xp'], 'active' => $old[$k]['active']], 'after' => ['xp' => $x, 'active' => $a === 1]]);
        }
        $cur = getSetting('waitlist_credit_unverified_email', '1') === '1';
        if ($cur !== $creditUnverified) {
            $db->prepare("INSERT INTO site_settings (setting_key, setting_val, label) VALUES ('waitlist_credit_unverified_email', ?, 'Waitlist: credit password registrations immediately') ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")
               ->execute([$creditUnverified ? '1' : '0']);
            wlrAudit($db, null, 'setting_changed', $adminId, null, 0, ['setting' => 'waitlist_credit_unverified_email', 'before' => $cur, 'after' => $creditUnverified]);
        }
        return ['status' => 'ok'];
    });
    return ['ok' => true, 'errors' => []];
}

/* ------------------------------------------------------------------ */
/* Admin: listing                                                      */
/* ------------------------------------------------------------------ */

const WLR_STATUSES = [
    'waiting'  => 'Waiting for registration',
    'ready'    => 'Eligible and ready to credit',
    'credited' => 'Credited',
    'excluded' => 'Excluded',
    'mismatch' => 'Registration email does not match',
];

/** SQL producing every record with its matched account id and a computed status. */
function wlrBaseSql(): string
{
    return "SELECT r.*, (SELECT u.id FROM users u WHERE u.email = r.email_normalized COLLATE utf8mb4_unicode_ci AND u.deleted_at IS NULL LIMIT 1) AS matched_user_id FROM waitlist_rewards r";
}

const WLR_STATUS_CASE = "CASE WHEN t.credited_at IS NOT NULL THEN 'credited' WHEN t.is_active = 0 THEN 'excluded' WHEN t.matched_user_id IS NOT NULL THEN 'ready' WHEN t.email_mismatch_flag = 1 THEN 'mismatch' ELSE 'waiting' END";

/** @return array{rows:array<int,array<string,mixed>>,total:int,pages:int,counts:array<string,int>} */
function wlrList(string $search, string $status, int $page, int $per = 25): array
{
    $db = getDB();
    $where = []; $p = [];
    $search = trim($search);
    if ($search !== '') { $where[] = 't.email_normalized LIKE ?'; $p[] = '%' . addcslashes(mb_strtolower($search, 'UTF-8'), '%_\\') . '%'; }
    if (isset(WLR_STATUSES[$status])) { $where[] = WLR_STATUS_CASE . ' = ?'; $p[] = $status; }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $base = wlrBaseSql();

    $st = $db->prepare("SELECT COUNT(*) FROM ($base) t $w");
    $st->execute($p);
    $total = (int)$st->fetchColumn();
    $pages = max(1, (int)ceil($total / $per));
    $page = min(max(1, $page), $pages);
    $off = ($page - 1) * $per;
    $st = $db->prepare("SELECT t.*, " . WLR_STATUS_CASE . " AS status FROM ($base) t $w ORDER BY t.id DESC LIMIT $per OFFSET $off");
    $st->execute($p);
    $rows = $st->fetchAll();

    $counts = array_fill_keys(array_keys(WLR_STATUSES), 0);
    foreach ($db->query("SELECT " . WLR_STATUS_CASE . " AS s, COUNT(*) c FROM ($base) t GROUP BY s")->fetchAll() as $c) $counts[$c['s']] = (int)$c['c'];
    return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page, 'counts' => $counts];
}

/** One record with computed status and matched account, or null. */
function wlrGet(int $id): ?array
{
    $db = getDB();
    $st = $db->prepare("SELECT t.*, " . WLR_STATUS_CASE . " AS status FROM (" . wlrBaseSql() . ") t WHERE t.id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
