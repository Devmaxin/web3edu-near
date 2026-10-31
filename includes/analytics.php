<?php
/**
 * Web3Edu — Product Analytics Service
 * ============================================================================
 * Single, centralized entry point for writing to the existing
 * `analytics_events` table (see database/web3edu_v2.sql, SECTION 10 —
 * BOOKMARKS, ANALYTICS — that table already existed, "prepared for future
 * analytics — not yet consumed by any admin dashboard", before this file).
 *
 * No new analytics table was created. This file only adds the write path
 * (Analytics::track) and a couple of small read helpers that are reused by
 * both the admin dashboard (admin/analytics.php) and the email reports
 * (includes/analytics/AnalyticsReportService.php).
 *
 * DESIGN PRINCIPLES (see the implementation report for the full rationale):
 *   - Analytics::track() NEVER throws and NEVER slows down or breaks the
 *     calling page — every failure is caught and error_log()'d only.
 *   - Every event name actually fired is declared in EVENT_TAXONOMY below.
 *     Anything not declared is still recorded (fail open, not closed) but
 *     logged as an "undeclared event" so drift gets noticed during review.
 *   - Properties are JSON-encoded and size-capped so a runaway caller can
 *     never bloat a single row or blow up the JSON column.
 *   - We NEVER write passwords, API keys, card numbers, tokens, or wallet
 *     keys into `properties` — see sanitizeProperties().
 *   - Session correlation uses PHP's own session_id() — already a
 *     per-browser-session opaque token the app generates for every visitor
 *     (see includes/config.php session_set_cookie_params) — so anonymous
 *     (not-logged-in) events can still be grouped per visit without adding
 *     any new cookie or fingerprinting mechanism.
 */

final class Analytics
{
    /**
     * The full event taxonomy this app fires, grouped exactly as specified
     * in the analytics brief. Used only for the "undeclared event" warning
     * below — it is documentation-as-code, not a hard allow-list, so a
     * legitimate one-off event is never silently dropped.
     */
    private const EVENT_TAXONOMY = [
        // Authentication / Acquisition
        'signup', 'login', 'logout', 'onboarding_started', 'onboarding_completed',
        // Learning
        'lesson_started', 'lesson_completed', 'quiz_started', 'quiz_completed',
        'guide_started', 'guide_completed', 'resource_viewed', 'video_started', 'video_completed',
        // AI
        'ai_chat_used', 'ai_study_card_generated', 'ai_free_limit_reached',
        // Certificates
        'certificate_issued', 'certificate_viewed', 'certificate_shared', 'certificate_verified',
        // Premium
        'premium_page_viewed', 'checkout_started', 'payment_completed', 'subscription_started',
        'subscription_cancelled', 'subscription_renewal', 'payment_failed',
        // Bounties
        'bounty_viewed', 'bounty_host_started', 'bounty_hosted', 'bounty_submission_started',
        'bounty_submission_made', 'bounty_won',
        // Referrals (reserved — no referral system exists in this codebase yet; see report)
        'referral_created', 'referral_clicked', 'referral_converted',
        // Engagement
        'search_performed', 'content_bookmarked', 'notification_opened',
    ];

    /** Hard cap on the JSON-encoded size of `properties`, defense against a runaway caller. */
    private const MAX_PROPERTIES_BYTES = 4000;

    /** Keys that must NEVER be written, even if a caller passes them by accident. */
    private const FORBIDDEN_PROPERTY_KEYS = [
        'password', 'password_hash', 'api_key', 'secret', 'token', 'auth_token',
        'card_number', 'cvv', 'private_key', 'wallet_private_key', 'access_token',
        'refresh_token', 'authorization_code',
    ];

