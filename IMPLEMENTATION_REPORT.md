# Web3Edu — Product Analytics System: Implementation Report

## A. Files Changed

**8 new files:**
- `includes/analytics.php` — core `Analytics::track()`/`trackOnce()` service
- `includes/analytics/AnalyticsQueries.php` — every dashboard/report metric query
- `includes/analytics/AnalyticsReportService.php` — daily/weekly email report generation
- `includes/analytics/AnalyticsReportRunner.php` — opportunistic scheduler (InfinityFree cron workaround)
- `admin/analytics.php` — the admin dashboard
- `database/analytics-phase1-migration.sql` — indexes + new email templates
- `cron/process_analytics_reports.php` — real-cron endpoint (for once off InfinityFree)
- `docs/ANALYTICS.md` — architecture, event dictionary, KPI definitions, limitations

**26 modified files** (all additive — no existing logic removed):
`includes/config.php`, `includes/notifications/NotificationService.php`,
`includes/gamification.php`, `includes/certificates.php`,
`includes/bounties.php`, `includes/subscriptions.php`, `admin/header.php`,
`admin/notification-center.php`, `register.php`, `login.php`, `logout.php`,
`auth/google-callback.php`, `onboarding.php`, `learn/lesson.php`,
`guides/lesson.php`, `api/complete_lesson.php`, `api/submit_quiz.php`,
`api/ai_chat.php`, `api/ai_study_card.php`, `api/toggle_bookmark.php`,
`api/paystack-webhook.php`, `payment-callback.php`, `upgrade.php`,
`certificate-view.php`, `bounty-detail.php`, `lessons.php`

All 34 files pass `php -l` with no syntax errors.

## B. Database Changes

- **No new tables.** `analytics_events` already existed and was unused —
  this system is the first thing to write to it.
- **2 new indexes** on `analytics_events`: `idx_analytics_user_event_time`
  (user_id, event_name, created_at) and `idx_analytics_session`
  (session_id, created_at).
- **7 new `email_templates` rows** (via `INSERT IGNORE`, never overwrites
  an admin's edits): `analytics_daily_report`, `analytics_weekly_report`,
  `payment_failed_admin`, `premium_cancelled_admin`,
  `premium_purchased_admin`, `bounty_submission_admin`, `bounty_won_admin`.
- **1 migration file**, idempotent (safe to run more than once):
  `database/analytics-phase1-migration.sql`.

## C. Events Implemented

31 of the 39 taxonomy events are wired and firing at real product
moments. 8 are documented as not-yet-applicable (no corresponding
feature/trigger exists in the current product: `resource_viewed`,
`video_started`/`video_completed`, `certificate_shared`,
`notification_opened`, `referral_created`/`_clicked`/`_converted`).
Full dictionary with fire conditions, properties, and idempotency
guarantees: **`docs/ANALYTICS.md` §2**.

## D. Dashboard Metrics

`admin/analytics.php` — 9 tabs: Overview, User Growth, Activation Funnel,
Learning, AI, Premium & Revenue, Bounties, Retention & Engagement, Product
Health. Date filtering: Today / Yesterday / Last 7 Days / Last 30 Days /
This Month / Last Month / This Year / Custom Range, with period-over-period
comparison on growth. Lifetime totals are visually and structurally
separate from period metrics (two distinct card groups, never merged).
Every formula is documented in **`docs/ANALYTICS.md` §3**. CSV export is
available for raw events, user growth, revenue, and bounties.

No new frontend dependency was added — every chart is a small inline CSS
bar chart, matching the brief's instruction not to introduce new
frameworks/tooling for a reporting layer.

## E. Email Reports

- **Daily Analytics Report** — once per Lagos calendar day.
- **Weekly Analytics Report** — Mondays only, includes a "Notable Changes"
  section that only states changes actually backed by that run's numbers.
- **5 new/upgraded admin alerts**: New Premium Subscription, Premium
  Cancellation, Payment Failure, New Bounty Submission, Bounty Winner
  Announced. (New Bounty Host Application already had a working alert —
  reused, not duplicated.)
- All are individually toggleable from **Admin → Settings → Notification
  Preferences** (extended the existing `notificationEventCatalog()` — no
  parallel settings system).
- All recipients come from the existing `getAdminNotificationRecipients()`
  — never a hardcoded address.
- Manual "Send Now" buttons added to **Admin → Notification Center** for
  both reports.
- Scheduling: opportunistic (piggybacked on site traffic) right now, since
  InfinityFree has no reachable external cron for anything in this
  codebase — same workaround already used for the email queue and
  inactivity reminders. `cron/process_analytics_reports.php` is ready for
  real cron the moment this project moves hosts. Full explanation:
  **`docs/ANALYTICS.md` §6**.

## F. Testing

**What I could verify in this environment:** every one of the 34 changed
files passes `php -l` (PHP 8.3) with zero syntax errors. Every SQL query
in `AnalyticsQueries.php` was checked column-by-column against the actual
schema in `database/*.sql` (not assumed) before being written — table and
column names, ENUM values (`bounties.status`, `bounty_submissions.status`,
`purchases.status`, `user_subscriptions.status`), and units (confirmed
`purchases.amount` is stored in Naira, `premium_plans.price_kobo` in
kobo, and that the existing checkout flow already does that conversion
correctly).

**What I could NOT do in this environment:** run the application against
a live MySQL database, click through registration → lesson → quiz →
certificate → payment → bounty flows, or send a real test email. This
sandbox has no MySQL instance and no access to InfinityFree/Paystack.

**Before deploying, please:**
1. Run `database/analytics-phase1-migration.sql` against a staging copy first.
2. Walk through: register → complete onboarding → view a lesson → complete
   it → take a quiz → check `admin/analytics.php` shows the new user in
   the funnel and the lesson/quiz counts.
3. Use Paystack's test mode to run one checkout → confirm `payment_completed`
   fires once (not twice) even if you refresh the callback URL.
4. Click "Send Daily Analytics Now" in Notification Center and confirm the
   email arrives with real numbers.
5. Run `SELECT @@global.time_zone, NOW();` on the production DB — see
   §4 of `docs/ANALYTICS.md`.

## G. Known Limitations

Full detail in **`docs/ANALYTICS.md` §7**. Headline items:
- Timezone handling assumes the DB stores UTC timestamps — unverified against your actual server.
- Verified Earners (North Star) currently only counts bounty winners; the certificate-based half of its definition has no supporting schema field yet.
- Opportunistic scheduling means a zero-traffic day silently skips that day's report until real cron is available.
- AI cost is AIService's own estimate, not a reconciled provider invoice.
- `quiz_started` and `bounty_submission_started` are best-available proxies, not true "began" signals — this product has no separate UI step for either.

## H. Recommended Next Steps

1. Verify the DB server's timezone against the assumption in `docs/ANALYTICS.md` §4.
2. Add a `verified_opportunity` flag to `certificate_types` to complete the Verified Earners definition.
3. Move to a host with real cron and flip `ANALYTICS_REPORT_RUNNER_ENABLED` off in favor of `cron/process_analytics_reports.php`.
4. Add click-tracking for lesson resources and notification links if those become measurement priorities.
5. Once volume supports it, move Activation Rate and Guide Completion Rate to true cohort-based measurement rather than period-scoped counts.
