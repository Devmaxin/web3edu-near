<?php
/**
 * Web3Edu Admin — Product Analytics Dashboard
 * ============================================================================
 * Read-only reporting surface over the existing `analytics_events` table
 * plus the app's own source-of-truth tables (users, purchases,
 * user_subscriptions, ai_usage_log, bounties, certificates, etc.) — see
 * includes/analytics/AnalyticsQueries.php for every query behind this page.
 *
 * Kept intentionally dependency-free: no charting library was added, since
 * the brief is explicit about not introducing new frameworks/tooling for a
 * reporting layer — every chart here is a small inline CSS bar/sparkline.
 */
require_once __DIR__ . '/../includes/analytics/AnalyticsQueries.php';

// ── CSV export (handled before the admin chrome renders — raw file response) ──
if (isset($_GET['export'])) {
    requireAdmin(); // header.php below normally does this; export bypasses header.php entirely
    $range = analyticsResolveRange($_GET['range'] ?? 'last30', $_GET['from'] ?? null, $_GET['to'] ?? null);
    $export = $_GET['export'];
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="web3edu-analytics-' . $export . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');

    switch ($export) {
        case 'events':
            $stmt = getDB()->prepare("SELECT id, user_id, event_name, properties, session_id, created_at FROM analytics_events WHERE created_at BETWEEN ? AND ? ORDER BY created_at DESC LIMIT 20000");
            $stmt->execute([$range['from'], $range['to']]);
            fputcsv($out, ['id', 'user_id', 'event_name', 'properties', 'session_id', 'created_at']);
            while ($row = $stmt->fetch()) fputcsv($out, $row);
            break;
        case 'user_growth':
            fputcsv($out, ['date', 'new_users']);
            foreach (analyticsUserGrowthSeries($range) as $r) fputcsv($out, [$r['date'], $r['count']]);
            break;
        case 'revenue':
            $stmt = getDB()->prepare("SELECT p.id, p.user_id, p.plan_id, p.billing_cycle, p.reference, p.amount, p.status, p.created_at FROM purchases p WHERE p.created_at BETWEEN ? AND ? ORDER BY p.created_at DESC LIMIT 20000");
            $stmt->execute([$range['from'], $range['to']]);
            fputcsv($out, ['id', 'user_id', 'plan_id', 'billing_cycle', 'reference', 'amount', 'status', 'created_at']);
            while ($row = $stmt->fetch()) fputcsv($out, $row);
            break;
        case 'bounties':
            $stmt = getDB()->prepare("SELECT id, type, host_id, title, status, view_count, num_winners, created_at, published_at FROM bounties WHERE created_at BETWEEN ? AND ? ORDER BY created_at DESC LIMIT 20000");
            $stmt->execute([$range['from'], $range['to']]);
            fputcsv($out, ['id', 'type', 'host_id', 'title', 'status', 'view_count', 'num_winners', 'created_at', 'published_at']);
            while ($row = $stmt->fetch()) fputcsv($out, $row);
            break;
        default:
            fputcsv($out, ['error', 'Unknown export type']);
    }
    fclose($out);
    exit;
}

$adminTitle = 'Analytics';
require_once __DIR__ . '/header.php';

$presets = [
    'today' => 'Today', 'yesterday' => 'Yesterday', 'last7' => 'Last 7 Days', 'last30' => 'Last 30 Days',
    'this_month' => 'This Month', 'last_month' => 'Last Month', 'this_year' => 'This Year', 'custom' => 'Custom Range',
];
$rangePreset = $_GET['range'] ?? 'last30';
if (!isset($presets[$rangePreset])) $rangePreset = 'last30';
$customFrom = $_GET['from'] ?? '';
$customTo   = $_GET['to'] ?? '';
$range = analyticsResolveRange($rangePreset, $customFrom ?: null, $customTo ?: null);

$tabs = [
    'overview'  => 'Overview', 'growth' => 'User Growth', 'funnel' => 'Activation Funnel',
    'learning'  => 'Learning', 'ai' => 'AI', 'revenue' => 'Premium & Revenue',
    'bounty'    => 'Bounties', 'retention' => 'Retention & Engagement', 'health' => 'Product Health',
];
$tab = $_GET['tab'] ?? 'overview';
if (!isset($tabs[$tab])) $tab = 'overview';