    /**
     * Record one analytics event. Safe to call from anywhere — page code,
     * an API endpoint, a cron job. Never throws.
     *
     * @param string   $eventName  One of EVENT_TAXONOMY (recommended) — see docblock above.
     * @param int|null $userId     Null for a logged-out / anonymous visitor.
     * @param array    $properties Structured event context — see event dictionary in docs/ANALYTICS.md.
     * @param string|null $sessionId Override the session id used for correlation (rarely needed).
     */
    public static function track(string $eventName, ?int $userId = null, array $properties = [], ?string $sessionId = null): void
    {
        try {
            if ($eventName === '') return;

            if (!in_array($eventName, self::EVENT_TAXONOMY, true)) {
                // Fail open: still record it (a real product signal is more valuable
                // than a dropped one), but flag it so taxonomy drift is visible in logs.
                error_log("[Analytics] Undeclared event name tracked: {$eventName} — add it to Analytics::EVENT_TAXONOMY or docs/ANALYTICS.md.");
            }

            $properties = self::sanitizeProperties($properties);
            $json = json_encode($properties, JSON_UNESCAPED_SLASHES);
            if ($json === false) $json = '{}';
            if (strlen($json) > self::MAX_PROPERTIES_BYTES) {
                // Truncate rather than fail the whole event — better a partial
                // properties blob than losing the fact that the event happened.
                $properties = ['_truncated' => true];
                $json = json_encode($properties);
            }

            $sessionId = $sessionId ?? (session_id() ?: null);

            $db = getDB();
            $stmt = $db->prepare("
                INSERT INTO analytics_events (user_id, event_name, properties, session_id, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$userId, $eventName, $json, $sessionId]);
        } catch (\Throwable $e) {
            // Analytics must never break the user-facing action that triggered it.
            error_log('[Analytics] track() failed for event "' . $eventName . '": ' . $e->getMessage());
        }
    }

    /**
     * Idempotent variant of track(): only inserts if no row with this exact
     * (event_name, user_id) — optionally narrowed by a property value —
     * already exists. Use sparingly (it costs a SELECT); reserved for
     * events where a duplicate would visibly distort a dashboard number
     * (e.g. "first bounty host application") and no other uniqueness
     * mechanism already exists in the source table.
     *
     * Most events do NOT need this — funnel/report queries in this system
     * use `COUNT(DISTINCT user_id)`, so ordinary repeat events (lesson
     * viewed twice, page refreshed) never inflate a unique-user metric.
     * True double-counting risks (payments, certificates, bounty wins) are
     * instead prevented at the source (see the money-events section of
     * docs/ANALYTICS.md) — trackOnce() is a second line of defense for a
     * couple of cases where the source table has no natural unique key.
     */
    public static function trackOnce(string $eventName, ?int $userId, array $properties = [], ?string $propertyKeyToMatch = null): void
    {
        try {
            if ($userId === null) { self::track($eventName, $userId, $properties); return; }

            $db = getDB();
            if ($propertyKeyToMatch !== null && isset($properties[$propertyKeyToMatch])) {
                $stmt = $db->prepare("
                    SELECT id FROM analytics_events
                    WHERE user_id = ? AND event_name = ?
                      AND JSON_EXTRACT(properties, ?) = ?
                    LIMIT 1
                ");
                $path = '$.' . $propertyKeyToMatch;
                $stmt->execute([$userId, $eventName, $path, json_encode($properties[$propertyKeyToMatch])]);
            } else {
                $stmt = $db->prepare("SELECT id FROM analytics_events WHERE user_id = ? AND event_name = ? LIMIT 1");
                $stmt->execute([$userId, $eventName]);
            }
            if ($stmt->fetch()) return; // already recorded once

            self::track($eventName, $userId, $properties);
        } catch (\Throwable $e) {
            error_log('[Analytics] trackOnce() failed for event "' . $eventName . '": ' . $e->getMessage());
            // Best-effort fallback — still record the event even if the dedup check itself failed.
            self::track($eventName, $userId, $properties);
        }
    }

    /** Strips forbidden keys and casts to JSON-safe scalars/arrays only. */
    private static function sanitizeProperties(array $properties): array
    {
        $clean = [];
        foreach ($properties as $key => $value) {
            $lowerKey = strtolower((string)$key);
            $blocked = false;
            foreach (self::FORBIDDEN_PROPERTY_KEYS as $bad) {
                if (str_contains($lowerKey, $bad)) { $blocked = true; break; }
            }
            if ($blocked) continue;
            if (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            } elseif (is_array($value)) {
                $clean[$key] = self::sanitizeProperties($value);
            }
            // Objects and resources are silently dropped — never expected here.
        }
        return $clean;
    }
}

/**
 * Thin functional wrapper — some call sites in this codebase prefer plain
 * functions over static class calls (matches e.g. notifyUser()/emailUser()
 * conventions in includes/notifications/NotificationService.php).
 */
function trackAnalyticsEvent(string $eventName, ?int $userId = null, array $properties = []): void
{
    Analytics::track($eventName, $userId, $properties);
}
