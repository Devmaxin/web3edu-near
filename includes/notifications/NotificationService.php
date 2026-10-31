<?php
/**
 * NotificationService — the single entry point for every notification the
 * platform sends: in-app (notifications / admin_notifications tables) and
 * email (via includes/email/EmailService.php, unchanged).
 *
 * This does NOT replace any existing system. It centralizes the three
 * shorthand functions that used to live only in includes/bounties.php
 * (notifyUser, emailUser, notifyAdmins) so every module — subscriptions,
 * gamification, certificates, projects, auth, bounties — calls the same
 * code instead of re-implementing "insert into notifications" +
 * "deliverEmailTemplate()" inline. Signatures are unchanged from the
 * original bounties.php versions, so every existing call site keeps
 * working exactly as before.
 *
 * It also owns the Notification Preferences event catalog (Phase 1 of the
 * Email & Notification Center) and the admin-recipient resolution used by
 * notifyAdmins(), so both the admin Settings UI and the email pipeline
 * read from the same source of truth.
 */

// ============================================================
// EVENT CATALOG — powers the admin Notification Preferences UI
// (admin/settings.php) and the email enable/disable gate below.
//
// 'keys' lists every email template_key that maps to this event — when
// any of those templates is sent via deliverEmailTemplate(), it is gated
// by this event's on/off setting. 'wired' = false means no code path
// currently triggers this event yet (shown in the UI as reserved for a
// future phase, per the communication-system audit).
// ============================================================
function notificationEventCatalog(): array
{
    return [
        'bounty' => [
            'label' => 'Bounty Marketplace',
            'events' => [
                'new_host_profile'             => ['label' => 'New Host Profile',              'keys' => ['host_profile_submitted_admin', 'host_profile_resubmitted_admin'], 'admin' => true],
                'host_profile_approved'        => ['label' => 'Host Profile Approved',          'keys' => ['host_profile_approved']],
                'host_profile_rejected'        => ['label' => 'Host Profile Rejected',           'keys' => ['host_profile_rejected']],
                'host_profile_request_changes' => ['label' => 'Host Profile Request Changes',    'keys' => ['host_profile_request_changes']],
                'hosted_bounty_submitted'      => ['label' => 'Hosted Bounty Submitted',         'keys' => ['bounty_submitted_admin'], 'admin' => true],
                'hosted_bounty_approved'       => ['label' => 'Hosted Bounty Approved',          'keys' => ['bounty_approved_host']],
                'hosted_bounty_rejected'       => ['label' => 'Hosted Bounty Rejected',          'keys' => ['bounty_rejected_host']],
                'payment_submitted'            => ['label' => 'Payment Submitted',               'keys' => ['bounty_payment_admin'], 'admin' => true],
                'payment_approved'             => ['label' => 'Payment Approved',                'keys' => ['bounty_payment_approved']],
                'payment_rejected'             => ['label' => 'Payment Rejected',                'keys' => ['bounty_payment_rejected']],
                'winner_selected'              => ['label' => 'Winner Selected',                 'keys' => ['bounty_winner']],
                'new_submission'               => ['label' => 'New Submission',                  'keys' => ['bounty_submission_received']],
            ],
        ],
        'platform' => [
            'label' => 'Platform',
            'events' => [
                'new_user_registration'   => ['label' => 'New User Registration',        'keys' => ['welcome']],
                'premium_purchased'       => ['label' => 'Premium Subscription Purchased', 'keys' => ['premium_purchase']],
                'new_premium_subscription_admin' => ['label' => 'New Premium Subscription (Admin Alert)', 'keys' => ['premium_purchased_admin'], 'admin' => true],
                'premium_cancelled'       => ['label' => 'Premium Subscription Cancelled', 'keys' => ['premium_cancelled_admin'], 'admin' => true],
                'payment_failed'          => ['label' => 'Payment Failure',               'keys' => ['payment_failed_admin'], 'admin' => true],
                'bounty_submission_admin' => ['label' => 'New Bounty Submission (Admin Alert)', 'keys' => ['bounty_submission_admin'], 'admin' => true],
                'bounty_won_admin'        => ['label' => 'Bounty Won (Admin Alert)',      'keys' => ['bounty_won_admin'], 'admin' => true],
                'contact_form'            => ['label' => 'Contact Form',                  'keys' => [], 'wired' => false],
                'system_errors'           => ['label' => 'System Errors',                 'keys' => [], 'wired' => false, 'admin' => true],
                'failed_email_queue'      => ['label' => 'Failed Email Queue',            'keys' => [], 'wired' => false, 'admin' => true],
                'ai_errors'               => ['label' => 'AI Errors',                     'keys' => [], 'wired' => false, 'admin' => true],
            ],
        ],
        'analytics' => [
            'label' => 'Product Analytics Reports',
            'events' => [
                'daily_analytics_report'  => ['label' => 'Daily Analytics Report',        'keys' => ['analytics_daily_report'], 'admin' => true],
                'weekly_analytics_report' => ['label' => 'Weekly Analytics Report',        'keys' => ['analytics_weekly_report'], 'admin' => true],
            ],
        ],
        'retention' => [
            'label' => 'Retention & Comeback',
            'events' => [
                'inactivity_reminders' => ['label' => 'Inactivity Reminder Emails (3/7-day nudges, 14/30-day comeback)', 'keys' => ['inactivity_nudge_3day', 'inactivity_nudge_7day', 'inactivity_comeback_14day', 'inactivity_comeback_30day']],
            ],
        ],
    ];
}

