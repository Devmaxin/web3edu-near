<?php
/**
 * Web3Edu — Analytics Query Layer
 * ============================================================================
 * Every aggregate SQL query behind the admin Analytics dashboard
 * (admin/analytics.php) and the daily/weekly email reports
 * (includes/analytics/AnalyticsReportService.php) lives here, so both
 * surfaces always compute a given metric the same way for the same period —
 * there is exactly one definition of "MRR" or "D7 retention" in the codebase.
 *
 * TIMEZONE — READ THIS FIRST
 * Web3Edu operates primarily in Nigeria (Africa/Lagos, UTC+1, no DST).
 * Every timestamp column in this schema (`created_at` etc.) is written via
 * MySQL's CURRENT_TIMESTAMP/NOW(), which — like everywhere else in this
 * codebase — is never explicitly set to a timezone, so it reflects
 * whatever timezone the DB server itself is configured to (on most shared
 * hosts, including InfinityFree, this is UTC). This file assumes stored
 * timestamps are UTC and converts to Africa/Lagos (a fixed +01:00 offset,
 * so no mysql.time_zone_name tables need to be loaded — those are often
 * missing on shared hosting) ONLY where a calendar-day boundary matters
 * (e.g. "today", daily report bucketing). Range *filters* compare against
 * UTC-equivalent bounds directly (fast, index-friendly) rather than
 * wrapping every row in CONVERT_TZ.
 *
 * VERIFY THIS: if your DB server's own timezone is not actually UTC, every
 * "today" boundary in this file will be off by exactly that offset. Run
 * `SELECT @@global.time_zone, @@session.time_zone, NOW();` against your DB
 * and compare to real UTC time to confirm. See docs/ANALYTICS.md.
 *
 * MONEY QUERIES use `purchases.status = 'success'` and/or
 * `user_subscriptions.status = 'active'` — never raw payment attempts —
 * so a failed or pending charge never counts as revenue.
 */

const ANALYTICS_TZ_OFFSET = '+01:00'; // Africa/Lagos

/** Resolves a dashboard/report date-range preset into concrete UTC bounds, Lagos-calendar-aware. */
function analyticsResolveRange(string $preset, ?string $customFrom = null, ?string $customTo = null): array
{
    $lagos = new DateTimeZone('Africa/Lagos');
    $utc   = new DateTimeZone('UTC');
    $nowLagos = new DateTime('now', $lagos);

    $startOfDay = function (DateTime $d): DateTime { $c = clone $d; $c->setTime(0, 0, 0); return $c; };
    $endOfDay   = function (DateTime $d): DateTime { $c = clone $d; $c->setTime(23, 59, 59); return $c; };

    switch ($preset) {
        case 'today':
            $from = $startOfDay($nowLagos); $to = $endOfDay($nowLagos);
            $prevFrom = (clone $from)->modify('-1 day'); $prevTo = (clone $to)->modify('-1 day');
            $label = 'Today';
            break;
        case 'yesterday':
            $y = (clone $nowLagos)->modify('-1 day');
            $from = $startOfDay($y); $to = $endOfDay($y);
            $prevFrom = (clone $from)->modify('-1 day'); $prevTo = (clone $to)->modify('-1 day');
            $label = 'Yesterday';
            break;
        case 'last7':
            $from = $startOfDay((clone $nowLagos)->modify('-6 days')); $to = $endOfDay($nowLagos);
            $prevFrom = (clone $from)->modify('-7 days'); $prevTo = (clone $to)->modify('-7 days');
            $label = 'Last 7 Days';
            break;
        case 'this_month':
            $from = $startOfDay(new DateTime($nowLagos->format('Y-m-01'), $lagos)); $to = $endOfDay($nowLagos);
            $prevFrom = $startOfDay((clone $from)->modify('-1 month')); $prevTo = $endOfDay((clone $from)->modify('-1 day'));
            $label = 'This Month';
            break;
        case 'last_month':
            $firstOfThis = new DateTime($nowLagos->format('Y-m-01'), $lagos);
            $from = $startOfDay((clone $firstOfThis)->modify('-1 month'));
            $to   = $endOfDay((clone $firstOfThis)->modify('-1 day'));
            $prevFrom = $startOfDay((clone $from)->modify('-1 month')); $prevTo = $endOfDay((clone $from)->modify('-1 day'));
            $label = 'Last Month';
            break;
        case 'this_year':
            $from = $startOfDay(new DateTime($nowLagos->format('Y-01-01'), $lagos)); $to = $endOfDay($nowLagos);
            $prevFrom = $startOfDay((clone $from)->modify('-1 year')); $prevTo = $endOfDay((clone $to)->modify('-1 year'));
            $label = 'This Year';
            break;
        case 'custom':
            $from = $startOfDay(new DateTime($customFrom ?: 'now', $lagos));
            $to   = $endOfDay(new DateTime($customTo ?: 'now', $lagos));
            $days = max(1, (int)$from->diff($to)->days + 1);
            $prevFrom = (clone $from)->modify("-{$days} days"); $prevTo = (clone $to)->modify("-{$days} days");
            $label = $from->format('M j, Y') . ' – ' . $to->format('M j, Y');
            break;
        case 'last30':
        default:
            $preset = 'last30';
            $from = $startOfDay((clone $nowLagos)->modify('-29 days')); $to = $endOfDay($nowLagos);
            $prevFrom = (clone $from)->modify('-30 days'); $prevTo = (clone $to)->modify('-30 days');
            $label = 'Last 30 Days';
            break;
    }

    $toUtc = fn(DateTime $d) => (clone $d)->setTimezone($utc)->format('Y-m-d H:i:s');

    return [
        'preset' => $preset, 'label' => $label,
        'from' => $toUtc($from), 'to' => $toUtc($to),
        'prev_from' => $toUtc($prevFrom), 'prev_to' => $toUtc($prevTo),
        'from_lagos' => $from->format('Y-m-d'), 'to_lagos' => $to->format('Y-m-d'),
    ];
}