function qs(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    return '?' . http_build_query($params);
}

/** Tiny inline CSS bar chart — no JS charting dependency. */
function renderBars(array $series, string $labelKey, string $valueKey, string $color = 'var(--primary-bright)', int $height = 120): void {
    if (empty($series)) { echo '<p style="color:var(--text-muted);font-size:0.85rem">No data in this period.</p>'; return; }
    $max = max(array_column($series, $valueKey)) ?: 1;
    echo '<div style="display:flex;align-items:flex-end;gap:4px;height:' . $height . 'px;overflow-x:auto;padding-top:8px">';
    foreach ($series as $point) {
        $h = $max > 0 ? max(2, round(($point[$valueKey] / $max) * ($height - 20))) : 2;
        echo '<div title="' . sanitize((string)$point[$labelKey]) . ': ' . sanitize((string)$point[$valueKey]) . '" style="flex:1;min-width:6px;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%">';
        echo '<div style="width:100%;background:' . $color . ';border-radius:2px 2px 0 0;height:' . $h . 'px"></div>';
        echo '</div>';
    }
    echo '</div>';
}

function pctBadge(?float $pct): string {
    if ($pct === null) return '<span style="color:var(--text-muted);font-size:0.75rem">new</span>';
    $color = $pct > 0 ? '#0B7958' : ($pct < 0 ? '#fca5a5' : 'var(--text-muted)');
    $arrow = $pct > 0 ? '↑' : ($pct < 0 ? '↓' : '·');
    return '<span style="color:' . $color . ';font-size:0.75rem;font-weight:700">' . $arrow . ' ' . abs($pct) . '%</span>';
}

function metricCard(string $label, string $value, ?string $sub = null): void {
    echo '<div class="stat-card">';
    echo '<div class="stat-card-num" style="font-size:1.6rem">' . $value . '</div>';
    echo '<div class="stat-card-label">' . sanitize($label) . '</div>';
    if ($sub) echo '<div style="margin-top:4px">' . $sub . '</div>';
    echo '</div>';
}
?>

<h1 class="admin-page-title">Product Analytics</h1>

<!-- Date range filter -->
<form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:20px;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:14px 16px">
    <input type="hidden" name="tab" value="<?= sanitize($tab) ?>">
    <select name="range" class="form-select" style="width:auto" onchange="this.form.submit()">
        <?php foreach ($presets as $key => $label): ?>
        <option value="<?= $key ?>" <?= $rangePreset === $key ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($rangePreset === 'custom'): ?>
        <input type="date" name="from" value="<?= sanitize($customFrom) ?>" class="form-input" style="width:auto">
        <span style="color:var(--text-muted)">to</span>
        <input type="date" name="to" value="<?= sanitize($customTo) ?>" class="form-input" style="width:auto">
        <button type="submit" class="btn btn-secondary" style="padding:8px 14px">Apply</button>
    <?php endif; ?>
    <span style="color:var(--text-muted);font-size:0.85rem;margin-left:8px"><?= sanitize($range['label']) ?> (Africa/Lagos)</span>
</form>

<!-- Tab bar -->
<div style="display:flex;gap:6px;margin-bottom:24px;border-bottom:1px solid var(--border);flex-wrap:wrap">
    <?php foreach ($tabs as $key => $label): ?>
    <a href="<?= qs(['tab' => $key]) ?>" style="padding:8px 14px;font-size:0.82rem;font-weight:600;border-radius:var(--radius) var(--radius) 0 0;text-decoration:none;white-space:nowrap;
        <?= $tab === $key ? 'background:var(--bg-card);border:1px solid var(--border);border-bottom:1px solid var(--bg-card);color:var(--primary-bright)' : 'color:var(--text-muted)' ?>">
        <?= $label ?>
    </a>
    <?php endforeach; ?>
</div>

<?php // ═══════════════════════════════════════ OVERVIEW ═══════════════════════════════════════
if ($tab === 'overview'):
    $ov = analyticsOverview($range);
