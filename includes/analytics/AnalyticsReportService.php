<?php
/**
 * Analytics Report Service — generates the Daily and Weekly Analytics
 * emails for admins and queues them through the existing email pipeline.
 *
 * Modeled directly on includes/notifications/DailySummaryService.php
 * (same file, same conventions) — this is a second, KPI-focused digest
 * next to that ops-focused one, not a replacement for it. Reuses:
 * getAdminNotificationRecipients(), deliverEmailTemplate() + email_queue,
 * adminNotify(), and every number comes from
 * includes/analytics/AnalyticsQueries.php — the exact same functions the
 * admin/analytics.php dashboard uses, so an emailed number and the
 * dashboard's number for the same period never disagree.
 *
 * This file only *generates and queues* the reports. Scheduling (running
 * once a day / once a week without a human clicking a button) is handled
 * by AnalyticsReportRunner.php (opportunistic, same InfinityFree-cron
 * workaround as EmailQueueRunner.php/InactivityRunner.php) and by
 * cron/process_analytics_reports.php (the real-cron path for once you're
 * off InfinityFree). Both call the two functions below.
 */
require_once __DIR__ . '/AnalyticsQueries.php';

/** Builds and queues the Daily Analytics Report. Returns the stats used (for the manual "Send Now" button / cron log output). */
function generateDailyAnalyticsReport(): array
{
    $today = analyticsResolveRange('today');
    $yesterday = analyticsResolveRange('yesterday');

    $ov = analyticsOverview($today);
    $ovYesterday = analyticsOverview($yesterday);
    $ai = analyticsAIStats($today);
    $bounty = analyticsBountyStats($today);

    $vars = [
        'report_date'      => date('F j, Y'),
        'new_users'        => number_format($ov['period']['new_users']),
        'new_users_prev'   => number_format($ovYesterday['period']['new_users']),
        'active_users'     => number_format($ov['period']['active_users']),
        'onboarding_completed' => number_format($ov['period']['new_users'] > 0 ? 0 : 0), // see note below
        'lessons_completed'=> number_format($ov['period']['lessons_completed']),
        'guides_completed' => number_format($ov['period']['guides_completed']),
        'ai_requests'      => number_format($ai['total_requests']),
        'premium_signups'  => number_format($ov['period']['premium_signups']),
        'revenue'          => '₦' . number_format($ov['period']['revenue'], 2),
        'bounty_activity'  => $bounty['views'] . ' views · ' . $bounty['submissions'] . ' submissions · ' . $bounty['winners'] . ' winners',
        'dashboard_url'    => SITE_URL . '/admin/analytics.php?range=today',
    ];
    // onboarding_completed needs its own count (distinct from new_users) — computed directly rather than reusing $ov:
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM analytics_events WHERE event_name='onboarding_completed' AND created_at BETWEEN ? AND ?");
    $stmt->execute([$today['from'], $today['to']]);
    $vars['onboarding_completed'] = number_format((int)$stmt->fetchColumn());

    foreach (getAdminNotificationRecipients() as $email) {
        deliverEmailTemplate('analytics_daily_report', $email, $vars);
    }

    adminNotify(
        'analytics_daily_report',
        'Daily Analytics — ' . $vars['report_date'],
        "{$vars['new_users']} New Users · {$vars['active_users']} Active · {$vars['lessons_completed']} Lessons Completed · {$vars['revenue']} Revenue",
        SITE_URL . '/admin/analytics.php?range=today'
    );

    return $vars;
}