/** Percentage change, safe against a zero/absent previous value. Returns null when not meaningful. */
function analyticsPctChange($current, $previous): ?float
{
    $current = (float)$current; $previous = (float)$previous;
    if ($previous == 0.0) return $current > 0 ? null : 0.0; // "new" — no meaningful % base
    return round((($current - $previous) / $previous) * 100, 1);
}

// ============================================================================
// OVERVIEW
// ============================================================================

function analyticsOverview(array $range): array
{
    $db = getDB();
    $q = function (string $sql, array $params = []) use ($db) {
        $stmt = $db->prepare($sql); $stmt->execute($params); return (int)$stmt->fetchColumn();
    };
    $qFloat = function (string $sql, array $params = []) use ($db) {
        $stmt = $db->prepare($sql); $stmt->execute($params); return (float)$stmt->fetchColumn();
    };

    return [
        // Lifetime totals — never confused with period metrics in the UI (see admin/analytics.php)
        'lifetime' => [
            'total_users'          => $q("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL"),
            'premium_users'        => $q("SELECT COUNT(*) FROM user_subscriptions WHERE status='active' AND (expires_at IS NULL OR expires_at > NOW())"),
            'total_revenue'        => $qFloat("SELECT COALESCE(SUM(amount),0) FROM purchases WHERE status='success'"),
            'lessons_completed'    => $q("SELECT COUNT(*) FROM user_lesson_progress"),
            'guides_completed'     => $q("SELECT COUNT(*) FROM analytics_events WHERE event_name='guide_completed'"),
            'certificates_issued'  => $q("SELECT COUNT(*) FROM certificates WHERE is_revoked=0"),
            'bounties_completed'   => $q("SELECT COUNT(*) FROM bounties WHERE status='winners_announced'"),
        ],
        // Selected-period metrics
        'period' => [
            'new_users'         => $q("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND created_at BETWEEN ? AND ?", [$range['from'], $range['to']]),
            'active_users'      => $q("SELECT COUNT(DISTINCT user_id) FROM analytics_events WHERE user_id IS NOT NULL AND created_at BETWEEN ? AND ?", [$range['from'], $range['to']]),
            'premium_signups'   => $q("SELECT COUNT(*) FROM analytics_events WHERE event_name='subscription_started' AND created_at BETWEEN ? AND ?", [$range['from'], $range['to']]),
            'revenue'           => $qFloat("SELECT COALESCE(SUM(amount),0) FROM purchases WHERE status='success' AND created_at BETWEEN ? AND ?", [$range['from'], $range['to']]),
            'lessons_completed' => $q("SELECT COUNT(*) FROM analytics_events WHERE event_name='lesson_completed' AND created_at BETWEEN ? AND ?", [$range['from'], $range['to']]),
            'guides_completed'  => $q("SELECT COUNT(*) FROM analytics_events WHERE event_name='guide_completed' AND created_at BETWEEN ? AND ?", [$range['from'], $range['to']]),
            'certificates_issued'=> $q("SELECT COUNT(*) FROM certificates WHERE is_revoked=0 AND issued_at BETWEEN ? AND ?", [$range['from'], $range['to']]),
            'bounties_completed'=> $q("SELECT COUNT(*) FROM bounties WHERE status='winners_announced' AND updated_at BETWEEN ? AND ?", [$range['from'], $range['to']]),
        ],
        'mrr' => analyticsMRR(),
    ];
}