?>
<h3 style="margin-bottom:12px;color:var(--text-muted);font-size:0.85rem;text-transform:uppercase;letter-spacing:0.05em">Lifetime Totals</h3>
<div class="grid-3" style="margin-bottom:28px">
    <?php metricCard('Total Users', number_format($ov['lifetime']['total_users'])); ?>
    <?php metricCard('Premium Users', number_format($ov['lifetime']['premium_users'])); ?>
    <?php metricCard('Total Revenue', '₦' . number_format($ov['lifetime']['total_revenue'], 2)); ?>
    <?php metricCard('Lessons Completed', number_format($ov['lifetime']['lessons_completed'])); ?>
    <?php metricCard('Guides Completed', number_format($ov['lifetime']['guides_completed'])); ?>
    <?php metricCard('Certificates Issued', number_format($ov['lifetime']['certificates_issued'])); ?>
    <?php metricCard('Bounties Completed', number_format($ov['lifetime']['bounties_completed'])); ?>
    <?php metricCard('Current MRR', '₦' . number_format($ov['mrr'], 2), '<span style="color:var(--text-muted);font-size:0.72rem">point-in-time, not period-filtered</span>'); ?>
</div>

<h3 style="margin-bottom:12px;color:var(--text-muted);font-size:0.85rem;text-transform:uppercase;letter-spacing:0.05em"><?= sanitize($range['label']) ?></h3>
<div class="grid-3">
    <?php metricCard('New Users', number_format($ov['period']['new_users'])); ?>
    <?php metricCard('Active Users', number_format($ov['period']['active_users']), '<span style="color:var(--text-muted);font-size:0.72rem">≥1 tracked action</span>'); ?>
    <?php metricCard('Premium Signups', number_format($ov['period']['premium_signups'])); ?>
    <?php metricCard('Revenue', '₦' . number_format($ov['period']['revenue'], 2)); ?>
    <?php metricCard('Lessons Completed', number_format($ov['period']['lessons_completed'])); ?>
    <?php metricCard('Guides Completed', number_format($ov['period']['guides_completed'])); ?>
    <?php metricCard('Certificates Issued', number_format($ov['period']['certificates_issued'])); ?>
    <?php metricCard('Bounties Completed', number_format($ov['period']['bounties_completed'])); ?>
</div>

<?php // ═══════════════════════════════════════ USER GROWTH ═══════════════════════════════════════
elseif ($tab === 'growth'):
    $g = analyticsUserGrowthComparison($range);
?>
<div class="grid-3" style="margin-bottom:24px">
    <?php metricCard('New Users', number_format($g['new_users']), pctBadge($g['new_users_change_pct']) . ' <span style="color:var(--text-muted);font-size:0.72rem">vs previous period (' . number_format($g['new_users_prev']) . ')</span>'); ?>
    <?php metricCard('Active Users', number_format($g['active_users'])); ?>
    <?php metricCard('Total Registered', number_format($g['total_registered'])); ?>
</div>
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <strong style="font-size:0.9rem">New users per day</strong>
        <a href="<?= qs(['export' => 'user_growth']) ?>" class="btn-ghost" style="padding:4px 10px;font-size:0.72rem">Export CSV</a>
    </div>
    <?php renderBars($g['series'], 'date', 'count'); ?>
</div>

<?php // ═══════════════════════════════════════ ACTIVATION FUNNEL ═══════════════════════════════════════
elseif ($tab === 'funnel'):
    $funnel = analyticsActivationFunnel($range);
?>
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px">
    <p style="color:var(--text-muted);font-size:0.82rem;margin-bottom:20px">Unique users who fired each event at least once in <?= sanitize($range['label']) ?>. Percentages are of Signups (this stage) and of the previous stage.</p>
    <?php foreach ($funnel as $i => $s): $width = max(4, $s['pct_of_start']); ?>
    <div style="margin-bottom:14px">
        <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:4px">
            <span><?= sanitize($s['label']) ?></span>
            <span><strong><?= number_format($s['count']) ?></strong> · <?= $s['pct_of_start'] ?>% of start<?= $s['pct_of_prev'] !== null ? ' · ' . $s['pct_of_prev'] . '% of prev. step' : '' ?></span>
        </div>
        <div style="background:var(--bg-hover, rgba(255,255,255,0.05));border-radius:6px;height:22px;overflow:hidden">
            <div style="width:<?= $width ?>%;background:var(--primary-bright);height:100%;border-radius:6px"></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php // ═══════════════════════════════════════ LEARNING ═══════════════════════════════════════