/** Builds and queues the Weekly Analytics Report (last 7 days vs the 7 days before that). */
function generateWeeklyAnalyticsReport(): array
{
    $thisWeek = analyticsResolveRange('last7');

    $growth  = analyticsUserGrowthComparison($thisWeek);
    $ov      = analyticsOverview($thisWeek);
    $revenue = analyticsRevenueStats($thisWeek);
    $bounty  = analyticsBountyStats($thisWeek);
    $learning= analyticsLearningStats($thisWeek);
    $ai      = analyticsAIStats($thisWeek);

    $db = getDB();
    $onboardingStmt = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM analytics_events WHERE event_name='onboarding_completed' AND created_at BETWEEN ? AND ?");
    $onboardingStmt->execute([$thisWeek['from'], $thisWeek['to']]);
    $onboardingCompleted = (int)$onboardingStmt->fetchColumn();
    $onboardingRate = $growth['new_users'] > 0 ? round(($onboardingCompleted / $growth['new_users']) * 100, 1) : 0.0;

    $firstLessonStmt = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM analytics_events WHERE event_name='lesson_completed' AND created_at BETWEEN ? AND ?");
    $firstLessonStmt->execute([$thisWeek['from'], $thisWeek['to']]);
    $firstLessonRate = $growth['new_users'] > 0 ? round(((int)$firstLessonStmt->fetchColumn() / $growth['new_users']) * 100, 1) : 0.0;

    $topLessonsStr = empty($learning['top_lessons']) ? 'No lesson activity this week.'
        : implode('; ', array_map(fn($l) => "{$l['title']} ({$l['completed']} completions)", array_slice($learning['top_lessons'], 0, 3)));
    $topGuidesStr = empty($learning['top_guides']) ? 'No guide activity this week.'
        : implode('; ', array_map(fn($g) => "{$g['title']} ({$g['completed']} completions)", array_slice($learning['top_guides'], 0, 3)));

    // Notable changes — only ever states a change that's actually backed by
    // the numbers above (never invents a narrative); a period with nothing
    // notable just says so.
    $highlights = [];
    if ($growth['new_users_change_pct'] !== null && abs($growth['new_users_change_pct']) >= 20) {
        $highlights[] = 'New user signups ' . ($growth['new_users_change_pct'] > 0 ? 'increased' : 'decreased') . ' ' . abs($growth['new_users_change_pct']) . '% vs the previous week.';
    }
    if ($revenue['revenue'] > 0 && $revenue['successful_payments'] > 0) {
        $highlights[] = "{$revenue['successful_payments']} successful payments totaling ₦" . number_format($revenue['revenue'], 2) . ' this week.';
    }
    if ($revenue['cancellations'] > 0) {
        $highlights[] = "{$revenue['cancellations']} subscription cancellation(s) this week.";
    }
    $highlightsStr = empty($highlights) ? 'Nothing outside the normal range this week.' : implode(' ', $highlights);

    $vars = [
        'week_start'        => date('M j', strtotime($thisWeek['from_lagos'] ?? 'now')),
        'new_users'         => number_format($growth['new_users']),
        'growth_pct'        => $growth['new_users_change_pct'] === null ? 'new' : ($growth['new_users_change_pct'] > 0 ? '+' : '') . $growth['new_users_change_pct'] . '%',
        'active_users'      => number_format($growth['active_users']),
        'onboarding_rate'   => $onboardingRate . '%',
        'first_lesson_rate' => $firstLessonRate . '%',
        'lessons_completed' => number_format($ov['period']['lessons_completed']),
        'guides_completed'  => number_format($ov['period']['guides_completed']),
        'ai_requests'       => number_format($ai['total_requests']),
        'premium_signups'   => number_format($ov['period']['premium_signups']),
        'revenue'           => '₦' . number_format($revenue['revenue'], 2),
        'mrr'               => '₦' . number_format($revenue['mrr'], 2),
        'cancellations'     => number_format($revenue['cancellations']),
        'bounties_created'  => number_format($bounty['created']),
        'bounty_submissions'=> number_format($bounty['submissions']),
        'bounty_winners'    => number_format($bounty['winners']),
        'top_lessons'       => 'Top lessons: ' . $topLessonsStr,
        'top_guides'        => 'Top guides: ' . $topGuidesStr,
        'highlights'        => $highlightsStr,
        'dashboard_url'     => SITE_URL . '/admin/analytics.php?range=last7',
    ];

    foreach (getAdminNotificationRecipients() as $email) {
        deliverEmailTemplate('analytics_weekly_report', $email, $vars);
    }

    adminNotify(
        'analytics_weekly_report',
        'Weekly Analytics — Week of ' . $vars['week_start'],
        "{$vars['new_users']} New Users ({$vars['growth_pct']}) · {$vars['revenue']} Revenue · {$vars['mrr']} MRR",
        SITE_URL . '/admin/analytics.php?range=last7'
    );

    return $vars;
}