// ============================================================================
// USER GROWTH
// ============================================================================

/** Daily new-user counts across the range, Lagos-calendar-bucketed, for the growth chart. */
function analyticsUserGrowthSeries(array $range): array
{
    $db = getDB();
    $stmt = $db->prepare("
        SELECT DATE(CONVERT_TZ(created_at, '+00:00', '" . ANALYTICS_TZ_OFFSET . "')) AS day, COUNT(*) AS n
        FROM users
        WHERE deleted_at IS NULL AND created_at BETWEEN ? AND ?
        GROUP BY day ORDER BY day ASC
    ");
    $stmt->execute([$range['from'], $range['to']]);
    $rows = $stmt->fetchAll();
    return array_map(fn($r) => ['date' => $r['day'], 'count' => (int)$r['n']], $rows);
}

function analyticsUserGrowthComparison(array $range): array
{
    $db = getDB();
    $q = function (string $from, string $to) use ($db) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND created_at BETWEEN ? AND ?");
        $stmt->execute([$from, $to]); return (int)$stmt->fetchColumn();
    };
    $current  = $q($range['from'], $range['to']);
    $previous = $q($range['prev_from'], $range['prev_to']);
    $activeStmt = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM analytics_events WHERE user_id IS NOT NULL AND created_at BETWEEN ? AND ?");
    $activeStmt->execute([$range['from'], $range['to']]);

    return [
        'new_users' => $current, 'new_users_prev' => $previous, 'new_users_change_pct' => analyticsPctChange($current, $previous),
        'active_users' => (int)$activeStmt->fetchColumn(),
        'total_registered' => (int)$db->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetchColumn(),
        'series' => analyticsUserGrowthSeries($range),
    ];
}

// ============================================================================
// ACTIVATION FUNNEL — unique users at each stage, computed independently
// per step (a user only needs to have fired the event at least once in the
// range to count — never double counted even with many repeat events).
// ============================================================================

function analyticsActivationFunnel(array $range): array
{
    $db = getDB();
    $countDistinctUsers = function (string $eventName) use ($db, $range): int {
        $stmt = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM analytics_events WHERE event_name = ? AND user_id IS NOT NULL AND created_at BETWEEN ? AND ?");
        $stmt->execute([$eventName, $range['from'], $range['to']]);
        return (int)$stmt->fetchColumn();
    };

    $steps = [
        ['key' => 'signup',              'label' => 'Signups',                 'count' => $countDistinctUsers('signup')],
        ['key' => 'onboarding_completed','label' => 'Onboarding Completed',     'count' => $countDistinctUsers('onboarding_completed')],
        ['key' => 'lesson_started',      'label' => 'First Lesson Started',     'count' => $countDistinctUsers('lesson_started')],
        ['key' => 'lesson_completed',    'label' => 'First Lesson Completed',   'count' => $countDistinctUsers('lesson_completed')],
        ['key' => 'guide_started',       'label' => 'Guide Started',            'count' => $countDistinctUsers('guide_started')],
        ['key' => 'guide_completed',     'label' => 'Guide Completed',          'count' => $countDistinctUsers('guide_completed')],
        ['key' => 'premium_page_viewed', 'label' => 'Premium Page Viewed',      'count' => $countDistinctUsers('premium_page_viewed')],
        ['key' => 'checkout_started',    'label' => 'Checkout Started',         'count' => $countDistinctUsers('checkout_started')],
        ['key' => 'payment_completed',   'label' => 'Payment Completed',        'count' => $countDistinctUsers('payment_completed')],
    ];

    $base = $steps[0]['count'] ?: 1;
    $prevCount = null;
    foreach ($steps as &$s) {
        $s['pct_of_start'] = round(($s['count'] / $base) * 100, 1);
        $s['pct_of_prev']  = $prevCount === null ? null : ($prevCount > 0 ? round(($s['count'] / $prevCount) * 100, 1) : 0.0);
        $prevCount = $s['count'];
    }
    unset($s);

    return $steps;
}

