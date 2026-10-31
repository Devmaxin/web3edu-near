<?php
/**
 * Web3Edu — Login Attempt Throttling (shared helper)
 *
 * Added as part of the Phase 1E security remediation, addressing the
 * "no brute-force protection on login.php or admin/login.php" finding.
 * Both login endpoints call these three functions; the mechanism itself
 * doesn't care whether the attempt is against the `users` or `admins`
 * table — it only ever sees the email string the visitor submitted and
 * their client IP, so one implementation protects both login flows.
 *
 * Design (see the Phase 1E "Step 2" report for the full reasoning):
 *   - Two independent sliding-window counters, both keyed off the same
 *     `login_attempts` table (see database/login-throttle-migration.sql):
 *       "email:<lowercased submitted email>" — stops one account being
 *           hammered from many different IPs.
 *       "ip:<client ip>"                     — stops one IP spraying
 *           many different accounts.
 *   - Both counters are self-expiring (a plain COUNT(*) over the last
 *     WINDOW_MINUTES), so there is no permanent lockout and nothing to
 *     manually clear — waiting out the window is always enough.
 *   - A successful login clears only that email's own rows, never the
 *     IP-keyed rows, since the IP key is a shared resource other
 *     accounts' attempts may also be counted under.
 *   - Thresholds are deliberately different (5 for an email, 20 for an
 *     IP) so a shared/office IP with a few genuine typos from different
 *     colleagues doesn't get caught by the same tight limit that stops a
 *     targeted attack on one account.
 *   - Every function fails OPEN (never blocks, never throws) if the
 *     `login_attempts` table doesn't exist yet or the DB is unreachable,
 *     mirroring the existing defensive pattern in getSetting() —
 *     forgetting to run the migration must not lock everyone out of
 *     login, and a throttle outage must never become a login outage.
 *     This covers BOTH failure shapes: a query failing against an
 *     already-open connection (caught as \PDOException below), and no
 *     connection being available at all (handled by getDBSafe(), since
 *     getDB() itself die()s on that specific failure rather than
 *     throwing something a try/catch here could ever intercept).
 *   - Nothing here ever stores a password, a session token, or any other
 *     authentication secret — only the throttle key string (an email or
 *     an IP address) and a timestamp.
 */

const LOGIN_THROTTLE_WINDOW_MINUTES   = 15;
const LOGIN_THROTTLE_MAX_PER_EMAIL    = 5;
const LOGIN_THROTTLE_MAX_PER_IP       = 20;
const LOGIN_THROTTLE_GENERIC_MESSAGE  = 'Too many login attempts. Please wait a few minutes and try again.';

/**
 * Best-effort client IP. Web3Edu sits behind Cloudflare, which sets
 * CF-Connecting-IP at its edge and strips any client-supplied copy of
 * that same header before forwarding the request on — safe to trust
 * as-is. X-Forwarded-For is deliberately NOT used here: its leftmost
 * entry is exactly the part a client can set to anything they like, so
 * trusting it would let an attacker rotate a fake value on every
 * request and defeat the per-IP counter entirely, or frame an unrelated
 * IP. Falls back to REMOTE_ADDR — the only source the rest of this
 * codebase already uses (certificate download logging in
 * includes/certificates.php) — for any request that somehow reaches the
 * app without going through Cloudflare.
 */