/** template_key => event_key, flattened once from the catalog above. */
function notificationTemplateEventMap(): array
{
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    foreach (notificationEventCatalog() as $category) {
        foreach ($category['events'] as $eventKey => $event) {
            foreach ($event['keys'] as $templateKey) {
                $map[$templateKey] = $eventKey;
            }
        }
    }
    return $map;
}

/** site_settings key an event's email toggle is stored under. */
function notificationPreferenceSettingKey(string $eventKey): string
{
    return 'notif_email_enabled__' . $eventKey;
}

/**
 * Whether emails for a given template_key should currently be sent.
 * Templates not present in the event catalog (password reset, welcome's
 * transactional siblings, certificates, etc.) are never gated here —
 * only the curated business-event list the admin sees toggles in
 * Settings can be turned off. Called from deliverEmailTemplate() itself
 * (includes/email/EmailService.php), so this applies no matter which
 * code path triggers the send.
 */
function isNotificationEmailEnabled(string $templateKey): bool
{
    $eventKey = notificationTemplateEventMap()[$templateKey] ?? null;
    if ($eventKey === null) return true; // not a togglable event — always send
    return getSetting(notificationPreferenceSettingKey($eventKey), '1') !== '0';
}

/**
 * Every address that should receive admin-facing emails: the configured
 * Primary Admin Notification Email (site_settings) plus every admin
 * account with an email on file (admins table) — deduplicated. Keeping
 * both means setting a primary address doesn't remove multi-admin
 * delivery, and additional recipients can be added later (e.g. a CSV
 * setting or a per-admin "receives notifications" flag) without
 * touching any call site that uses notifyAdmins().
 */
function getAdminNotificationRecipients(): array
{
    $emails = [];
    $primary = trim(getSetting('primary_admin_notification_email', ''));
    if ($primary !== '') $emails[] = $primary;

    try {
        $rows = getDB()->query("SELECT email FROM admins WHERE email IS NOT NULL AND email != ''")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $e) $emails[] = $e;
    } catch (\Throwable $e) {
        error_log('[NotificationService] Failed to load admin emails: ' . $e->getMessage());
    }

    return array_values(array_unique(array_filter($emails)));
}

