-- ============================================================================
-- WEB3EDU — MIGRATION: Product Analytics & Admin Reporting (Phase 1)
--
-- Run this ONCE via phpMyAdmin -> your database -> SQL tab -> paste -> Go.
-- Purely additive/backfilling — nothing existing is dropped or renamed.
-- Safe to run more than once.
--
-- WHAT THIS MIGRATION DOES
-- 1. Adds two indexes to the EXISTING `analytics_events` table (see
--    database/web3edu_v2.sql, SECTION 10) so per-user timelines (retention,
--    "did this user do X in the funnel") and anonymous-session lookups don't
--    scan the whole table. No new analytics table is created — the existing
--    one was correctly designed for this from the start, it was just unused.
-- 2. Registers 6 new `email_templates` rows for the analytics email system:
--      - analytics_daily_report / analytics_weekly_report (admin KPI digests)
--      - payment_failed_admin, premium_cancelled_admin, premium_purchased_admin,
--        bounty_submission_admin, bounty_won_admin (admin alert emails — the
--        events already existed in the product, they just had no admin-facing
--        email template wired to them yet; "New Bounty Host Application" already
--        had one — host_profile_submitted_admin — so it's reused as-is, not duplicated)
--    Uses INSERT IGNORE against the existing UNIQUE KEY `uniq_template_key`,
--    so it never overwrites a template an admin has since edited by hand.
-- 3. No changes to any other existing table, column, or data.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. Indexes on the existing analytics_events table
-- ----------------------------------------------------------------------------

-- Per-user event timeline (retention cohorts, activation funnel, "did user X
-- do event Y after signup date Z" queries) — the existing idx_event_name_time
-- index doesn't cover a per-user lookup at all.
SET @idx := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name='analytics_events' AND index_name='idx_analytics_user_event_time');
SET @sql := IF(@idx = 0,
  "ALTER TABLE `analytics_events` ADD INDEX `idx_analytics_user_event_time` (`user_id`,`event_name`,`created_at`)",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Anonymous-session correlation (pre-signup funnel steps keyed by session_id
-- rather than user_id).
SET @idx := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name='analytics_events' AND index_name='idx_analytics_session');
SET @sql := IF(@idx = 0,
  "ALTER TABLE `analytics_events` ADD INDEX `idx_analytics_session` (`session_id`,`created_at`)",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 2. Email templates — analytics reports + new admin alerts
-- ----------------------------------------------------------------------------
-- Rendered via the EXISTING deliverEmailTemplate()/email_queue pipeline
-- (includes/email/EmailService.php) — same as every other template in this
-- table. {{placeholder}} syntax matches every other row here.

INSERT IGNORE INTO `email_templates` (`template_key`, `subject`, `html_body`, `variables_hint`, `is_active`) VALUES
('analytics_daily_report',
 'Web3Edu Daily Analytics — {{report_date}}',
 '<h2>Web3Edu Daily Analytics — {{report_date}}</h2>
<p><strong>New Users:</strong> {{new_users}} (vs {{new_users_prev}} yesterday)</p>
<p><strong>Active Users:</strong> {{active_users}}</p>
<p><strong>Onboarding Completed:</strong> {{onboarding_completed}}</p>
<p><strong>Lessons Completed:</strong> {{lessons_completed}}</p>
<p><strong>Guides Completed:</strong> {{guides_completed}}</p>
<p><strong>AI Requests:</strong> {{ai_requests}}</p>
<p><strong>Premium Signups:</strong> {{premium_signups}}</p>
<p><strong>Revenue:</strong> {{revenue}}</p>
<p><strong>Bounty Activity:</strong> {{bounty_activity}}</p>
<p><a href="{{dashboard_url}}">View full dashboard →</a></p>',
 'report_date, new_users, new_users_prev, active_users, onboarding_completed, lessons_completed, guides_completed, ai_requests, premium_signups, revenue, bounty_activity, dashboard_url',
 1),

('analytics_weekly_report',
 'Web3Edu Weekly Analytics — Week of {{week_start}}',
 '<h2>Web3Edu Weekly Analytics — Week of {{week_start}}</h2>
<h3>Growth</h3>
<p>New users: {{new_users}} ({{growth_pct}} vs previous week) · Active users: {{active_users}}</p>
<h3>Activation</h3>
<p>Onboarding completion: {{onboarding_rate}} · First lesson completion: {{first_lesson_rate}}</p>
<h3>Engagement</h3>
<p>Lessons completed: {{lessons_completed}} · Guides completed: {{guides_completed}} · AI usage: {{ai_requests}}</p>
<h3>Revenue</h3>
<p>New premium users: {{premium_signups}} · Revenue: {{revenue}} · MRR: {{mrr}} · Cancellations: {{cancellations}}</p>
<h3>Marketplace</h3>
<p>Bounties created: {{bounties_created}} · Submissions: {{bounty_submissions}} · Winners: {{bounty_winners}}</p>
<h3>Top Content</h3>
<p>{{top_lessons}}</p>
<p>{{top_guides}}</p>
<h3>Notable changes</h3>
<p>{{highlights}}</p>
<p><a href="{{dashboard_url}}">View full dashboard →</a></p>',
 'week_start, new_users, growth_pct, active_users, onboarding_rate, first_lesson_rate, lessons_completed, guides_completed, ai_requests, premium_signups, revenue, mrr, cancellations, bounties_created, bounty_submissions, bounty_winners, top_lessons, top_guides, highlights, dashboard_url',
 1),

('payment_failed_admin',
 'Payment failed — {{username}}',
 '<p><strong>{{username}}</strong> ({{email}}) had a failed payment attempt for the {{plan_name}} plan.</p><p>Reference: {{reference}}</p><p><a href="{{link}}">View in admin →</a></p>',
 'username, email, plan_name, reference, link',
 1),

('premium_cancelled_admin',
 'Premium subscription cancelled — {{username}}',
 '<p><strong>{{username}}</strong> ({{email}}) cancelled their {{plan_name}} subscription.</p><p><a href="{{link}}">View in admin →</a></p>',
 'username, email, plan_name, link',
 1),

('premium_purchased_admin',
 'New premium subscription — {{username}}',
 '<p><strong>{{username}}</strong> ({{email}}) purchased the {{plan_name}} plan (₦{{amount}}).</p><p><a href="{{link}}">View in admin →</a></p>',
 'username, email, plan_name, amount, link',
 1),

('bounty_submission_admin',
 'New bounty submission — {{bounty_title}}',
 '<p><strong>{{username}}</strong> submitted an entry to <strong>{{bounty_title}}</strong>.</p><p><a href="{{link}}">Review submissions →</a></p>',
 'username, bounty_title, link',
 1),

('bounty_won_admin',
 'Bounty winner announced — {{bounty_title}}',
 '<p><strong>{{username}}</strong> was announced as a winner ({{placement}}) for <strong>{{bounty_title}}</strong>.</p><p><a href="{{link}}">View bounty →</a></p>',
 'username, bounty_title, placement, link',
 1);

SET FOREIGN_KEY_CHECKS = 1;
