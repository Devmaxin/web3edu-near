-- ============================================================================
-- Phase 2 — Notification System Completion
-- ============================================================================
-- No new tables. admin_notifications, notifications, email_queue and
-- email_templates already existed from Phase 1 and are reused as-is.
--
-- This migration only adds the two email templates that were genuinely
-- missing after the Phase 2 audit:
--   1. bounty_submission_received — confirms receipt to a participant right
--      after they submit an entry (wires up the 'new_submission' event that
--      was already reserved, but unwired, in the Notification Preferences
--      catalog).
--   2. daily_summary — used by includes/notifications/DailySummaryService.php
--      to email admins the "Today's Activity" digest. Queued through the
--      existing email_queue exactly like every other template; no new
--      delivery mechanism.
-- ============================================================================

INSERT IGNORE INTO `email_templates` (`template_key`, `subject`, `html_body`, `variables_hint`, `is_active`) VALUES
('bounty_submission_received',
 'We received your submission for "{{title}}"',
 '<p>Hi {{username}},</p><p>Your entry for <strong>{{title}}</strong> has been received and is now awaiting review. We will let you know as soon as there is an update.</p><p><a href="{{site_url}}/bounty-submissions.php" style="color:#7C3AED;font-weight:600;">View your submissions →</a></p>',
 '{{username}}, {{title}}, {{site_url}}',
 1),

('daily_summary',
 'Web3Edu Daily Summary — {{summary_date}}',
 '<p>Hi Admin,</p><p>Here is what happened on Web3Edu in the last 24 hours:</p><ul><li>{{host_applications}} Host Application(s)</li><li>{{new_submissions}} New Submission(s)</li><li>{{payments_awaiting_review}} Payment(s) Awaiting Review</li><li>{{new_premium_users}} New Premium User(s)</li><li>{{new_registrations}} New Registration(s)</li></ul><p><a href="{{site_url}}/admin/notification-center.php" style="color:#7C3AED;font-weight:600;">Open Admin Notification Center →</a></p>',
 '{{summary_date}}, {{host_applications}}, {{new_submissions}}, {{payments_awaiting_review}}, {{new_premium_users}}, {{new_registrations}}, {{site_url}}',
 1);