/**
 * In-app notification for one user. Called as a post-save side effect —
 * never throws; a DB hiccup here must not turn an already-successful
 * action into a fatal error for the user.
 */
function notifyUser(int $userId, string $type, string $title, string $body, ?string $link = null): void
{
    try {
        getDB()->prepare("INSERT INTO notifications (user_id, type, title, body, link) VALUES (?,?,?,?,?)")
               ->execute([$userId, $type, $title, $body, $link]);
    } catch (\Throwable $e) {
        error_log("[Notify] Failed to notify user #{$userId} ({$type}): " . $e->getMessage());
    }
}

/**
 * Emails one user via the existing template pipeline. Gated by
 * Notification Preferences through deliverEmailTemplate() itself, so
 * no duplicate check is needed here.
 */
function emailUser(int $userId, string $templateKey, array $vars = [], bool $immediate = false): void
{
    try {
        $stmt = getDB()->prepare("SELECT username, email FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        if ($u = $stmt->fetch()) {
            deliverEmailTemplate($templateKey, $u['email'], array_merge(['username' => $u['username'], 'site_url' => SITE_URL], $vars), $immediate);
        }
    } catch (\Throwable $e) {
        error_log("[Notify] Failed to email user #{$userId} ({$templateKey}): " . $e->getMessage());
    }
}

/**
 * Notifies admins of a platform event: always logs an in-app row to
 * admin_notifications (so the admin bell/list always shows it — the
 * Notification Preferences toggle controls the *email* only), and
 * emails every address from getAdminNotificationRecipients() using the
 * existing template + queue, subject to that toggle.
 *
 * $inAppTitle/$inAppBody/$link let a caller supply nicer in-app copy;
 * when omitted, they're derived from the email template itself so this
 * stays a drop-in call — existing notifyAdmins() call sites in
 * bounties.php needed no changes to gain the in-app row.
 */
function notifyAdmins(string $templateKey, array $vars = [], ?string $inAppTitle = null, ?string $inAppBody = null, ?string $link = null): void
{
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM email_templates WHERE template_key = ?");
        $stmt->execute([$templateKey]);
        $template = $stmt->fetch();

        $renderedVars = array_merge(['site_url' => SITE_URL], $vars);
        $title = $inAppTitle ?? ($template ? renderTemplateString($template['subject'], $renderedVars) : ucfirst(str_replace('_', ' ', $templateKey)));
        $body  = $inAppBody  ?? ($template ? trim(strip_tags(renderTemplateString($template['html_body'], $renderedVars))) : null);
        $link  = $link ?? ($renderedVars['site_url'] ?? SITE_URL);

        $db->prepare("INSERT INTO admin_notifications (type, title, body, link) VALUES (?,?,?,?)")
           ->execute([$templateKey, $title, $body, $link]);
    } catch (\Throwable $e) {
        error_log("[Notify] Failed to log admin_notifications ({$templateKey}): " . $e->getMessage());
    }

    try {
        foreach (getAdminNotificationRecipients() as $email) {
            deliverEmailTemplate($templateKey, $email, array_merge(['site_url' => SITE_URL], $vars));
        }
    } catch (\Throwable $e) {
        error_log("[Notify] Failed to email admins ({$templateKey}): " . $e->getMessage());
    }
}

/**
 * Pure in-app admin alert with no email template involved — for future
 * system-level events (system errors, AI errors, failed email queue)
 * once those phases are wired up. Not yet called anywhere; included now
 * so that work doesn't need a fourth notification helper invented later.
 */
function adminNotify(string $type, string $title, string $body, ?string $link = null): void
{
    try {
        getDB()->prepare("INSERT INTO admin_notifications (type, title, body, link) VALUES (?,?,?,?)")
               ->execute([$type, $title, $body, $link]);
    } catch (\Throwable $e) {
        error_log("[Notify] Failed to log admin_notifications ({$type}): " . $e->getMessage());
    }
}