elseif ($tab === 'learning'):
    $l = analyticsLearningStats($range);
?>
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;margin-bottom:24px">
    <strong style="font-size:0.9rem">Lessons completed per day</strong>
    <?php renderBars($l['activity_series'], 'day', 'n', '#0B7958'); ?>
</div>

<div class="admin-dash-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px">
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">
        <div style="padding:16px 16px 0"><strong style="font-size:0.9rem">Top Lessons</strong></div>
        <table class="admin-table">
            <thead><tr><th>Lesson</th><th>Started</th><th>Completed</th><th>Rate</th></tr></thead>
            <tbody>
            <?php foreach ($l['top_lessons'] as $r): ?>
            <tr><td style="font-size:0.82rem"><?= sanitize($r['title']) ?></td><td><?= $r['started'] ?></td><td><?= $r['completed'] ?></td><td><?= $r['completion_rate'] ?>%</td></tr>
            <?php endforeach; if (empty($l['top_lessons'])): ?>
            <tr><td colspan="4" style="color:var(--text-muted)">No lesson activity in this period.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">
        <div style="padding:16px 16px 0"><strong style="font-size:0.9rem">Top Guide Paths</strong></div>
        <table class="admin-table">
            <thead><tr><th>Guide</th><th>Started</th><th>Completed</th><th>Rate</th></tr></thead>
            <tbody>
            <?php foreach ($l['top_guides'] as $r): ?>
            <tr><td style="font-size:0.82rem"><?= sanitize($r['title']) ?></td><td><?= $r['started'] ?></td><td><?= $r['completed'] ?></td><td><?= $r['completion_rate'] ?>%</td></tr>
            <?php endforeach; if (empty($l['top_guides'])): ?>
            <tr><td colspan="4" style="color:var(--text-muted)">No guide activity in this period.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">
    <div style="padding:16px 16px 0"><strong style="font-size:0.9rem">Quizzes</strong></div>
    <table class="admin-table">
        <thead><tr><th>Quiz</th><th>Attempts</th><th>Passed</th><th>Avg Score</th></tr></thead>
        <tbody>
        <?php foreach ($l['quizzes'] as $r): ?>
        <tr><td style="font-size:0.82rem"><?= sanitize($r['title']) ?></td><td><?= (int)$r['attempts'] ?></td><td><?= (int)($r['passed'] ?? 0) ?></td><td><?= $r['avg_score'] !== null ? round($r['avg_score'], 1) . '%' : '—' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php // ═══════════════════════════════════════ AI ═══════════════════════════════════════
elseif ($tab === 'ai'):
    $a = analyticsAIStats($range);
?>
<div class="grid-3" style="margin-bottom:24px">
    <?php metricCard('Total AI Requests', number_format($a['total_requests'])); ?>
    <?php metricCard('Free-User Requests', number_format($a['free_requests'])); ?>
    <?php metricCard('Premium-User Requests', number_format($a['premium_requests'])); ?>
    <?php metricCard('Study Cards Generated', number_format($a['study_cards_generated'])); ?>
    <?php metricCard('Users Who Hit Free Limit', number_format($a['users_hit_free_limit'])); ?>
    <?php metricCard('Estimated AI Cost', '$' . number_format($a['estimated_cost'], 4), '<span style="color:var(--text-muted);font-size:0.72rem">estimated — not a provider invoice</span>'); ?>
</div>
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;margin-bottom:24px">
    <strong style="font-size:0.9rem">AI requests per day</strong>
    <?php renderBars($a['daily_series'], 'day', 'n', '#d8b4fe'); ?>