// ============================================================================
// LEARNING ANALYTICS
// ============================================================================

function analyticsLearningStats(array $range): array
{
    $db = getDB();

    $topLessons = $db->prepare("
        SELECT l.id, l.title,
               (SELECT COUNT(*) FROM analytics_events e WHERE e.event_name='lesson_started' AND JSON_EXTRACT(e.properties,'$.lesson_id')=l.id AND e.created_at BETWEEN ? AND ?) AS started,
               (SELECT COUNT(*) FROM analytics_events e WHERE e.event_name='lesson_completed' AND JSON_EXTRACT(e.properties,'$.lesson_id')=l.id AND e.created_at BETWEEN ? AND ?) AS completed
        FROM lessons l WHERE l.deleted_at IS NULL
        HAVING started > 0
        ORDER BY completed DESC, started DESC
        LIMIT 10
    ");
    $topLessons->execute([$range['from'], $range['to'], $range['from'], $range['to']]);
    $topLessonsRows = $topLessons->fetchAll();
    foreach ($topLessonsRows as &$r) {
        $r['completion_rate'] = $r['started'] > 0 ? round(($r['completed'] / $r['started']) * 100, 1) : 0.0;
    }
    unset($r);

    $topGuides = $db->prepare("
        SELECT g.id, g.title,
               (SELECT COUNT(*) FROM analytics_events e WHERE e.event_name='guide_started' AND JSON_EXTRACT(e.properties,'$.guide_id')=g.id AND e.created_at BETWEEN ? AND ?) AS started,
               (SELECT COUNT(*) FROM analytics_events e WHERE e.event_name='guide_completed' AND JSON_EXTRACT(e.properties,'$.guide_id')=g.id AND e.created_at BETWEEN ? AND ?) AS completed
        FROM guide_paths g WHERE g.deleted_at IS NULL
        HAVING started > 0
        ORDER BY completed DESC, started DESC
        LIMIT 10
    ");
    $topGuides->execute([$range['from'], $range['to'], $range['from'], $range['to']]);
    $topGuidesRows = $topGuides->fetchAll();
    foreach ($topGuidesRows as &$r) {
        $r['completion_rate'] = $r['started'] > 0 ? round(($r['completed'] / $r['started']) * 100, 1) : 0.0;
    }
    unset($r);

    $quizStmt = $db->prepare("
        SELECT q.id, q.title, COUNT(qr.id) AS attempts, SUM(qr.passed) AS passed, AVG(qr.score) AS avg_score
        FROM quizzes q LEFT JOIN quiz_results qr ON qr.quiz_id = q.id AND qr.taken_at BETWEEN ? AND ?
        WHERE q.is_active = 1
        GROUP BY q.id ORDER BY attempts DESC LIMIT 10
    ");
    $quizStmt->execute([$range['from'], $range['to']]);

    $activityStmt = $db->prepare("
        SELECT DATE(CONVERT_TZ(created_at, '+00:00', '" . ANALYTICS_TZ_OFFSET . "')) AS day, COUNT(*) AS n
        FROM analytics_events WHERE event_name = 'lesson_completed' AND created_at BETWEEN ? AND ?
        GROUP BY day ORDER BY day ASC
    ");
    $activityStmt->execute([$range['from'], $range['to']]);

    return [
        'top_lessons' => $topLessonsRows,
        'top_guides'  => $topGuidesRows,
        'quizzes'     => $quizStmt->fetchAll(),
        'activity_series' => $activityStmt->fetchAll(),
    ];
}

// ============================================================================
// AI ANALYTICS — sourced from ai_usage_log (already tracks provider/model/
// tokens/cost per request) joined with the lighter-weight analytics_events
// rows for free-limit-hit counts.
// ============================================================================

function analyticsAIStats(array $range): array
{
    $db = getDB();

    $totals = $db->prepare("
        SELECT COUNT(*) AS total, SUM(success=1) AS successful,
               SUM(membership_type='free') AS free_requests, SUM(membership_type='premium') AS premium_requests,
               SUM(tokens_input+tokens_output) AS total_tokens, SUM(cost_estimated) AS total_cost
        FROM ai_usage_log WHERE created_at BETWEEN ? AND ?
    ");
    $totals->execute([$range['from'], $range['to']]);
    $totalsRow = $totals->fetch();

    $byProvider = $db->prepare("
        SELECT provider, COUNT(*) AS requests, SUM(cost_estimated) AS cost
        FROM ai_usage_log WHERE created_at BETWEEN ? AND ? AND provider IS NOT NULL
        GROUP BY provider ORDER BY requests DESC
    ");
    $byProvider->execute([$range['from'], $range['to']]);

    $byModel = $db->prepare("
        SELECT model, COUNT(*) AS requests
        FROM ai_usage_log WHERE created_at BETWEEN ? AND ? AND model IS NOT NULL
        GROUP BY model ORDER BY requests DESC LIMIT 10
    ");
    $byModel->execute([$range['from'], $range['to']]);

    $dailyStmt = $db->prepare("
        SELECT DATE(CONVERT_TZ(created_at, '+00:00', '" . ANALYTICS_TZ_OFFSET . "')) AS day, COUNT(*) AS n
        FROM ai_usage_log WHERE created_at BETWEEN ? AND ?
        GROUP BY day ORDER BY day ASC
    ");
    $dailyStmt->execute([$range['from'], $range['to']]);

    $limitHitStmt = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM analytics_events WHERE event_name='ai_free_limit_reached' AND created_at BETWEEN ? AND ?");
    $limitHitStmt->execute([$range['from'], $range['to']]);

    $studyCardStmt = $db->prepare("SELECT COUNT(*) FROM analytics_events WHERE event_name='ai_study_card_generated' AND created_at BETWEEN ? AND ?");
    $studyCardStmt->execute([$range['from'], $range['to']]);

    return [
        'total_requests'      => (int)($totalsRow['total'] ?? 0),
        'successful_requests' => (int)($totalsRow['successful'] ?? 0),
        'free_requests'       => (int)($totalsRow['free_requests'] ?? 0),
        'premium_requests'    => (int)($totalsRow['premium_requests'] ?? 0),
        'total_tokens'        => (int)($totalsRow['total_tokens'] ?? 0),
        'estimated_cost'      => (float)($totalsRow['total_cost'] ?? 0),
        'study_cards_generated' => (int)$studyCardStmt->fetchColumn(),
        'users_hit_free_limit'  => (int)$limitHitStmt->fetchColumn(),
        'by_provider' => $byProvider->fetchAll(),
        'by_model'    => $byModel->fetchAll(),
        'daily_series'=> $dailyStmt->fetchAll(),
    ];
}

// ============================================================================
// PREMIUM & REVENUE ANALYTICS
// ============================================================================

/**
 * MRR methodology (documented per spec §16): for every currently-active
 * subscription, its plan's price is normalized to a monthly figure —
 * monthly plans contribute their price as-is; yearly plans contribute
 * price/12. This is a point-in-time snapshot (as of now), not a
 * period-filtered figure — MRR is inherently "right now", not "during the
 * selected range". Read directly from premium_plans.price_kobo, never a
 * hardcoded price, so it keeps working if pricing changes.
 */
function analyticsMRR(): float
{
    $db = getDB();
    $stmt = $db->query("
        SELECT pp.billing_cycle, pp.price_kobo, COUNT(*) AS n
        FROM user_subscriptions us
        INNER JOIN premium_plans pp ON pp.id = us.plan_id
        WHERE us.status = 'active' AND (us.expires_at IS NULL OR us.expires_at > NOW())
        GROUP BY pp.billing_cycle, pp.price_kobo
    ");
    $mrrKobo = 0.0;
    foreach ($stmt->fetchAll() as $row) {
        $monthlyEquivalent = $row['billing_cycle'] === 'yearly' ? ((float)$row['price_kobo'] / 12) : (float)$row['price_kobo'];
        $mrrKobo += $monthlyEquivalent * (int)$row['n'];
    }
    return round($mrrKobo / 100, 2); // kobo -> naira
}

function analyticsRevenueStats(array $range): array
{
    $db = getDB();

    $summary = $db->prepare("
        SELECT
            SUM(status='success') AS successful_payments,
            SUM(status='failed') AS failed_payments,
            SUM(CASE WHEN status='success' THEN amount ELSE 0 END) AS revenue
        FROM purchases WHERE created_at BETWEEN ? AND ?
    ");
    $summary->execute([$range['from'], $range['to']]);
    $summaryRow = $summary->fetch();

    $planPerf = $db->prepare("
        SELECT pp.name, pp.billing_cycle,
               COUNT(DISTINCT us.id) AS active_subscribers,
               COALESCE(SUM(CASE WHEN p.status='success' AND p.created_at BETWEEN ? AND ? THEN p.amount ELSE 0 END),0) AS period_revenue
        FROM premium_plans pp
        LEFT JOIN user_subscriptions us ON us.plan_id = pp.id AND us.status='active' AND (us.expires_at IS NULL OR us.expires_at > NOW())
        LEFT JOIN purchases p ON p.plan_id = pp.id
        WHERE pp.deleted_at IS NULL
        GROUP BY pp.id
    ");
    $planPerf->execute([$range['from'], $range['to']]);

    $cancellations = $db->prepare("SELECT COUNT(*) FROM analytics_events WHERE event_name='subscription_cancelled' AND created_at BETWEEN ? AND ?");
    $cancellations->execute([$range['from'], $range['to']]);

    $premiumUsers = (int)$db->query("SELECT COUNT(*) FROM user_subscriptions WHERE status='active' AND (expires_at IS NULL OR expires_at > NOW())")->fetchColumn();
    $totalUsers   = (int)$db->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetchColumn();

    $arpuStmt = $db->prepare("SELECT COALESCE(AVG(t.total),0) FROM (SELECT user_id, SUM(amount) AS total FROM purchases WHERE status='success' GROUP BY user_id) t");
    $arpuStmt->execute();

    $revenueSeriesStmt = $db->prepare("
        SELECT DATE(CONVERT_TZ(created_at, '+00:00', '" . ANALYTICS_TZ_OFFSET . "')) AS day, COALESCE(SUM(amount),0) AS revenue
        FROM purchases WHERE status='success' AND created_at BETWEEN ? AND ?
        GROUP BY day ORDER BY day ASC
    ");
    $revenueSeriesStmt->execute([$range['from'], $range['to']]);

    return [
        'successful_payments' => (int)($summaryRow['successful_payments'] ?? 0),
        'failed_payments'     => (int)($summaryRow['failed_payments'] ?? 0),
        'revenue'             => (float)($summaryRow['revenue'] ?? 0),
        'cancellations'       => (int)$cancellations->fetchColumn(),
        'premium_users'       => $premiumUsers,
        'premium_conversion_rate' => $totalUsers > 0 ? round(($premiumUsers / $totalUsers) * 100, 1) : 0.0,
        'mrr'  => analyticsMRR(),
        'arr'  => round(analyticsMRR() * 12, 2),
        'arpu' => round((float)$arpuStmt->fetchColumn(), 2),
        'plan_performance' => $planPerf->fetchAll(),
        'revenue_series'   => $revenueSeriesStmt->fetchAll(),
    ];
}

// ============================================================================
// BOUNTY ANALYTICS
// ============================================================================

function analyticsBountyStats(array $range): array
{
    $db = getDB();

    $created = $db->prepare("SELECT COUNT(*) FROM bounties WHERE created_at BETWEEN ? AND ?");
    $created->execute([$range['from'], $range['to']]);

    $published = $db->prepare("SELECT COUNT(*) FROM bounties WHERE published_at BETWEEN ? AND ?");
    $published->execute([$range['from'], $range['to']]);

    $views = $db->prepare("SELECT COUNT(*) FROM analytics_events WHERE event_name='bounty_viewed' AND created_at BETWEEN ? AND ?");
    $views->execute([$range['from'], $range['to']]);

    $submissions = $db->prepare("SELECT COUNT(*) FROM bounty_submissions WHERE created_at BETWEEN ? AND ?");
    $submissions->execute([$range['from'], $range['to']]);

    $winners = $db->prepare("SELECT COUNT(*) FROM bounty_submissions WHERE status='winner' AND updated_at BETWEEN ? AND ?");
    $winners->execute([$range['from'], $range['to']]);

    $completed = $db->prepare("SELECT COUNT(*) FROM bounties WHERE status='winners_announced' AND updated_at BETWEEN ? AND ?");
    $completed->execute([$range['from'], $range['to']]);

    $activeHosts = $db->prepare("SELECT COUNT(DISTINCT host_id) FROM bounties WHERE type='hosted' AND host_id IS NOT NULL AND created_at BETWEEN ? AND ?");
    $activeHosts->execute([$range['from'], $range['to']]);

    $repeatHosts = (int)$db->query("
        SELECT COUNT(*) FROM (SELECT host_id FROM bounties WHERE type='hosted' AND host_id IS NOT NULL GROUP BY host_id HAVING COUNT(*) > 1) t
    ")->fetchColumn();

    $viewCount = (int)$views->fetchColumn();
    $submissionCount = (int)$submissions->fetchColumn();

    return [
        'created'      => (int)$created->fetchColumn(),
        'published'    => (int)$published->fetchColumn(),
        'views'        => $viewCount,
        'submissions'  => $submissionCount,
        'winners'      => (int)$winners->fetchColumn(),
        'completed'    => (int)$completed->fetchColumn(),
        'active_hosts' => (int)$activeHosts->fetchColumn(),
        'repeat_hosts' => $repeatHosts,
        'view_to_submission_rate' => $viewCount > 0 ? round(($submissionCount / $viewCount) * 100, 1) : 0.0,
    ];
}

// ============================================================================
// RETENTION — D1/D7/D30
//
// Definition (documented per spec §18): for users who signed up on day X,
// what fraction had at least one "meaningful activity" event (any of
// lesson_started, lesson_completed, quiz_completed, guide_started,
// ai_chat_used, bounty_submission_made — i.e. genuine product usage, never
// a login alone) on day X+N (a 24h window around the +N mark, not "ever
// after"). Cohorts are only counted once they're actually old enough for
// the +N mark to have passed, otherwise the denominator would include
// users who simply haven't had the chance yet.
// ============================================================================

function analyticsRetention(): array
{
    $db = getDB();
    $meaningfulEvents = "'lesson_started','lesson_completed','quiz_completed','guide_started','ai_chat_used','bounty_submission_made'";

    $compute = function (int $dayOffset) use ($db, $meaningfulEvents): array {
        // Cohort: users who signed up at least $dayOffset+1 days ago (so the
        // +N window has fully elapsed for every user counted).
        $sql = "
            SELECT COUNT(DISTINCT u.id) AS cohort_size,
                   COUNT(DISTINCT CASE WHEN e.id IS NOT NULL THEN u.id END) AS retained
            FROM users u
            LEFT JOIN analytics_events e
                ON e.user_id = u.id
               AND e.event_name IN ({$meaningfulEvents})
               AND e.created_at BETWEEN DATE_ADD(u.created_at, INTERVAL ? DAY) AND DATE_ADD(u.created_at, INTERVAL ? DAY)
            WHERE u.deleted_at IS NULL
              AND u.created_at <= DATE_SUB(NOW(), INTERVAL ? DAY)
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$dayOffset, $dayOffset + 1, $dayOffset]);
        $row = $stmt->fetch();
        $cohort = (int)($row['cohort_size'] ?? 0);
        $retained = (int)($row['retained'] ?? 0);
        return ['cohort_size' => $cohort, 'retained' => $retained, 'rate' => $cohort > 0 ? round(($retained / $cohort) * 100, 1) : null];
    };

    return ['d1' => $compute(1), 'd7' => $compute(7), 'd30' => $compute(30)];
}

// ============================================================================
// ENGAGEMENT
// ============================================================================

function analyticsEngagement(array $range): array
{
    $db = getDB();
    $dau = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM analytics_events WHERE user_id IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $dau->execute();
    $wau = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM analytics_events WHERE user_id IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $wau->execute();
    $mau = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM analytics_events WHERE user_id IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $mau->execute();

    $dauVal = (int)$dau->fetchColumn(); $wauVal = (int)$wau->fetchColumn(); $mauVal = (int)$mau->fetchColumn();

    return [
        'dau' => $dauVal, 'wau' => $wauVal, 'mau' => $mauVal,
        'dau_mau_pct' => $mauVal > 0 ? round(($dauVal / $mauVal) * 100, 1) : 0.0,
    ];
}

// ============================================================================
// PRODUCT HEALTH + NORTH STAR
// ============================================================================

/**
 * Verified Earners — North Star Metric (spec §31). Definition: a learner
 * who has EITHER (a) won a paid bounty (a bounty_submissions row with
 * status='winner') OR (b) holds a certificate whose type is explicitly
 * flagged as tied to a verified real-world opportunity.
 *
 * Condition (b) currently has no data to evaluate: certificate_types has
 * no "verified real-world opportunity" flag anywhere in the schema, so
 * only condition (a) is actually measurable today. This function measures
 * exactly that and is documented as a partial implementation — see
 * docs/ANALYTICS.md "Known Limitations".
 */
function analyticsVerifiedEarners(): array
{
    $db = getDB();
    $count = (int)$db->query("SELECT COUNT(DISTINCT user_id) FROM bounty_submissions WHERE status='winner'")->fetchColumn();
    return ['count' => $count, 'partial' => true];
}

function analyticsProductHealth(array $range): array
{
    $funnel = analyticsActivationFunnel($range);
    $signups = $funnel[0]['count'] ?: 0;
    $activated = 0;
    foreach ($funnel as $s) { if ($s['key'] === 'lesson_completed') $activated = $s['count']; }

    $revenue = analyticsRevenueStats($range);
    $retention = analyticsRetention();

    $db = getDB();
    $guideCompletionRate = (function () use ($db, $range) {
        $s = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM analytics_events WHERE event_name='guide_started' AND created_at BETWEEN ? AND ?");
        $s->execute([$range['from'], $range['to']]);
        $startedN = (int)$s->fetchColumn();
        $c = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM analytics_events WHERE event_name='guide_completed' AND created_at BETWEEN ? AND ?");
        $c->execute([$range['from'], $range['to']]);
        $completedN = (int)$c->fetchColumn();
        return $startedN > 0 ? round(($completedN / $startedN) * 100, 1) : 0.0;
    })();

    $ai = analyticsAIStats($range);
    $bounty = analyticsBountyStats($range);

    return [
        'activation_rate'   => ['value' => $signups > 0 ? round(($activated / $signups) * 100, 1) : 0.0, 'help' => 'Signups who completed their first lesson, ÷ total signups, in the selected period.'],
        'premium_conversion'=> ['value' => $revenue['premium_conversion_rate'], 'help' => 'Users with an active subscription right now, ÷ all non-deleted users.'],
        'd7_retention'      => ['value' => $retention['d7']['rate'], 'help' => 'Of users who signed up 7+ days ago, % who had a meaningful activity event ~7 days after signup.'],
        'd30_retention'     => ['value' => $retention['d30']['rate'], 'help' => 'Of users who signed up 30+ days ago, % who had a meaningful activity event ~30 days after signup.'],
        'guide_completion'  => ['value' => $guideCompletionRate, 'help' => 'Guide-starts in the period that were also completed, ÷ guide-starts (distinct users).'],
        'ai_usage'          => ['value' => $ai['total_requests'], 'help' => 'Total AI Tutor + Study Card requests in the selected period (from ai_usage_log).'],
        'bounty_conversion' => ['value' => $bounty['view_to_submission_rate'], 'help' => 'Bounty submissions ÷ bounty views in the selected period.'],
        'verified_earners'  => array_merge(analyticsVerifiedEarners(), ['help' => 'Users who have won at least one paid bounty. (Certificate-based earners not yet trackable — see docs/ANALYTICS.md.)']),
    ];
}
