<?php
/**
 * Waitlist Rewards tests (main platform). Needs PHP 8.1+ with pdo_mysql + curl, and a DISPOSABLE MySQL/MariaDB.
 *
 * !!! NEVER point this at the live database. It deletes users, XP and reward rows. !!!
 * Run it inside a COPY of the project whose secrets/secrets.php points at a throw-away database with "test" in its
 * name (the runner refuses otherwise). See tests/waitlist-rewards/README.md.
 *
 *   php tests/waitlist-rewards/run.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/waitlist_rewards.php';
require_once __DIR__ . '/TestKit.php';
if (stripos(DB_NAME, 'test') === false) { fwrite(STDERR, "Refusing to run: DB_NAME must contain 'test'.\n"); exit(2); }

$ROOT = realpath(__DIR__ . '/../..');
$db = getDB();
$PHP = PHP_BINARY;

/* ----------------------------------------------------------- helpers */
function wipe(PDO $db): void {
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['waitlist_reward_audit', 'waitlist_rewards', 'user_activity_log', 'users', 'admins', 'login_attempts'] as $t) { try { $db->exec("DELETE FROM $t"); } catch (Throwable $e) {} }
    $db->exec('SET FOREIGN_KEY_CHECKS=1');
    $db->exec("DELETE FROM site_settings WHERE setting_key IN ('prelaunch_lock')");
    $db->exec("UPDATE site_settings SET setting_val='1' WHERE setting_key='waitlist_credit_unverified_email'");
}
function rules(PDO $db, array $r): void {   // ['welcome'=>[xp,active], 'signup'=>..., 'referral'=>...]
    $map = ['welcome' => WLR_RULE_WELCOME, 'signup' => WLR_RULE_SIGNUP, 'referral' => WLR_RULE_REFERRAL];
    foreach ($map as $k => $key) { [$xp, $a] = $r[$k] ?? [0, 0]; $db->prepare('UPDATE xp_rules SET xp_value=?, is_active=? WHERE action_key=?')->execute([$xp, $a, $key]); }
}
function mkUser(PDO $db, string $email): int {
    $db->prepare("INSERT INTO users (username,email,password,avatar,membership_type,profile_completed,login_provider) VALUES (NULL,?,?,?,'free',0,'email')")->execute([$email, password_hash('pw123456', PASSWORD_DEFAULT), 'cyber_01.png']);
    return (int)$db->lastInsertId();
}
function mkAdmin(PDO $db, string $email = 'admin@test.local', string $pw = 'adminpw123'): int {
    $db->prepare('INSERT INTO admins (email,password,name) VALUES (?,?,?)')->execute([$email, password_hash($pw, PASSWORD_DEFAULT), 'Test Admin']);
    return (int)$db->lastInsertId();
}
function xp(PDO $db, int $uid): int { return (int)$db->query("SELECT total_xp FROM users WHERE id=$uid")->fetchColumn(); }
function ledgerSum(PDO $db, int $uid): int { return (int)$db->query("SELECT COALESCE(SUM(xp_awarded),0) FROM user_activity_log WHERE user_id=$uid")->fetchColumn(); }
function cnt(PDO $db, string $sql): int { return (int)$db->query($sql)->fetchColumn(); }
function rec(PDO $db, int $id): array { return $db->query("SELECT * FROM waitlist_rewards WHERE id=$id")->fetch(); }
function addRec(string $email, int $refs, int $admin): int { $r = wlrSaveRecord(null, ['email' => $email, 'referral_count' => (string)$refs], $admin); return (int)($r['id'] ?? 0); }

/** Spawn N parallel workers, release them together, return their decoded JSON results. */
function parallel(array $jobs): array {
    global $PHP;
    $start = sys_get_temp_dir() . '/wlr_start_' . bin2hex(random_bytes(4));
    $procs = [];
    foreach ($jobs as $i => $args) {
        $cmd = array_merge([$PHP, __DIR__ . '/worker.php', (string)$args[0], (string)$args[1], (string)$args[2], $start], array_map('strval', array_slice($args, 3)));
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[$i] = [$p, $pipes];
    }
    usleep(1500000);          // let every worker finish bootstrapping and reach its spin-wait
    touch($start);
    $out = [];
    foreach ($procs as $i => [$p, $pipes]) {
        $o = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
        $j = json_decode(trim($o), true); $out[] = is_array($j) ? $j : ['status' => 'NO_OUTPUT:' . substr($o, 0, 80)];
    }
    @unlink($start);
    return $out;
}
function statuses(array $res): array { $c = array_count_values(array_column($res, 'status')); ksort($c); return $c; }