</div>
<div class="admin-dash-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:24px">
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">
        <div style="padding:16px 16px 0"><strong style="font-size:0.9rem">By Provider</strong></div>
        <table class="admin-table"><thead><tr><th>Provider</th><th>Requests</th><th>Est. Cost</th></tr></thead><tbody>
        <?php foreach ($a['by_provider'] as $r): ?>
        <tr><td><?= sanitize($r['provider']) ?></td><td><?= (int)$r['requests'] ?></td><td>$<?= number_format($r['cost'], 4) ?></td></tr>
        <?php endforeach; if (empty($a['by_provider'])): ?><tr><td colspan="3" style="color:var(--text-muted)">No AI activity in this period.</td></tr><?php endif; ?>
        </tbody></table>
    </div>
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">
        <div style="padding:16px 16px 0"><strong style="font-size:0.9rem">By Model</strong></div>
        <table class="admin-table"><thead><tr><th>Model</th><th>Requests</th></tr></thead><tbody>
        <?php foreach ($a['by_model'] as $r): ?>
        <tr><td style="font-size:0.8rem"><?= sanitize($r['model']) ?></td><td><?= (int)$r['requests'] ?></td></tr>
        <?php endforeach; if (empty($a['by_model'])): ?><tr><td colspan="2" style="color:var(--text-muted)">No AI activity in this period.</td></tr><?php endif; ?>
        </tbody></table>
    </div>
</div>

<?php // ═══════════════════════════════════════ REVENUE ═══════════════════════════════════════
elseif ($tab === 'revenue'):
    $r = analyticsRevenueStats($range);
?>
<div class="grid-3" style="margin-bottom:24px">
    <?php metricCard('Premium Users (now)', number_format($r['premium_users'])); ?>
    <?php metricCard('Premium Conversion', $r['premium_conversion_rate'] . '%'); ?>
    <?php metricCard('Revenue (period)', '₦' . number_format($r['revenue'], 2)); ?>
    <?php metricCard('MRR', '₦' . number_format($r['mrr'], 2)); ?>
    <?php metricCard('ARR', '₦' . number_format($r['arr'], 2)); ?>
    <?php metricCard('ARPU (all-time avg)', '₦' . number_format($r['arpu'], 2)); ?>
    <?php metricCard('Successful Payments', number_format($r['successful_payments'])); ?>
    <?php metricCard('Failed Payments', number_format($r['failed_payments'])); ?>
    <?php metricCard('Cancellations', number_format($r['cancellations'])); ?>
</div>
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;margin-bottom:24px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <strong style="font-size:0.9rem">Revenue over time</strong>
        <a href="<?= qs(['export' => 'revenue']) ?>" class="btn-ghost" style="padding:4px 10px;font-size:0.72rem">Export CSV</a>
    </div>
    <?php renderBars($r['revenue_series'], 'day', 'revenue', '#0B7958'); ?>
</div>
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">
    <div style="padding:16px 16px 0"><strong style="font-size:0.9rem">Plan Performance</strong></div>
    <table class="admin-table">
        <thead><tr><th>Plan</th><th>Billing</th><th>Active Subscribers</th><th>Revenue (period)</th></tr></thead>
        <tbody>
        <?php foreach ($r['plan_performance'] as $p): ?>
        <tr><td><?= sanitize($p['name']) ?></td><td><?= ucfirst($p['billing_cycle']) ?></td><td><?= (int)$p['active_subscribers'] ?></td><td>₦<?= number_format($p['period_revenue'], 2) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php // ═══════════════════════════════════════ BOUNTIES ═══════════════════════════════════════
elseif ($tab === 'bounty'):
    $b = analyticsBountyStats($range);
?>
<div class="grid-3" style="margin-bottom:24px">
    <?php metricCard('Bounties Created', number_format($b['created'])); ?>
    <?php metricCard('Bounties Published', number_format($b['published'])); ?>
    <?php metricCard('Bounty Views', number_format($b['views'])); ?>
    <?php metricCard('Submissions', number_format($b['submissions'])); ?>
    <?php metricCard('Winners', number_format($b['winners'])); ?>
    <?php metricCard('Bounties Completed', number_format($b['completed'])); ?>
    <?php metricCard('Active Hosts', number_format($b['active_hosts'])); ?>
    <?php metricCard('Repeat Hosts (all-time)', number_format($b['repeat_hosts'])); ?>
    <?php metricCard('View → Submission Rate', $b['view_to_submission_rate'] . '%'); ?>
</div>
<div style="text-align:right">
    <a href="<?= qs(['export' => 'bounties']) ?>" class="btn-ghost" style="padding:4px 10px;font-size:0.72rem">Export bounties CSV</a>
