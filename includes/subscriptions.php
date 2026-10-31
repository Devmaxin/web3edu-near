<?php
require_once __DIR__ . '/payments/PaystackProvider.php';
require_once __DIR__ . '/certificates.php';

/** Returns the active payment provider. Swap here if a second provider is added. */
function getPaymentProvider(): PaymentProviderInterface
{
    return new PaystackProvider();
}

/** All active, non-deleted plans in display order, with their feature list attached. */
function getActivePlans(): array
{
    $db    = getDB();
    $plans = $db->query("
        SELECT * FROM premium_plans WHERE is_active = 1 AND deleted_at IS NULL ORDER BY sort_order ASC
    ")->fetchAll();

    foreach ($plans as &$plan) {
        $stmt = $db->prepare("SELECT feature_text FROM plan_features WHERE plan_id = ? ORDER BY sort_order ASC");
        $stmt->execute([$plan['id']]);
        $plan['features'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    unset($plan);

    return $plans;
}

function getPlanBySlug(string $slug): ?array
{
    $stmt = getDB()->prepare("SELECT * FROM premium_plans WHERE slug = ? AND is_active = 1 AND deleted_at IS NULL");
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

function getPlanById(int $id): ?array
{
    $stmt = getDB()->prepare("SELECT * FROM premium_plans WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** The user's most recent subscription record, with plan details joined in. */
function getUserSubscription(int $userId): ?array
{
    $stmt = getDB()->prepare("
        SELECT us.*, pp.name AS plan_name, pp.billing_cycle, pp.price_kobo
        FROM user_subscriptions us
        INNER JOIN premium_plans pp ON pp.id = us.plan_id
        WHERE us.user_id = ?
        ORDER BY us.created_at DESC LIMIT 1
    ");
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

/**
 * Monthly/yearly are the only purchasable cycles. (Existing legacy subscribers
 * with a permanent NULL expires_at, if any, keep their access — see
 * syncMembershipCache() — but no new subscription is ever created that way.)
 */
function calculateExpiry(string $billingCycle, ?string $from = null): ?string
{
    $base = $from ? new DateTime($from) : new DateTime();
    $base->modify($billingCycle === 'monthly' ? '+1 month' : '+1 year');
    return $base->format('Y-m-d H:i:s');
}

/**
 * Activates (or renews) a user's subscription after a verified successful
 * payment. Handles both a brand new subscription and a renewal of an
 * existing one. Keeps users.membership_type in sync (cached fast-read flag).
 */
function activateSubscription(int $userId, array $plan, array $verification, string $reference): int
{
    $db  = getDB();
    $now = date('Y-m-d H:i:s');

    $existing = getUserSubscription($userId);
    $isRenewal = $existing && $existing['plan_id'] == $plan['id'] && $existing['status'] === 'active';
    $expiresAt = calculateExpiry($plan['billing_cycle'], $isRenewal ? $existing['expires_at'] : $now);

    if ($existing && in_array($existing['status'], ['active', 'pending'], true)) {
        $db->prepare("
            UPDATE user_subscriptions
            SET plan_id=?, status='active', provider=?, provider_customer_code=?,
                provider_authorization_code=?, started_at=COALESCE(started_at, ?),
                expires_at=?, renews_at=?, cancel_at_period_end=0, cancelled_at=NULL
            WHERE id=?
        ")->execute([
            $plan['id'], 'paystack', $verification['customer_code'] ?? null,
            $verification['authorization_code'] ?? null, $now,
            $expiresAt, $expiresAt, $existing['id'],
        ]);
        $subscriptionId = (int)$existing['id'];
    } else {
        $db->prepare("
            INSERT INTO user_subscriptions
                (user_id, plan_id, status, provider, provider_customer_code, provider_authorization_code, started_at, expires_at, renews_at)
            VALUES (?, ?, 'active', 'paystack', ?, ?, ?, ?, ?)
        ")->execute([
            $userId, $plan['id'], $verification['customer_code'] ?? null,
            $verification['authorization_code'] ?? null, $now, $expiresAt, $expiresAt,
        ]);
        $subscriptionId = (int)$db->lastInsertId();
    }

    // Attempt recurring billing setup for monthly/yearly (gracefully no-ops if
    // the plan isn't synced to a provider_plan_code yet — see PaystackProvider).
    if (in_array($plan['billing_cycle'], ['monthly', 'yearly'], true)
        && !empty($verification['authorization_reusable'])
        && !empty($verification['authorization_code'])
        && !empty($verification['customer_code'])
    ) {
        $userStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $userRow = $userStmt->fetch();

        $subResult = getPaymentProvider()->createSubscription($userRow, $plan, $verification['authorization_code'], $verification['customer_code']);
        if (!empty($subResult['success'])) {
            $db->prepare("UPDATE user_subscriptions SET provider_subscription_code=? WHERE id=?")
               ->execute([$subResult['subscription_code'], $subscriptionId]);
        }
    }

    // Record the payment against the existing purchases ledger. A pending row
    // may already exist (created when checkout was initiated) — update it
    // rather than inserting a duplicate, since `reference` is unique.
    $db->prepare("
        INSERT INTO purchases (user_id, reference, amount, status, plan_id, subscription_id, provider, billing_cycle)
        VALUES (?, ?, ?, 'success', ?, ?, 'paystack', ?)
        ON DUPLICATE KEY UPDATE status='success', amount=VALUES(amount), subscription_id=VALUES(subscription_id)
    ")->execute([
        $userId, $reference, $verification['amount_kobo'] / 100,
        $plan['id'], $subscriptionId, $plan['billing_cycle'],
    ]);

    syncMembershipCache($userId);

    $amountNaira = $verification['amount_kobo'] / 100;
    logSubscriptionHistory($subscriptionId, $userId, $isRenewal ? 'renewed' : 'created', $plan['name'], $reference, $amountNaira);

    Analytics::trackOnce('payment_completed', $userId, [
        'plan_id' => $plan['id'], 'plan_name' => $plan['name'], 'amount' => $amountNaira,
        'currency' => 'NGN', 'payment_reference' => $reference,
    ], 'payment_reference'); // idempotency: a reference is unique per successful payment (purchases.reference is UNIQUE)

    Analytics::track($isRenewal ? 'subscription_renewal' : 'subscription_started', $userId, [
        'plan_id' => $plan['id'], 'plan_name' => $plan['name'], 'billing_cycle' => $plan['billing_cycle'],
    ]);

    // Certificate automation: "premium purchased" rules (Admin -> Certificates
    // -> Rules). Only on first-time activation, not renewals.
    if (!$isRenewal) {
        evaluateCertificateRules($userId, 'premium_active', ['plan_id' => $plan['id'], 'plan_name' => $plan['name']]);
    }

    // Email: purchase (new) or renewal (existing active plan)
    $userStmt = $db->prepare("SELECT username, email FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $userInfo = $userStmt->fetch();

    // Admin alert — covers both "new subscription purchased" / "subscription
    // renewed" and "payment succeeded" (they're the same event on this path;
    // the webhook's recurring-charge path notifies separately).
    adminNotify(
        $isRenewal ? 'subscription_renewed' : 'subscription_purchased',
        $isRenewal ? 'Subscription renewed' : 'New premium subscription',
        ($userInfo['username'] ?? $userInfo['email'] ?? 'A user') . ' ' . ($isRenewal ? 'renewed' : 'purchased') .
            " the {$plan['name']} plan (₦" . number_format($amountNaira, 2) . ').',
        SITE_URL . '/admin/subscriptions.php?action=view&id=' . $subscriptionId
    );

    if (!$isRenewal) {
        notifyAdmins('premium_purchased_admin', [
            'username' => $userInfo['username'] ?? $userInfo['email'] ?? 'A user',
            'email'    => $userInfo['email'] ?? '',
            'plan_name'=> $plan['name'],
            'amount'   => number_format($amountNaira, 2),
            'link'     => SITE_URL . '/admin/subscriptions.php?action=view&id=' . $subscriptionId,
        ]);
    }

    // immediate: true — payment confirmation should land right after the
    // user completes checkout, not up to 60s later via the background runner.
    if ($isRenewal) {
        emailUser($userId, 'premium_renewed', [
            'plan_name'     => $plan['name'],
            'renewal_date'  => $expiresAt ? date('F j, Y', strtotime($expiresAt)) : 'Never',
        ], immediate: true);
    } else {
        emailUser($userId, 'premium_purchase', [
            'plan_name' => $plan['name'],
            'amount'    => number_format($verification['amount_kobo'] / 100, 2),
        ], immediate: true);
    }

    // In-app notification
    notifyUser(
        $userId, 'premium_purchase', 'Welcome to Premium!',
        "Your {$plan['name']} plan is active and will renew on " . date('M j, Y', strtotime($expiresAt)) . '.',
        SITE_URL . '/dashboard.php'
    );

    return $subscriptionId;
}

/** Keeps users.membership_type (fast cached flag) in sync with the real subscription state. */
function syncMembershipCache(int $userId): void
{
    $db = getDB();
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM user_subscriptions
        WHERE user_id = ? AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW())
    ");
    $stmt->execute([$userId]);
    $hasActive = (int)$stmt->fetchColumn() > 0;

    $db->prepare("UPDATE users SET membership_type = ? WHERE id = ?")
       ->execute([$hasActive ? 'premium' : 'free', $userId]);

    if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $userId) {
        $_SESSION['membership_type'] = $hasActive ? 'premium' : 'free';
    }
}

/**
 * THE canonical, real-time premium check. Every premium gate in the app
 * (lesson pages, guide pages, airdrop guides, AI Tutor, certificate rules,
 * etc.) should call this — never read users.membership_type directly.
 *
 * It checks actual subscription state (status = active AND now < expiry),
 * not a cached flag. Because cron may not run promptly on every host (see
 * cron/subscription-cron.php's InfinityFree note), this also opportunistically
 * self-heals: if it finds a subscription that lapsed but is still marked
 * 'active' (cron hasn't caught up yet), it expires it right now, inline,
 * via the same code path the cron job uses — so the email/notification/
 * history side-effects still fire correctly and access drops immediately
 * instead of staying "premium" until the next cron run.
 *
 * Cached per-request (static) since this may be called many times rendering
 * a single page (nav badge, lesson lock, AI rate limit, etc.).
 */
function userHasActivePremium(int $userId): bool
{
    static $cache = [];
    if (array_key_exists($userId, $cache)) return $cache[$userId];

    $db = getDB();

    $stale = $db->prepare("
        SELECT us.id, us.user_id, pp.name AS plan_name
        FROM user_subscriptions us
        INNER JOIN premium_plans pp ON pp.id = us.plan_id
        WHERE us.user_id = ? AND us.status = 'active'
          AND us.expires_at IS NOT NULL AND us.expires_at <= NOW()
    ");
    $stale->execute([$userId]);
    foreach ($stale->fetchAll() as $sub) {
        expireSubscription($sub);
    }

    $stmt = $db->prepare("
        SELECT COUNT(*) FROM user_subscriptions
        WHERE user_id = ? AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW())
    ");
    $stmt->execute([$userId]);
    return $cache[$userId] = (int)$stmt->fetchColumn() > 0;
}

/**
 * All the side-effects of a subscription lapsing: flips status, syncs the
 * cached flag, sends the expiry email + in-app notification + admin alert,
 * and writes a history entry. Extracted so the cron sweep
 * (checkAndExpireSubscriptions) and the per-user opportunistic self-heal
 * (userHasActivePremium) share one implementation instead of two.
 *
 * $sub needs at minimum ['id' => subscription id, 'user_id' => ..., optionally 'plan_name' => ...]
 */
function expireSubscription(array $sub): void
{
    $db = getDB();

    // Guard: only act if still active (avoids double-processing / double-emailing
    // if this is somehow called twice for the same row in one request).
    $changed = $db->prepare("UPDATE user_subscriptions SET status='expired' WHERE id=? AND status='active'");
    $changed->execute([$sub['id']]);
    if ($changed->rowCount() === 0) return;

    syncMembershipCache((int)$sub['user_id']);

    $userStmt = $db->prepare("SELECT username, email FROM users WHERE id = ?");
    $userStmt->execute([$sub['user_id']]);
    $userInfo = $userStmt->fetch();

    if ($userInfo) {
        emailUser((int)$sub['user_id'], 'premium_expired');
    }

    notifyUser((int)$sub['user_id'], 'premium_expired', 'Premium access expired', 'Renew anytime to keep learning without limits.', SITE_URL . '/upgrade.php');

    logReminderSent((int)$sub['id'], 'expired');
    logSubscriptionHistory((int)$sub['id'], (int)$sub['user_id'], 'expired', $sub['plan_name'] ?? null);

    adminNotify(
        'subscription_expired',
        'Subscription expired',
        ($userInfo['username'] ?? $userInfo['email'] ?? 'A user') . "'s premium access (" . ($sub['plan_name'] ?? 'plan') . ') has expired.',
        SITE_URL . '/admin/subscriptions.php?action=view&id=' . (int)$sub['id']
    );
}

/** Writes one row to the subscription's audit trail (item 11 in the audit spec). */
function logSubscriptionHistory(
    int $subscriptionId,
    int $userId,
    string $event,
    ?string $planName = null,
    ?string $reference = null,
    ?float $amount = null,
    ?string $note = null
): void {
    // Audit trail only — must never break the page it's called from (e.g. if
    // the subscription-audit-migration.sql hasn't been run yet on this
    // environment, this table may not exist). Fails loud in the error log,
    // silent to the user.
    try {
        getDB()->prepare("
            INSERT INTO subscription_history (subscription_id, user_id, event, plan_name, reference, amount, note)
            VALUES (?,?,?,?,?,?,?)
        ")->execute([$subscriptionId, $userId, $event, $planName, $reference, $amount, $note]);
    } catch (Throwable $e) {
        error_log('[Subscriptions] logSubscriptionHistory failed (is subscription-audit-migration.sql applied?): ' . $e->getMessage());
    }
}

/** Full audit trail for a subscription, newest first. */
function getSubscriptionHistory(int $subscriptionId): array
{
    $stmt = getDB()->prepare("SELECT * FROM subscription_history WHERE subscription_id = ? ORDER BY created_at DESC");
    $stmt->execute([$subscriptionId]);
    return $stmt->fetchAll();
}

/** Drops an alert into the admin notification feed (item 10 in the audit spec). */
// adminNotify() (formerly notifySubscriptionAdmins(), same signature/body)
// now lives in includes/notifications/NotificationService.php, loaded
// globally by includes/config.php — both call sites above were renamed
// to match, nothing else changed.

/** User-initiated cancellation — access continues until expires_at, then lapses (no refund logic here). */
function cancelSubscription(int $userId): bool
{
    $sub = getUserSubscription($userId);
    if (!$sub || $sub['status'] !== 'active') return false;

    if (!empty($sub['provider_subscription_code'])) {
        getPaymentProvider()->cancelSubscription($sub['provider_subscription_code']);
    }

    getDB()->prepare("UPDATE user_subscriptions SET cancel_at_period_end=1, cancelled_at=NOW(), renews_at=NULL WHERE id=?")
           ->execute([$sub['id']]);
    logSubscriptionHistory((int)$sub['id'], $userId, 'cancelled', $sub['plan_name'] ?? null, null, null, 'Cancelled by user — access continues until period end');

    Analytics::track('subscription_cancelled', $userId, ['plan_name' => $sub['plan_name'] ?? null, 'initiated_by' => 'user']);

    $__userInfo = getCurrentUser();
    notifyAdmins('premium_cancelled_admin', [
        'username'  => $__userInfo['username'] ?? $__userInfo['email'] ?? 'A user',
        'email'     => $__userInfo['email'] ?? '',
        'plan_name' => $sub['plan_name'] ?? '',
        'link'      => SITE_URL . '/admin/subscriptions.php?action=view&id=' . $sub['id'],
    ]);

    emailUser($userId, 'general_notification', [
        'subject'  => 'Your subscription was cancelled',
        'message'  => 'Your plan will not renew. You keep full access until it expires on ' .
                      ($sub['expires_at'] ? date('F j, Y', strtotime($sub['expires_at'])) : 'the end of the current period') . '.',
    ]);

    notifyUser($userId, 'subscription_cancelled', 'Subscription cancelled', 'Your plan will not renew. You keep access until it expires.', SITE_URL . '/dashboard.php');

    return true;
}

/**
 * Cron entry point: expires lapsed subscriptions and flips their user's
 * cached membership flag back to free. Safe to run repeatedly (idempotent).
 * Shares its per-subscription logic with the opportunistic self-heal in
 * userHasActivePremium() via expireSubscription() — see that function's
 * docblock for why both paths exist.
 */
function checkAndExpireSubscriptions(): int
{
    $expired = getDB()->query("
        SELECT us.id, us.user_id, pp.name AS plan_name
        FROM user_subscriptions us
        INNER JOIN premium_plans pp ON pp.id = us.plan_id
        WHERE us.status = 'active' AND us.expires_at IS NOT NULL AND us.expires_at <= NOW()
    ")->fetchAll();

    foreach ($expired as $sub) {
        expireSubscription($sub);
    }
    return count($expired);
}

/**
 * Cron entry point: sends expiry reminders on a schedule that depends on the
 * plan's billing cycle (per spec):
 *   - Yearly:  30 days, 7 days, and 1 day before expiry
 *   - Monthly: 7 days and 1 day before expiry
 * Reuses the 'premium_expiring' email template with a {{days_remaining}}
 * variable (avoids near-duplicate templates per window). subscription_reminder_log
 * dedupes so re-running this (e.g. twice in one day) never double-sends.
 */
function sendExpiryReminders(): int
{
    $db = getDB();
    $windows = [
        'monthly' => ['7_day' => 7, '1_day' => 1],
        'yearly'  => ['30_day' => 30, '7_day' => 7, '1_day' => 1],
    ];
    $sent = 0;

    foreach ($windows as $billingCycle => $cycleWindows) {
        foreach ($cycleWindows as $reminderType => $daysBefore) {
            $stmt = $db->prepare("
                SELECT us.id, us.user_id, us.expires_at, u.email, u.username, pp.name AS plan_name
                FROM user_subscriptions us
                INNER JOIN users u ON u.id = us.user_id
                INNER JOIN premium_plans pp ON pp.id = us.plan_id
                WHERE us.status = 'active'
                  AND pp.billing_cycle = ?
                  AND us.expires_at IS NOT NULL
                  AND DATE(us.expires_at) = DATE(DATE_ADD(NOW(), INTERVAL ? DAY))
                  AND NOT EXISTS (
                      SELECT 1 FROM subscription_reminder_log
                      WHERE subscription_id = us.id AND reminder_type = ?
                  )
            ");
            $stmt->execute([$billingCycle, $daysBefore, $reminderType]);
            $due = $stmt->fetchAll();

            foreach ($due as $row) {
                deliverEmailTemplate('premium_expiring', $row['email'], [
                    'username'       => $row['username'],
                    'plan_name'      => $row['plan_name'],
                    'days_remaining' => $daysBefore,
                    'expiry_date'    => date('F j, Y', strtotime($row['expires_at'])),
                ]);
                notifyUser(
                    (int)$row['user_id'], 'premium_expiring', 'Premium expiring soon',
                    "Your premium access expires in {$daysBefore} day" . ($daysBefore === 1 ? '' : 's') . '.',
                    SITE_URL . '/dashboard.php'
                );
                logReminderSent((int)$row['id'], $reminderType);
                $sent++;
            }
        }
    }
    return $sent;
}

function logReminderSent(int $subscriptionId, string $type): void
{
    getDB()->prepare("INSERT IGNORE INTO subscription_reminder_log (subscription_id, reminder_type) VALUES (?, ?)")
           ->execute([$subscriptionId, $type]);
}

// ============================================
// ADMIN SUBSCRIPTION MANAGEMENT
// (used by admin/subscriptions.php)
// ============================================

/** One subscription row with user + plan details joined in, or null. */
function getSubscriptionById(int $subscriptionId): ?array
{
    $stmt = getDB()->prepare("
        SELECT us.*, pp.name AS plan_name, pp.billing_cycle, pp.price_kobo,
               u.username, u.email
        FROM user_subscriptions us
        INNER JOIN premium_plans pp ON pp.id = us.plan_id
        INNER JOIN users u ON u.id = us.user_id
        WHERE us.id = ?
    ");
    $stmt->execute([$subscriptionId]);
    return $stmt->fetch() ?: null;
}

/** All purchase/payment rows tied to a subscription, newest first. */
function getSubscriptionPayments(int $subscriptionId): array
{
    $stmt = getDB()->prepare("SELECT * FROM purchases WHERE subscription_id = ? ORDER BY created_at DESC");
    $stmt->execute([$subscriptionId]);
    return $stmt->fetchAll();
}

/** Pushes a subscription's expiry forward by N days (grants extra access). Reactivates if it had expired. */
function adminExtendSubscription(int $subscriptionId, int $days): bool
{
    if ($days <= 0) return false;
    $sub = getSubscriptionById($subscriptionId);
    if (!$sub) return false;

    $base = $sub['expires_at'] ? new DateTime($sub['expires_at']) : new DateTime();
    // If it already lapsed, extend from today rather than compounding onto a past date.
    if ($sub['expires_at'] && strtotime($sub['expires_at']) < time()) {
        $base = new DateTime();
    }
    $base->modify("+{$days} days");
    $newExpiry = $base->format('Y-m-d H:i:s');

    getDB()->prepare("UPDATE user_subscriptions SET expires_at=?, status='active' WHERE id=?")
           ->execute([$newExpiry, $subscriptionId]);
    syncMembershipCache((int)$sub['user_id']);
    logSubscriptionHistory($subscriptionId, (int)$sub['user_id'], 'extended', $sub['plan_name'], null, null, "Extended by {$days} day(s) by admin — new expiry " . date('M j, Y', strtotime($newExpiry)));
    return true;
}

/** Pulls a subscription's expiry back by N days (reduces remaining access). Expires it immediately if that pushes it into the past. */
function adminReduceSubscription(int $subscriptionId, int $days): bool
{
    if ($days <= 0) return false;
    $sub = getSubscriptionById($subscriptionId);
    if (!$sub || !$sub['expires_at']) return false; // can't reduce a permanent (never-expiring) grant

    $base = new DateTime($sub['expires_at']);
    $base->modify("-{$days} days");
    $newExpiry = $base->format('Y-m-d H:i:s');

    getDB()->prepare("UPDATE user_subscriptions SET expires_at=? WHERE id=?")->execute([$newExpiry, $subscriptionId]);
    logSubscriptionHistory($subscriptionId, (int)$sub['user_id'], 'reduced', $sub['plan_name'], null, null, "Reduced by {$days} day(s) by admin — new expiry " . date('M j, Y', strtotime($newExpiry)));

    if (strtotime($newExpiry) <= time() && $sub['status'] === 'active') {
        expireSubscription(['id' => $subscriptionId, 'user_id' => $sub['user_id'], 'plan_name' => $sub['plan_name']]);
    } else {
        syncMembershipCache((int)$sub['user_id']);
    }
    return true;
}

/**
 * Admin-initiated cancellation — unlike cancelSubscription() (self-serve,
 * which keeps access until period end), this takes effect immediately.
 */
function adminCancelSubscription(int $subscriptionId): bool
{
    $sub = getSubscriptionById($subscriptionId);
    if (!$sub) return false;

    if (!empty($sub['provider_subscription_code'])) {
        getPaymentProvider()->cancelSubscription($sub['provider_subscription_code']);
    }

    getDB()->prepare("UPDATE user_subscriptions SET status='cancelled', cancelled_at=NOW(), cancel_at_period_end=0, renews_at=NULL WHERE id=?")
           ->execute([$subscriptionId]);
    syncMembershipCache((int)$sub['user_id']);
    logSubscriptionHistory($subscriptionId, (int)$sub['user_id'], 'cancelled', $sub['plan_name'], null, null, 'Cancelled by admin — access revoked immediately');

    Analytics::track('subscription_cancelled', (int)$sub['user_id'], ['plan_name' => $sub['plan_name'] ?? null, 'initiated_by' => 'admin']);

    emailUser((int)$sub['user_id'], 'general_notification', [
        'subject'  => 'Your subscription was cancelled',
        'message'  => 'Your premium subscription was cancelled by an administrator. Contact support if you believe this was a mistake.',
    ]);
    return true;
}

/** Reactivates a cancelled/expired subscription with a fresh billing period starting today. */
function adminReactivateSubscription(int $subscriptionId): bool
{
    $sub = getSubscriptionById($subscriptionId);
    if (!$sub) return false;

    $newExpiry = calculateExpiry($sub['billing_cycle']);
    getDB()->prepare("UPDATE user_subscriptions SET status='active', expires_at=?, renews_at=?, cancelled_at=NULL, cancel_at_period_end=0 WHERE id=?")
           ->execute([$newExpiry, $newExpiry, $subscriptionId]);
    syncMembershipCache((int)$sub['user_id']);
    logSubscriptionHistory($subscriptionId, (int)$sub['user_id'], 'reactivated', $sub['plan_name'], null, null, 'Reactivated by admin — one billing cycle granted from today');

    emailUser((int)$sub['user_id'], 'general_notification', [
        'subject'  => 'Your Web3Edu Premium access is back',
        'message'  => 'An administrator reactivated your premium subscription. It now renews ' . date('F j, Y', strtotime($newExpiry)) . '.',
    ]);
    return true;
}

/**
 * The hidden, non-purchasable plan used to back admin-granted premium (see
 * admin/users.php's Grant/Remove Premium action). is_active=0 keeps it off
 * getActivePlans()/getPlanBySlug() (the public pricing page), but
 * getPlanById() and this function can still reach it directly.
 */
function getAdminGrantPlan(): array
{
    $db = getDB();
    $plan = $db->query("SELECT * FROM premium_plans WHERE slug = 'admin-granted'")->fetch();
    if ($plan) return $plan;

    // Safety net in case the migration hasn't been run yet — creates it on first use.
    $db->exec("
        INSERT INTO premium_plans (name, slug, billing_cycle, price_kobo, description, is_active, sort_order)
        VALUES ('Admin Granted', 'admin-granted', 'yearly', 0, 'Manually granted by an administrator.', 0, 999)
    ");
    return $db->query("SELECT * FROM premium_plans WHERE slug = 'admin-granted'")->fetch();
}

/**
 * Grants a user permanent premium access (expires_at = NULL) backed by a
 * real user_subscriptions row, instead of just flipping the users.membership_type
 * flag with nothing behind it. Used by admin/users.php's "Grant Premium" action.
 */
function grantAdminPremium(int $userId): int
{
    $db = getDB();
    $plan = getAdminGrantPlan();
    $existing = getUserSubscription($userId);
    $now = date('Y-m-d H:i:s');

    if ($existing && in_array($existing['status'], ['active', 'pending', 'expired', 'cancelled'], true)) {
        $db->prepare("
            UPDATE user_subscriptions
            SET plan_id=?, status='active', provider='admin', started_at=COALESCE(started_at, ?),
                expires_at=NULL, renews_at=NULL, cancel_at_period_end=0, cancelled_at=NULL
            WHERE id=?
        ")->execute([$plan['id'], $now, $existing['id']]);
        $subscriptionId = (int)$existing['id'];
    } else {
        $db->prepare("
            INSERT INTO user_subscriptions (user_id, plan_id, status, provider, started_at, expires_at)
            VALUES (?, ?, 'active', 'admin', ?, NULL)
        ")->execute([$userId, $plan['id'], $now]);
        $subscriptionId = (int)$db->lastInsertId();
    }

    syncMembershipCache($userId);
    logSubscriptionHistory($subscriptionId, $userId, 'created', $plan['name'], null, null, 'Granted permanent access by admin (never expires)');
    return $subscriptionId;
}

/** Revokes admin-granted (or any) premium access immediately. Used by admin/users.php's "Remove Premium" action. */
function revokeAdminPremium(int $userId): bool
{
    $sub = getUserSubscription($userId);
    if (!$sub || $sub['status'] !== 'active') return false;

    getDB()->prepare("UPDATE user_subscriptions SET status='cancelled', cancelled_at=NOW(), renews_at=NULL WHERE id=?")
           ->execute([$sub['id']]);
    syncMembershipCache($userId);
    logSubscriptionHistory((int)$sub['id'], $userId, 'cancelled', $sub['plan_name'] ?? null, null, null, 'Removed by admin');
    return true;
}