/* ================================================================= 1. schema */
T::section('1. Schema');
foreach (['waitlist_rewards', 'waitlist_reward_audit'] as $t) T::ok(cnt($db, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' AND ENGINE='InnoDB'") === 1, "$t exists and is InnoDB");
$idx = $db->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waitlist_rewards' AND NON_UNIQUE=0")->fetchAll(PDO::FETCH_COLUMN);
foreach (['uq_wlr_email', 'uq_wlr_credited_user', 'uq_wlr_credit_key'] as $i) T::ok(in_array($i, $idx, true), "unique key $i present");
$rr = $db->query("SELECT action_key, xp_value, is_active FROM xp_rules WHERE action_key IN ('registration_welcome','waitlist_signup','waitlist_referral') ORDER BY action_key")->fetchAll();
T::eq(count($rr), 3, 'three new xp_rules rows seeded');

/* ================================================================= 2. normalisation */
T::section('2. Email normalisation');
T::eq(wlrNormalizeEmail("  User@Example.COM \n"), 'user@example.com', 'trims whitespace and lower-cases');
T::eq(wlrNormalizeEmail("\u{00A0}\u{200B}a@b.co\u{FEFF}"), 'a@b.co', 'strips NBSP / zero-width / BOM');
T::eq(wlrNormalizeEmail('A.B+tag@Gmail.com'), 'a.b+tag@gmail.com', 'keeps dots and +tag (matches main-platform identity)');
T::eq(wlrNormalizeEmail('not-an-email'), null, 'rejects invalid');
T::eq(wlrNormalizeEmail("a@b.co\r\nBcc: x@y.z"), null, 'rejects header-injection shape');
T::eq(wlrNormalizeEmail(''), null, 'rejects empty');

/* ================================================================= 3. calculation */
T::section('3. Reward calculation and rule gating');
$R = fn(array $a) => ['waitlist_signup' => ['xp' => $a[0], 'active' => (bool)$a[1]], 'waitlist_referral' => ['xp' => $a[2], 'active' => (bool)$a[3]]];
$c = wlrCalculate(4, $R([50, 1, 10, 1]));
T::eq([$c['signup_xp'], $c['referral_xp'], $c['total_xp'], $c['ready']], [50, 40, 90, true], '50 + (4 x 10) = 90');
$c = wlrCalculate(0, $R([50, 1, 10, 1]));
T::eq([$c['total_xp'], $c['ready']], [50, true], 'zero referrals = signup only');
$c = wlrCalculate(4, $R([50, 0, 10, 1]));
T::eq([$c['ready'], $c['reason'], $c['total_xp']], [false, 'signup_rule_inactive', 40], 'signup rule inactive => not ready');
$c = wlrCalculate(4, $R([50, 1, 10, 0]));
T::eq([$c['ready'], $c['reason']], [false, 'referral_rule_inactive'], 'referrals present but referral rule inactive => not ready');
$c = wlrCalculate(0, $R([50, 1, 10, 0]));
T::ok($c['ready'], 'referral rule inactive is fine when count is 0');
$c = wlrCalculate(0, $R([0, 1, 10, 1]));
T::eq([$c['ready'], $c['reason']], [false, 'nothing_to_credit'], 'active but 0 XP => nothing to credit');
T::ok(!wlrCalculate(WLR_MAX_REFERRALS, $R([0, 1, WLR_MAX_RULE_XP, 1]))['ready'], 'absurd totals are refused');

/* ================================================================= 4. admin records */
T::section('4. Admin: create, validate, de-duplicate');
wipe($db); rules($db, []);
$admin = mkAdmin($db);
$r = wlrSaveRecord(null, ['email' => '  New.Member@Example.com ', 'referral_count' => '4', 'admin_notes' => 'hello'], $admin);
T::ok($r['ok'], 'admin can add a record for an email that has not registered');
$id1 = (int)$r['id'];
T::eq(rec($db, $id1)['email_normalized'], 'new.member@example.com', 'stored normalized');
T::eq((int)rec($db, $id1)['referral_count'], 4, 'referral count stored');
T::eq(cnt($db, "SELECT COUNT(*) FROM waitlist_reward_audit WHERE reward_id=$id1 AND action='created'"), 1, 'creation audited');
foreach (['NEW.member@example.com', ' new.member@EXAMPLE.com  '] as $dup) {
    $r = wlrSaveRecord(null, ['email' => $dup, 'referral_count' => '1'], $admin);
    T::ok(!$r['ok'] && isset($r['errors']['email']) && ($r['existing_id'] ?? 0) === $id1, "duplicate normalized email rejected: '" . trim($dup) . "'");
}
T::eq(cnt($db, 'SELECT COUNT(*) FROM waitlist_rewards'), 1, 'still exactly one record');
foreach (['-1', '1.5', 'abc', '1e3', '999999999', ' '] as $bad) {
    $r = wlrSaveRecord(null, ['email' => 'x' . bin2hex(random_bytes(2)) . '@example.com', 'referral_count' => $bad], $admin);
    $expectOk = trim($bad) === '';   // blank means 0
    T::ok($r['ok'] === $expectOk, "referral count '$bad' " . ($expectOk ? 'treated as 0' : 'rejected'));
}
T::ok(!wlrSaveRecord(null, ['email' => 'nope', 'referral_count' => '1'], $admin)['ok'], 'invalid email rejected');
T::ok(!wlrSaveRecord(null, ['email' => 'n@example.com', 'referral_count' => '1', 'admin_notes' => str_repeat('x', 1001)], $admin)['ok'], 'overlong notes rejected');
T::ok(wlrSaveRecord($id1, ['email' => 'new.member@example.com', 'referral_count' => '6'], $admin)['ok'], 'referral count editable before crediting');
T::eq((int)rec($db, $id1)['referral_count'], 6, 'edited count persisted');
T::eq(wlrGet($id1)['status'], 'waiting', 'status: waiting for registration');
$r = wlrSaveRecord($id1, ['email' => 'other@example.com', 'referral_count' => '6'], $admin);
T::ok($r['ok'], 'email can be corrected before crediting (typo fix)');
wlrSaveRecord($id1, ['email' => 'new.member@example.com', 'referral_count' => '6'], $admin);

T::section('4b. Statuses, exclusion, mismatch flag');
$uReady = mkUser($db, 'NEW.MEMBER@example.com');   // registered with different casing
T::eq(wlrGet($id1)['status'], 'ready', 'status: eligible and ready (matched account, case-insensitive)');
T::ok(wlrSetActive($id1, false, $admin, 'test')['status'] === 'ok', 'record can be excluded');
T::eq(wlrGet($id1)['status'], 'excluded', 'status: excluded');
rules($db, ['signup' => [50, 1], 'referral' => [10, 1]]);
$r = wlrCreditForUser($uReady, 'password');
T::eq($r['status'], 'excluded', 'excluded record awards nothing');
T::eq(xp($db, $uReady), 0, 'no XP for excluded record');
wlrSetActive($id1, true, $admin);
$idM = addRec('someone.else@example.com', 2, $admin);
T::ok(wlrSetMismatchFlag($idM, true, $admin)['status'] === 'ok', 'mismatch flag set');
T::eq(wlrGet($idM)['status'], 'mismatch', 'status: registration email does not match');
T::eq(wlrGet($id1)['status'], 'ready', 'status back to ready after re-include');
$lst = wlrList('', 'ready', 1);
T::ok($lst['total'] === 1 && $lst['rows'][0]['id'] == $id1, 'filter by status works');
$lst = wlrList('someone.else', '', 1);
T::ok($lst['total'] === 1, 'search by email works');
addRec('p1@example.com', 0, $admin);
for ($i = 0; $i < 30; $i++) addRec("bulk$i@example.com", 0, $admin);
$lst = wlrList('bulk', '', 2, 25);
T::ok($lst['total'] === 30 && count($lst['rows']) === 5 && $lst['pages'] === 2, 'pagination: page 2 of 2 has the remaining 5');

/* ================================================================= 5. registration: no record / rules off */
T::section('5. Registration without a record, and with rules off');
wipe($db); rules($db, []); $admin = mkAdmin($db);
$u = mkUser($db, 'plain@example.com');
$o = wlrOnRegistration($u, 'password');
T::eq([$o['welcome_xp'], $o['waitlist_xp'], $o['waitlist_status']], [0, 0, 'no_record'], 'no record: registration continues, nothing awarded');
T::eq(xp($db, $u), 0, 'XP is 0');
$id = addRec('rulesoff@example.com', 3, $admin);
$u2 = mkUser($db, 'rulesoff@example.com');
$o = wlrOnRegistration($u2, 'password');
T::eq($o['waitlist_status'], 'rules_not_ready', 'rules inactive: not credited');
T::eq(xp($db, $u2), 0, 'rules inactive: no XP');
T::ok(rec($db, $id)['credited_at'] === null, 'rules inactive: record NOT consumed');
T::eq(cnt($db, "SELECT COUNT(*) FROM waitlist_reward_audit WHERE reward_id=$id AND action='credit_blocked'"), 1, 'blocked attempt audited');
T::eq(wlrGet($id)['status'], 'ready', 'status stays eligible-and-ready');
rules($db, ['signup' => [50, 1], 'referral' => [10, 1]]);
$a = wlrAdminCreditNow($id, $admin);
T::eq([$a['status'], $a['total_xp']], ['credited', 80], 'after rules are enabled, admin "Credit now" pays 50 + 3x10');
T::eq(xp($db, $u2), 80, 'user XP = 80');

/* ================================================================= 6. registration with record */
T::section('6. Registration with a matching record (password + Google), welcome bonus separate');
wipe($db); rules($db, ['welcome' => [25, 1], 'signup' => [50, 1], 'referral' => [10, 1]]); $admin = mkAdmin($db);
$id = addRec('Matched.User@Example.com', 4, $admin);
$u = mkUser($db, ' matched.user@example.COM');
$o = wlrOnRegistration($u, 'password');
T::eq([$o['welcome_xp'], $o['waitlist_xp'], $o['waitlist_status']], [25, 90, 'credited'], 'password registration: welcome 25 + waitlist 50+4x10=90');
T::eq(xp($db, $u), 115, 'user XP = 25 + 90');
T::eq(ledgerSum($db, $u), 115, 'ledger sum equals users.total_xp');
$rows = $db->query("SELECT action_key, xp_awarded, reference_type, reference_id FROM user_activity_log WHERE user_id=$u ORDER BY id")->fetchAll();
T::eq(array_column($rows, 'action_key'), ['registration_welcome', 'waitlist_signup', 'waitlist_referral'], 'ledger has three distinct entries (welcome, signup, referral)');
$rc = rec($db, $id);
T::eq([(int)$rc['credited_user_id'], (int)$rc['credited_signup_xp'], (int)$rc['credited_referral_count'], (int)$rc['credited_referral_rate'], (int)$rc['credited_referral_xp'], (int)$rc['credited_total_xp'], $rc['credited_via'], $rc['credit_key']],
      [$u, 50, 4, 10, 40, 90, 'password', "wlr:$id:credit"], 'record carries the full snapshot (rates, count, amounts, via, idempotency key)');
T::ok($rc['credited_at'] !== null, 'credited_at stamped');
T::eq(wlrGet($id)['status'], 'credited', 'status: credited');
$au = $db->query("SELECT * FROM waitlist_reward_audit WHERE reward_id=$id AND action='credited'")->fetch();
T::ok($au && (int)$au['xp_delta'] === 90 && (int)$au['user_id'] === $u && json_decode($au['details'], true)['referral_count'] === 4, 'audit row records amount, user and referral count');
$o2 = wlrOnRegistration($u, 'password');
T::eq([$o2['welcome_xp'], $o2['waitlist_xp'], $o2['waitlist_status']], [0, 0, 'already'], 'repeat call: welcome NOT duplicated, waitlist NOT duplicated');
T::eq(xp($db, $u), 115, 'XP unchanged after repeat');
for ($i = 0; $i < 5; $i++) wlrCreditForUser($u, 'google');
T::eq(xp($db, $u), 115, 'five more sequential retries: still unchanged');
T::eq(cnt($db, "SELECT COUNT(*) FROM user_activity_log WHERE user_id=$u AND action_key LIKE 'waitlist_%'"), 2, 'exactly two waitlist ledger rows');
// Google path (verified email) is the same shared service
$id2 = addRec('g.user@example.com', 0, $admin); $ug = mkUser($db, 'g.user@example.com');
$o = wlrOnRegistration($ug, 'google');
T::eq([$o['welcome_xp'], $o['waitlist_xp']], [25, 50], 'google registration: welcome 25 + signup 50, no referrals');
T::eq(rec($db, $id2)['credited_via'], 'google', 'credited_via = google');
// welcome only (no waitlist record)
$un = mkUser($db, 'nowl@example.com'); $o = wlrOnRegistration($un, 'google');
T::eq([$o['welcome_xp'], $o['waitlist_xp']], [25, 0], 'no record: normal welcome bonus only');
// unverified-email policy
T::ok(true, '(policy toggle exercised in the HTTP section, because getSetting() caches per process)');

T::section('6a. Google sign-up path (real findOrCreateGoogleUser + the same shared hook the callback runs)');
$idG = addRec('Google.New@Example.com', 2, $admin);
$res = findOrCreateGoogleUser(['sub' => 'g-sub-1', 'email' => 'google.new@example.com', 'name' => 'G New']);
T::ok($res['is_new'] === true, 'brand-new Google account is reported as new');
$o = $res['is_new'] ? wlrOnRegistration((int)$res['user']['id'], 'google') : null;
T::eq([$o['welcome_xp'], $o['waitlist_xp'], $o['waitlist_status']], [25, 70, 'credited'], 'new Google account: welcome 25 + waitlist 50+2x10');
T::eq(xp($db, (int)$res['user']['id']), 95, 'Google user XP = 95');
$again = findOrCreateGoogleUser(['sub' => 'g-sub-1', 'email' => 'google.new@example.com']);
T::ok($again['is_new'] === false, 'second Google sign-in is not a registration (hook is not run, nothing can be paid again)');
// An existing PASSWORD account that signs in with Google for the first time is linked, NOT registered: never auto-credited.
$idL = addRec('linked.user@example.com', 1, $admin); $uL = mkUser($db, 'linked.user@example.com');
$link = findOrCreateGoogleUser(['sub' => 'g-sub-2', 'email' => 'linked.user@example.com']);
T::ok($link['is_new'] === false && (int)$link['user']['id'] === $uL, 'Google sign-in links to the existing email account instead of creating one');
T::eq(xp($db, $uL), 0, 'linking is not a registration: no XP');
T::eq(wlrGet($idL)['status'], 'ready', 'that record stays "ready to credit" for an admin to credit deliberately');
$src = file_get_contents(__DIR__ . '/../../auth/google-callback.php');
T::ok((bool)preg_match('/if \(\$isNew\) \{\s*\$rewards = wlrOnRegistration\(\(int\)\$user\[.id.\], .google.\);/', $src), 'google-callback.php calls the shared hook only for new accounts (static check)');
T::ok(str_contains($src, "email_verified") , 'google-callback.php still enforces Google email_verified (static check)');

T::section('6b. One account, one record; one record, one account (database guards)');
$idA = addRec('guard.a@example.com', 0, $admin); $idB = addRec('guard.b@example.com', 0, $admin);
$uA = mkUser($db, 'guard.a@example.com'); wlrCreditForUser($uA, 'password');
try { $db->prepare("UPDATE waitlist_rewards SET credited_user_id=?, credited_at=NOW(), credit_key='x' WHERE id=?")->execute([$uA, $idB]); T::ok(false, 'same account credited on a second record must be blocked'); }
catch (PDOException $e) { T::ok((int)$e->errorInfo[1] === 1062, 'DB blocks a second credited record for the same account (UNIQUE credited_user_id)'); }
$uB = mkUser($db, 'guard.b@example.com');
try { $db->prepare("UPDATE waitlist_rewards SET credited_user_id=?, credited_at=NOW(), credit_key=? WHERE id=?")->execute([$uB, "wlr:$idA:credit", $idB]); T::ok(false, 'duplicate credit_key must be blocked'); }
catch (PDOException $e) { T::ok((int)$e->errorInfo[1] === 1062, 'DB blocks a duplicate idempotency key (UNIQUE credit_key)'); }
try { addRec('GUARD.A@example.com', 0, $admin); $dupe = cnt($db, "SELECT COUNT(*) FROM waitlist_rewards WHERE email_normalized='guard.a@example.com'"); T::eq($dupe, 1, 'DB/app keep one record per normalized email'); } catch (Throwable $e) { T::ok(true, 'duplicate insert refused'); }
$accent = mkUser($db, 'josé.test@example.com');   // not a valid address for FILTER_VALIDATE_EMAIL, so it must never match
T::eq(wlrFindAccount('jose.test@example.com'), null, 'accent-folded near-match is not treated as the same mailbox');

/* ================================================================= 7. after-credit edits and corrections */
T::section('7. Edits before credit are reflected; edits after credit never silently change XP');
wipe($db); rules($db, ['signup' => [50, 1], 'referral' => [10, 1]]); $admin = mkAdmin($db);
$id = addRec('edit@example.com', 2, $admin);
wlrSaveRecord($id, ['email' => 'edit@example.com', 'referral_count' => '7'], $admin);
$u = mkUser($db, 'edit@example.com'); $o = wlrOnRegistration($u, 'password');
T::eq($o['waitlist_xp'], 120, 'count edited 2 -> 7 before registration: pays 50 + 70');
$r = wlrSaveRecord($id, ['email' => 'edit@example.com', 'referral_count' => '9'], $admin);
T::ok(!$r['ok'], 'changing the count after crediting is refused');
T::eq(xp($db, $u), 120, 'XP unchanged by the refused edit');
T::eq((int)rec($db, $id)['referral_count'], 7, 'stored count unchanged');
T::ok(!wlrSaveRecord($id, ['email' => 'changed@example.com', 'referral_count' => '7'], $admin)['ok'], 'changing the email after crediting is refused');
T::ok(wlrSaveRecord($id, ['email' => 'edit@example.com', 'referral_count' => '7', 'admin_notes' => 'note only'], $admin)['ok'], 'notes can still be edited');
T::eq(wlrSetActive($id, false, $admin)['status'], 'credited', 'excluding a credited record is refused (no silent claw-back)');
T::eq(xp($db, $u), 120, 'XP still unchanged');
rules($db, ['signup' => [999, 1], 'referral' => [999, 1]]);
T::eq(xp($db, $u), 120, 'changing the rules later does not touch credited XP');
T::eq((int)rec($db, $id)['credited_referral_rate'], 10, 'snapshot keeps the original rate');
rules($db, ['signup' => [50, 1], 'referral' => [10, 1]]);

T::section('7b. Controlled correction');
$ok = wlrApplyCorrection($id, 9, 7, 'two more referrals verified', $admin);
T::eq([$ok['status'], $ok['applied'], $ok['total_xp']], ['ok', 20, 140], 'correction 7 -> 9 adds exactly 2 x 10 = 20 XP');
T::eq(xp($db, $u), 140, 'user XP = 140');
T::eq(ledgerSum($db, $u), 140, 'ledger still equals balance');
T::eq(cnt($db, "SELECT COUNT(*) FROM user_activity_log WHERE user_id=$u AND action_key='waitlist_correction'"), 1, 'one correction ledger row');
$ca = $db->query("SELECT * FROM waitlist_reward_audit WHERE reward_id=$id AND action='correction'")->fetch();
T::ok($ca && (int)$ca['xp_delta'] === 20 && str_contains($ca['details'], 'two more referrals verified'), 'correction audited with reason and amount');
T::eq(wlrApplyCorrection($id, 9, 7, 'replay of the same form', $admin)['status'], 'stale', 'replaying the same form is rejected as stale');
T::eq(xp($db, $u), 140, 'replay did not change XP');
T::eq(wlrApplyCorrection($id, 9, 9, 'same count', $admin)['status'], 'no_change', 'no-op correction refused');
T::eq(wlrApplyCorrection($id, 5, 9, '', $admin)['status'], 'bad_reason', 'reason is mandatory');
$neg = wlrApplyCorrection($id, 5, 9, 'overcounted', $admin);
T::eq([$neg['status'], $neg['applied'], xp($db, $u)], ['ok', -40, 100], 'negative correction removes 4 x 10 XP');
$db->exec("UPDATE users SET total_xp = 10 WHERE id = $u");
$neg2 = wlrApplyCorrection($id, 0, 5, 'clamp test', $admin);
T::eq([$neg2['status'], $neg2['applied'], xp($db, $u)], ['ok', -10, 0], 'balance never goes below 0 (requested -50, applied -10)');
T::ok(str_contains($db->query("SELECT details FROM waitlist_reward_audit WHERE reward_id=$id AND action='correction' ORDER BY id DESC LIMIT 1")->fetchColumn(), '"clamped_at_zero":true'), 'clamping is recorded in the audit');
$idN = addRec('nocredit@example.com', 1, $admin);
T::eq(wlrApplyCorrection($idN, 3, 1, 'x', $admin)['status'], 'not_credited', 'cannot correct an uncredited record');

/* ================================================================= 8. concurrency */
T::section('8. Concurrency (real parallel PHP processes against the real database)');
wipe($db); rules($db, ['signup' => [50, 1], 'referral' => [10, 1]]); $admin = mkAdmin($db);
$id = addRec('race@example.com', 4, $admin); $u = mkUser($db, 'race@example.com');
$res = parallel(array_map(fn($i) => ['credit', $u, $i % 2 ? 'google' : 'password'], range(1, 14)));
T::eq(statuses($res), ['already' => 13, 'credited' => 1], '14 simultaneous credit attempts: exactly 1 credited, 13 already');
T::eq(xp($db, $u), 90, 'XP credited exactly once (90)');
T::eq(cnt($db, "SELECT COUNT(*) FROM user_activity_log WHERE user_id=$u AND action_key LIKE 'waitlist_%'"), 2, 'exactly 2 ledger rows');
T::eq(cnt($db, "SELECT COUNT(*) FROM waitlist_reward_audit WHERE reward_id=$id AND action='credited'"), 1, 'exactly 1 credited audit row');
T::eq(ledgerSum($db, $u), xp($db, $u), 'ledger equals balance');

$id = addRec('race2@example.com', 2, $admin); $u2 = mkUser($db, 'race2@example.com');
$jobs = []; for ($i = 0; $i < 6; $i++) { $jobs[] = ['register', $u2, 'password']; $jobs[] = ['credit_now', $id, $admin]; }
$res = parallel($jobs);
T::eq(xp($db, $u2), 70, 'registration + admin "Credit now" racing: paid once (70)');
T::eq(cnt($db, "SELECT COUNT(*) FROM waitlist_reward_audit WHERE reward_id=$id AND action='credited'"), 1, 'one credited audit row');
T::eq(cnt($db, "SELECT COUNT(*) FROM user_activity_log WHERE user_id=$u2 AND action_key LIKE 'waitlist_%'"), 2, 'two ledger rows');

$ids = []; $us = [];
for ($i = 0; $i < 5; $i++) { $ids[$i] = addRec("multi$i@example.com", $i, $admin); $us[$i] = mkUser($db, "multi$i@example.com"); }
$jobs = []; foreach ($us as $i => $uid) for ($k = 0; $k < 3; $k++) $jobs[] = ['credit', $uid, 'password'];
$res = parallel($jobs);
T::eq(statuses($res), ['already' => 10, 'credited' => 5], '5 members x 3 racing attempts each: 5 credited, 10 already');
$ok = true; foreach ($us as $i => $uid) $ok = $ok && xp($db, $uid) === 50 + 10 * $i; T::ok($ok, 'each member received exactly their own amount');

$id = addRec('race3@example.com', 3, $admin); $u3 = mkUser($db, 'race3@example.com'); wlrCreditForUser($u3, 'password');   // 80
$res = parallel(array_map(fn($i) => ['correct', $id, 5, 3, $admin], range(1, 8)));
T::eq(statuses($res), ['ok' => 1, 'stale' => 7], '8 identical correction submissions: applied once, 7 rejected as stale');
T::eq(xp($db, $u3), 100, 'correction applied exactly once (80 + 2x10)');

/* ================================================================= 9. existing XP features unaffected */
T::section('9. Existing XP behaviour is unchanged (awardXp / levels)');
$ux = mkUser($db, 'regress@example.com'); $db->exec("UPDATE xp_rules SET xp_value=10, is_active=1 WHERE action_key='lesson_complete'");
T::eq(awardXp($ux, 'lesson_complete', 'lesson', 77), 10, 'awardXp still pays a normal rule');
T::eq(awardXp($ux, 'lesson_complete', 'lesson', 77), 0, 'awardXp still de-duplicates sequential repeats');
T::eq(awardXp($ux, 'lesson_complete', 'lesson', 78), 10, 'awardXp pays a different reference');
T::eq(xp($db, $ux), 20, 'balance 20');
T::eq(awardXp($ux, 'registration_welcome', 'registration', $ux), 0, 'welcome rule inactive/0 pays nothing through awardXp');
T::ok(function_exists('recalculateLevel') && function_exists('awardOnboardingReward'), 'level + onboarding functions still present');
$o = awardOnboardingReward($ux);
T::ok(is_array($o) && ($o['xp_awarded'] ?? -1) >= 0, 'onboarding reward flow still works (xp ' . ($o['xp_awarded'] ?? '?') . ')');

/* ================================================================= 10. HTTP: access control, CSRF, routes, registration */
T::section('10. HTTP (PHP built-in server): admin-only access, CSRF, removed routes, real registration');
wipe($db); rules($db, ['welcome' => [25, 1], 'signup' => [50, 1], 'referral' => [10, 1]]); $admin = mkAdmin($db);
$port = random_int(20000, 40000);
$srv = proc_open([$PHP, '-S', "127.0.0.1:$port", '-t', $ROOT], [1 => ['file', '/dev/null', 'w'], 2 => ['file', sys_get_temp_dir() . '/wlr_srv.log', 'w']], $sp);
$base = "http://127.0.0.1:$port";
for ($i = 0; $i < 40; $i++) { $f = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($f) { fclose($f); break; } usleep(100000); }
function http(string $method, string $url, array $post = [], string $jar = '', array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
    if ($jar !== '') { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    $out = (string)curl_exec($ch); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $h = substr($out, 0, $hs); preg_match('/^Location:\s*(.+)$/mi', $h, $m);
    return ['code' => $code, 'body' => substr($out, $hs), 'loc' => trim($m[1] ?? '')];
}
function csrfOf(string $html): string { preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m); return $m[1] ?? ''; }

$r = http('GET', "$base/admin/waitlist-rewards.php");
T::ok($r['code'] === 302 && str_contains($r['loc'], '/admin/login.php'), 'unauthenticated visitor is redirected to admin login');
$r = http('POST', "$base/admin/waitlist-rewards.php", ['action' => 'add_record', 'email' => 'x@example.com']);
T::ok($r['code'] === 302 && !cnt($db, 'SELECT COUNT(*) FROM waitlist_rewards'), 'unauthenticated POST creates nothing');

// ordinary user session
$uj = tempnam(sys_get_temp_dir(), 'wlrj');
$r = http('POST', "$base/register.php", ['email' => 'ordinary@example.com', 'password' => 'secret123', 'confirm_password' => 'secret123'], $uj);
T::ok($r['code'] === 302 && str_contains($r['loc'], 'onboarding.php'), 'password registration works over HTTP (no matching record)');
T::eq(xp($db, (int)$db->query("SELECT id FROM users WHERE email='ordinary@example.com'")->fetchColumn()), 25, 'registration without a record: welcome bonus 25 only');
$r = http('GET', "$base/admin/waitlist-rewards.php", [], $uj);
T::ok($r['code'] === 302 && str_contains($r['loc'], '/admin/login.php'), 'a logged-in ORDINARY user cannot open the rewards admin page');
$r = http('POST', "$base/admin/waitlist-rewards.php", ['action' => 'add_record', 'email' => 'y@example.com'], $uj);
T::ok($r['code'] === 302 && !cnt($db, 'SELECT COUNT(*) FROM waitlist_rewards'), 'ordinary user POST creates nothing');

// admin session
$aj = tempnam(sys_get_temp_dir(), 'wlra');
$r = http('POST', "$base/admin/login.php", ['email' => 'admin@test.local', 'password' => 'adminpw123'], $aj);
T::ok($r['code'] === 302, 'admin logs in');
$page = http('GET', "$base/admin/waitlist-rewards.php", [], $aj);
T::ok($page['code'] === 200 && str_contains($page['body'], 'Waitlist Rewards'), 'admin sees the page');
$tok = csrfOf($page['body']); T::ok($tok !== '', 'page carries a CSRF token');
$r = http('POST', "$base/admin/waitlist-rewards.php", ['action' => 'add_record', 'email' => 'nocsrf@example.com', 'referral_count' => '1'], $aj);
T::ok($r['code'] === 403 && !cnt($db, "SELECT COUNT(*) FROM waitlist_rewards WHERE email_normalized='nocsrf@example.com'"), 'POST without CSRF token is refused (403), nothing created');
$r = http('POST', "$base/admin/waitlist-rewards.php", ['action' => 'add_record', 'email' => 'badcsrf@example.com', 'referral_count' => '1', 'csrf_token' => str_repeat('a', 64)], $aj);
T::ok($r['code'] === 403, 'POST with a wrong CSRF token is refused (403)');
$r = http('POST', "$base/admin/waitlist-rewards.php", ['action' => 'add_record', 'email' => 'Web.User@Example.com', 'referral_count' => '3', 'csrf_token' => $tok], $aj);
T::ok($r['code'] === 303 && cnt($db, "SELECT COUNT(*) FROM waitlist_rewards WHERE email_normalized='web.user@example.com'") === 1, 'admin POST with valid CSRF creates the record');
$r = http('POST', "$base/admin/waitlist-rewards.php", ['action' => 'add_record', 'email' => 'WEB.user@example.com', 'referral_count' => '1', 'csrf_token' => $tok], $aj);
T::ok($r['code'] === 200 && str_contains($r['body'], 'already exists') && cnt($db, 'SELECT COUNT(*) FROM waitlist_rewards') === 1, 'duplicate email rejected in the UI with a clear message');
$r = http('POST', "$base/admin/waitlist-rewards.php", ['action' => 'add_record', 'email' => 'neg@example.com', 'referral_count' => '-3', 'csrf_token' => $tok], $aj);
T::ok($r['code'] === 200 && str_contains($r['body'], 'whole number') && cnt($db, "SELECT COUNT(*) FROM waitlist_rewards WHERE email_normalized='neg@example.com'") === 0, 'negative count rejected server-side');
$r = http('POST', "$base/admin/waitlist-rewards.php", ['action' => 'add_record', 'email' => '<script>alert(1)</script>@example.com', 'referral_count' => '0', 'csrf_token' => $tok], $aj);
T::ok(!str_contains($r['body'], '<script>alert(1)</script>'), 'hostile input is escaped in the response');
$lst = http('GET', "$base/admin/waitlist-rewards.php?q=web.user&status=waiting", [], $aj);
T::ok($lst['code'] === 200 && str_contains($lst['body'], 'web.user@example.com'), 'search + status filter work over HTTP');
$det = http('GET', "$base/admin/waitlist-rewards.php?id=" . (int)$db->query("SELECT id FROM waitlist_rewards WHERE email_normalized='web.user@example.com'")->fetchColumn(), [], $aj);
T::ok(str_contains($det['body'], '50 + ') || str_contains($det['body'], 'Calculated reward'), 'detail page shows the calculated breakdown');
T::ok(str_contains($det['body'], '<b>80</b> XP'), 'breakdown total is 50 + 3 x 10 = 80');

// real registration with a matching record
$rj = tempnam(sys_get_temp_dir(), 'wlrr');
$r = http('POST', "$base/register.php", ['email' => 'WEB.USER@example.com ', 'password' => 'secret123', 'confirm_password' => 'secret123'], $rj);
$wu = (int)$db->query("SELECT id FROM users WHERE email LIKE 'web.user@example.com%'")->fetchColumn();
T::ok($r['code'] === 302 && $wu > 0, 'password registration with a matching record succeeds');
T::eq(xp($db, $wu), 25 + 80, 'registered user got welcome 25 + waitlist 80 = 105');
$rid = (int)$db->query("SELECT id FROM waitlist_rewards WHERE email_normalized='web.user@example.com'")->fetchColumn();
T::eq([wlrGet($rid)['status'], (int)rec($db, $rid)['credited_user_id']], ['credited', $wu], 'record shows credited and linked to the new account');
$dupReg = http('POST', "$base/register.php", ['email' => 'web.user@example.com', 'password' => 'secret123', 'confirm_password' => 'secret123'], tempnam(sys_get_temp_dir(), 'wlrx'));
T::ok($dupReg['code'] === 200 && xp($db, $wu) === 105, 're-registering the same email is refused and pays nothing');
// unverified-email policy off: password sign-up does not auto-credit, Google would
$page = http('GET', "$base/admin/waitlist-rewards.php?tab=rules", [], $aj); $tok = csrfOf($page['body']);
http('POST', "$base/admin/waitlist-rewards.php", ['action' => 'add_record', 'email' => 'policy@example.com', 'referral_count' => '1', 'csrf_token' => $tok], $aj);
$r = http('POST', "$base/admin/waitlist-rewards.php", ['action' => 'save_rules', 'xp' => ['registration_welcome' => '25', 'waitlist_signup' => '50', 'waitlist_referral' => '10'], 'active' => ['registration_welcome' => '1', 'waitlist_signup' => '1', 'waitlist_referral' => '1'], 'csrf_token' => $tok], $aj);
T::ok($r['code'] === 303 && getSetting('waitlist_credit_unverified_email', '1') !== null, 'rules form saves (credit_unverified unticked)');
$val = $db->query("SELECT setting_val FROM site_settings WHERE setting_key='waitlist_credit_unverified_email'")->fetchColumn();
T::eq($val, '0', 'unverified-email credit switched off');
$pj = tempnam(sys_get_temp_dir(), 'wlrp');
http('POST', "$base/register.php", ['email' => 'policy@example.com', 'password' => 'secret123', 'confirm_password' => 'secret123'], $pj);
$pu = (int)$db->query("SELECT id FROM users WHERE email='policy@example.com'")->fetchColumn();
T::eq(xp($db, $pu), 25, 'policy off: password registration gets welcome only, waitlist reward deferred');
$pid = (int)$db->query("SELECT id FROM waitlist_rewards WHERE email_normalized='policy@example.com'")->fetchColumn();
T::eq(wlrGet($pid)['status'], 'ready', 'deferred record shows "ready to credit"');
$page = http('GET', "$base/admin/waitlist-rewards.php?id=$pid", [], $aj); $tok = csrfOf($page['body']);
$r = http('POST', "$base/admin/waitlist-rewards.php", ['action' => 'credit_now', 'id' => $pid, 'csrf_token' => $tok], $aj);
T::ok($r['code'] === 303 && xp($db, $pu) === 25 + 60, 'admin "Credit now" pays 50 + 1 x 10 once');
http('POST', "$base/admin/waitlist-rewards.php", ['action' => 'credit_now', 'id' => $pid, 'csrf_token' => $tok], $aj);
T::eq(xp($db, $pu), 85, 'a second "Credit now" click pays nothing more');
// HTTP correction requires typed confirmation
$r = http('POST', "$base/admin/waitlist-rewards.php", ['action' => 'correct', 'id' => $pid, 'new_count' => '5', 'expected_count' => '1', 'reason' => 'x', 'confirm' => 'nope', 'csrf_token' => $tok], $aj);
T::eq(xp($db, $pu), 85, 'correction without typed CORRECT does nothing');
$r = http('POST', "$base/admin/waitlist-rewards.php", ['action' => 'correct', 'id' => $pid, 'new_count' => '5', 'expected_count' => '1', 'reason' => 'verified 4 more', 'confirm' => 'CORRECT', 'csrf_token' => $tok], $aj);
T::eq(xp($db, $pu), 125, 'correction over HTTP applies +40 once');

// removed routes and files
foreach (['/claim.php', '/api/waitlist-claim.php', '/admin/prelaunch.php', '/assets/js/waitlist-claim.js', '/includes/waitlist_integration.php', '/includes/prelaunch_gate.php'] as $p) {
    $r = http('GET', $base . $p); T::ok($r['code'] === 404, "old route $p is gone (HTTP {$r['code']})");
}
$r = http('POST', "$base/api/waitlist-claim.php", ['action' => 'redeem']); T::ok($r['code'] === 404, 'old claim endpoint POST is gone');
// pre-launch gate removed: a stale prelaunch_lock=1 value must not block anything
$db->exec("INSERT INTO site_settings (setting_key, setting_val) VALUES ('prelaunch_lock','1') ON DUPLICATE KEY UPDATE setting_val='1'");
$r = http('GET', "$base/login.php"); T::ok($r['code'] === 200, 'login page still served with a stale prelaunch_lock=1 row (gate code removed; HTTP ' . $r['code'] . ')');
$r = http('GET', "$base/login.php", [], '', ['Accept: application/json']); T::ok($r['code'] === 200, 'no 503 gate behaviour remains');
proc_terminate($srv); proc_close($srv);

/* ================================================================= 11. static: nothing obsolete remains */
T::section('11. No obsolete integration code, routes or secrets remain in the project');
$bad = []; $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname(); if (preg_match('~/(uploads|vendor|tests|\.git)/~', $p) || !preg_match('~\.(php|js|txt|md|json|sql|py|htaccess)$~', $p) || str_contains($p, 'waitlist-rewards-00')) continue;
    $s = (string)file_get_contents($p);
    if (preg_match('/WAITLIST_API_(BASE|KEY_ID|SECRET)|WAITLIST_AUTO_MATCH|waitlistOnSession|waitlistClaim\(|prelaunchGate|wl_verified_email|waitlist_claims|waitlist_checks|api\/v1\/claims|X-W3E-/', $s)) $bad[] = substr($p, strlen($ROOT));
}
T::eq($bad, [], 'no references to the old API, claim, auto-match or pre-launch gate');
T::ok(!file_exists("$ROOT/includes/waitlist_integration.php") && !file_exists("$ROOT/claim.php") && !file_exists("$ROOT/api/waitlist-claim.php"), 'old files are deleted');

echo "\n==============================\n";
echo T::$pass . ' passed, ' . T::$fail . " failed\n";
if (T::$fail) { echo "FAILURES:\n - " . implode("\n - ", T::$failures) . "\n"; }
exit(T::$fail ? 1 : 0);