</div>

<?php // ═══════════════════════════════════════ RETENTION & ENGAGEMENT ═══════════════════════════════════════
elseif ($tab === 'retention'):
    $ret = analyticsRetention();
    $eng = analyticsEngagement($range);
?>
<div class="grid-3" style="margin-bottom:24px">
    <?php metricCard('D1 Retention', $ret['d1']['rate'] !== null ? $ret['d1']['rate'] . '%' : '—', '<span style="color:var(--text-muted);font-size:0.72rem">cohort: ' . number_format($ret['d1']['cohort_size']) . '</span>'); ?>
    <?php metricCard('D7 Retention', $ret['d7']['rate'] !== null ? $ret['d7']['rate'] . '%' : '—', '<span style="color:var(--text-muted);font-size:0.72rem">cohort: ' . number_format($ret['d7']['cohort_size']) . '</span>'); ?>
    <?php metricCard('D30 Retention', $ret['d30']['rate'] !== null ? $ret['d30']['rate'] . '%' : '—', '<span style="color:var(--text-muted);font-size:0.72rem">cohort: ' . number_format($ret['d30']['cohort_size']) . '</span>'); ?>
</div>
<p style="color:var(--text-muted);font-size:0.8rem;margin-bottom:24px">Retained = had a meaningful activity event (lesson/guide/quiz/AI/bounty action — never just a login) in the 24h window around N days after signup. Retention windows always use all-time data, independent of the date filter above.</p>
<div class="grid-3">
    <?php metricCard('DAU', number_format($eng['dau'])); ?>
    <?php metricCard('WAU', number_format($eng['wau'])); ?>
    <?php metricCard('MAU', number_format($eng['mau'])); ?>
</div>
<div style="margin-top:12px"><?php metricCard('DAU/MAU (stickiness)', $eng['dau_mau_pct'] . '%'); ?></div>

<?php // ═══════════════════════════════════════ PRODUCT HEALTH ═══════════════════════════════════════
elseif ($tab === 'health'):
    $h = analyticsProductHealth($range);
    $render = function (string $label, array $m, string $suffix = '%') {
        echo '<div class="stat-card" title="' . sanitize($m['help']) . '">';
        echo '<div class="stat-card-num" style="font-size:1.6rem">' . ($m['value'] !== null ? $m['value'] . $suffix : '—') . '</div>';
        echo '<div class="stat-card-label">' . sanitize($label) . '</div>';
        echo '<div style="margin-top:6px;color:var(--text-muted);font-size:0.7rem;line-height:1.4">' . sanitize($m['help']) . '</div>';
        echo '</div>';
    };
?>
<p style="color:var(--text-muted);font-size:0.82rem;margin-bottom:20px">Hover or read the description under each metric for exactly how it's calculated. North Star Metric: <strong>Verified Earners</strong>.</p>
<div class="grid-3">
    <?php $render('Activation Rate', $h['activation_rate']); ?>
    <?php $render('Premium Conversion', $h['premium_conversion']); ?>
    <?php $render('D7 Retention', $h['d7_retention']); ?>
    <?php $render('D30 Retention', $h['d30_retention']); ?>
    <?php $render('Guide Completion Rate', $h['guide_completion']); ?>
    <?php $render('AI Usage (requests)', $h['ai_usage'], ''); ?>
    <?php $render('Bounty Conversion', $h['bounty_conversion']); ?>
    <div class="stat-card" title="<?= sanitize($h['verified_earners']['help']) ?>">
        <div class="stat-card-num" style="font-size:1.6rem;color:var(--primary-bright)"><?= number_format($h['verified_earners']['count']) ?></div>
        <div class="stat-card-label">Verified Earners ⭐ North Star</div>
        <div style="margin-top:6px;color:var(--text-muted);font-size:0.7rem;line-height:1.4"><?= sanitize($h['verified_earners']['help']) ?></div>
    </div>
</div>

<?php endif; ?>

<?php if ($tab === 'overview'): ?>
<div style="text-align:right;margin-top:16px">
    <a href="<?= qs(['export' => 'events']) ?>" class="btn-ghost" style="padding:4px 10px;font-size:0.72rem">Export raw events CSV</a>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