function getClientIp(): string
{
    $cfIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
    if ($cfIp !== '' && filter_var($cfIp, FILTER_VALIDATE_IP)) {
        return $cfIp;
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Like getDB(), but never die()s — returns null instead when a
 * connection can't be established. Used only by the three throttle
 * functions below, so a connection-cap hiccup (InfinityFree's
 * documented whole-account connection limit — see the comment above
 * closeDB() in includes/config.php) can fail the throttle open instead
 * of taking down the entire login page, which is what happened before
 * this fix: getDB()'s own internal catch calls die() on a connection
 * failure, and die() isn't a throwable, so no try/catch around a
 * getDB() call — including the ones previously in this file — could
 * ever intercept it.
 *
 * This deliberately reads and writes the SAME global $__web3edu_pdo
 * singleton getDB() itself uses, rather than keeping a separate
 * connection of its own:
 *   - If getDB() has already been called elsewhere earlier in this same
 *     request (e.g. by header.php), that connection is reused as-is —
 *     no second connection is ever opened in that case.
 *   - If this is the first DB touch of the request and the connection
 *     attempt here succeeds, it's stored into that same global, so a
 *     later getDB() call in the same request reuses it too, rather than
 *     opening a second one of its own.
 *   - Only the failure handling differs from getDB(): null instead of
 *     die(). The DSN, credentials, and PDO options below are read from
 *     the exact same DB_HOST/DB_USER/DB_PASS/DB_NAME/DB_CHARSET
 *     constants getDB() uses (defined once, in secrets/secrets.php and
 *     includes/config.php) — nothing is duplicated, hardcoded, or
 *     sourced differently here.
 */
function getDBSafe(): ?PDO
{
    global $__web3edu_pdo;
    if ($__web3edu_pdo instanceof PDO) {
        return $__web3edu_pdo;
    }
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $__web3edu_pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $__web3edu_pdo;
    } catch (\PDOException $e) {
        return null;
    }
}

/**
 * True if either the submitted email or the current IP has hit its
 * failed-attempt limit within the sliding window. Callers should check
 * this BEFORE calling password_verify(), so a throttled request never
 * even reaches the password check.
 *
 * $domain scopes the email-keyed counter to the calling auth flow
 * ('user' for login.php, 'admin' for admin/login.php) so that spamming
 * one login form with an email can never throttle the *other* login
 * form for that same address — e.g. an attacker flooding the public
 * login.php with a known admin email must not be able to lock the
 * admin login out for that address. The IP-keyed counter is
 * deliberately NOT domain-scoped: it's about the network source, not
 * the account type, and splitting it would just hand an attacker twice
 * the IP-based attempt budget by alternating between the two forms.
 */
function isLoginThrottled(string $email, string $domain = 'user'): bool
{
    $db = getDBSafe();
    if ($db === null) {
        // No connection available at all — fail OPEN rather than letting
        // getDB() die() further down the request for the same reason.
        return false;
    }
    try {
        // The window cutoff is computed by MySQL itself (NOW() - INTERVAL
        // ... MINUTE), not PHP's date()/time() — attempted_at is also
        // stamped by MySQL's own clock (DEFAULT CURRENT_TIMESTAMP), so
        // both sides of this comparison now come from the same clock.
        // PHP's timezone no longer participates in this calculation at
        // all, which is what previously caused real, recent attempts to
        // be undercounted whenever PHP's and MySQL's configured
        // timezones didn't agree. LOGIN_THROTTLE_WINDOW_MINUTES is a
        // fixed internal PHP constant (never request-derived), so
        // interpolating it directly into the SQL text here carries no
        // injection risk — it's the same trusted value, just placed in
        // the query text instead of passed as a bound parameter, which
        // sidesteps any driver/version inconsistency around binding a
        // parameter inside an INTERVAL expression.
        $windowMinutes = (int)LOGIN_THROTTLE_WINDOW_MINUTES;

        $emailKey = $domain . '_email:' . strtolower(trim($email));
        $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE throttle_key = ? AND attempted_at > NOW() - INTERVAL {$windowMinutes} MINUTE");
        $stmt->execute([$emailKey]);
        if ((int)$stmt->fetchColumn() >= LOGIN_THROTTLE_MAX_PER_EMAIL) {
            return true;
        }

        $ipKey = 'ip:' . getClientIp();
        $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE throttle_key = ? AND attempted_at > NOW() - INTERVAL {$windowMinutes} MINUTE");
        $stmt->execute([$ipKey]);
        if ((int)$stmt->fetchColumn() >= LOGIN_THROTTLE_MAX_PER_IP) {
            return true;
        }

        return false;
    } catch (\PDOException $e) {
        // Connection is up (handled above) but the query itself failed —
        // most likely the login_attempts table hasn't been migrated yet.
        // Fail OPEN here too, for the same reason. Scoped to PDOException
        // specifically (not \Throwable) so a genuine programming error
        // elsewhere in this function still surfaces normally during
        // development/staging instead of being silently swallowed.
        return false;
    }
}

/**
 * Record one failed login attempt under both the email and IP keys.
 * Call this only after a login attempt has actually failed (wrong
 * password, or account not found) — never for a throttled request that
 * was already rejected before checking the password, and never for a
 * successful login. $domain must match the value passed to
 * isLoginThrottled()/clearLoginThrottle() for the same login flow.
 */
function recordFailedLogin(string $email, string $domain = 'user'): void
{
    $db = getDBSafe();
    if ($db === null) {
        // No connection available — silently skip recording and let the
        // existing login failure response continue as normal.
        return;
    }
    try {
        $ins = $db->prepare("INSERT INTO login_attempts (throttle_key) VALUES (?)");
        $ins->execute([$domain . '_email:' . strtolower(trim($email))]);
        $ins->execute(['ip:' . getClientIp()]);

        // Opportunistic cleanup — no separate cron job needed. Cheap
        // (~1-in-50 writes) and only ever deletes rows already outside
        // every window this file uses, so it can never interfere with
        // an active throttle.
        if (random_int(1, 50) === 1) {
            $db->prepare("DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY")->execute();
        }
    } catch (\PDOException $e) {
        // Connection is up (handled above) but the query itself failed —
        // most likely the login_attempts table hasn't been migrated yet.
        // Never let logging a failed attempt itself break the login page.
        // See the note in isLoginThrottled() on why this catches
        // PDOException, not \Throwable.
    }
}

/**
 * Clear a successful account's own throttle history for the given auth
 * domain. Deliberately does NOT touch IP-keyed rows — the IP key is
 * shared with any other account that may have failed attempts counted
 * under it, and one account succeeding shouldn't reset another
 * account's protection. Also does not touch the *other* domain's key
 * for the same email — a successful user login must not clear an
 * admin-login throttle for the same address, or vice versa.
 */
function clearLoginThrottle(string $email, string $domain = 'user'): void
{
    $db = getDBSafe();
    if ($db === null) {
        // No connection available — safely do nothing. Worst case, a few
        // old rows linger and age out of the window naturally on their
        // own; the login that just succeeded is otherwise unaffected.
        return;
    }
    try {
        $db->prepare("DELETE FROM login_attempts WHERE throttle_key = ?")
           ->execute([$domain . '_email:' . strtolower(trim($email))]);
    } catch (\PDOException $e) {
        // Connection is up (handled above) but the query itself failed —
        // non-fatal either way; worst case a few old rows linger.
    }
}
