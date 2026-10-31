-- ============================================================================
-- WEB3EDU — MIGRATION: Bounty Host Approval Workflow (Phase 1)
--
-- REQUIRES database/bounty-marketplace-migration.sql (and phase2) to already
-- be applied.
--
-- Run this ONCE via phpMyAdmin -> your database -> SQL tab -> paste -> Go.
-- Purely additive/backfilling — nothing existing is dropped or renamed.
-- Safe to run more than once.
--
-- WHY THIS MIGRATION EXISTS
-- Previously, `bounty_hosts` had no approval concept at all —
-- createOrGetBountyHost() auto-created a row the instant a user hosted their
-- first bounty. This migration separates "Host Profile" approval from
-- "Hosted Bounty" approval:
--   - Adds a real status machine to bounty_hosts (pending_approval / approved /
--     request_changes / rejected / suspended).
--   - Adds the extra Host Profile fields the new "Become a Host" form collects
--     (country, organization_type, social links) that didn't exist before.
--   - Adds admin-configurable fee_policy (standard/exempt) per host — used by
--     Phase 2's listing-fee system, but the column belongs on the host record
--     itself so it's added here alongside the rest of the approval workflow.
--   - Adds admin_message (host-visible) vs internal_notes (admin-only) so the
--     admin Host Applications review screen has somewhere to put both.
--
-- BACKFILL NOTE (important):
-- Every bounty_hosts row that exists before this migration runs was created
-- under the old "auto-approved on first bounty" behavior — those hosts have
-- already been hosting bounties in production. To avoid retroactively locking
-- real hosts out of their own dashboard, every pre-existing row is backfilled
-- to status='approved' below. Only NEW Host Profiles created after this
-- migration (via the new "Become a Host" flow) start at 'pending_approval'.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. New columns on bounty_hosts
-- ----------------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name='bounty_hosts' AND column_name='status');
SET @sql := IF(@col = 0,
  "ALTER TABLE `bounty_hosts` ADD COLUMN `status` ENUM('pending_approval','approved','request_changes','rejected','suspended') NOT NULL DEFAULT 'pending_approval' AFTER `user_id`",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name='bounty_hosts' AND column_name='country');
SET @sql := IF(@col = 0,
  "ALTER TABLE `bounty_hosts` ADD COLUMN `country` VARCHAR(100) DEFAULT NULL AFTER `description`",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name='bounty_hosts' AND column_name='organization_type');
SET @sql := IF(@col = 0,
  "ALTER TABLE `bounty_hosts` ADD COLUMN `organization_type` VARCHAR(100) DEFAULT NULL AFTER `country`",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name='bounty_hosts' AND column_name='twitter');
SET @sql := IF(@col = 0,
  "ALTER TABLE `bounty_hosts` ADD COLUMN `twitter` VARCHAR(150) DEFAULT NULL AFTER `organization_type`",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name='bounty_hosts' AND column_name='discord');
SET @sql := IF(@col = 0,
  "ALTER TABLE `bounty_hosts` ADD COLUMN `discord` VARCHAR(150) DEFAULT NULL AFTER `twitter`",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name='bounty_hosts' AND column_name='telegram');
SET @sql := IF(@col = 0,
  "ALTER TABLE `bounty_hosts` ADD COLUMN `telegram` VARCHAR(150) DEFAULT NULL AFTER `discord`",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name='bounty_hosts' AND column_name='fee_policy');
SET @sql := IF(@col = 0,
  "ALTER TABLE `bounty_hosts` ADD COLUMN `fee_policy` ENUM('standard','exempt') NOT NULL DEFAULT 'standard' AFTER `is_verified`",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name='bounty_hosts' AND column_name='admin_message');
SET @sql := IF(@col = 0,
  "ALTER TABLE `bounty_hosts` ADD COLUMN `admin_message` VARCHAR(1000) DEFAULT NULL COMMENT 'Visible to the host — reason for Request Changes / Reject / Suspend, or a general note' AFTER `fee_policy`",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name='bounty_hosts' AND column_name='internal_notes');
SET @sql := IF(@col = 0,
  "ALTER TABLE `bounty_hosts` ADD COLUMN `internal_notes` VARCHAR(1000) DEFAULT NULL COMMENT 'Admin-only, never shown to the host' AFTER `admin_message`",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name='bounty_hosts' AND column_name='reviewed_by');
SET @sql := IF(@col = 0,
  "ALTER TABLE `bounty_hosts` ADD COLUMN `reviewed_by` INT DEFAULT NULL COMMENT 'FK admins.id' AFTER `internal_notes`",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name='bounty_hosts' AND column_name='reviewed_at');
SET @sql := IF(@col = 0,
  "ALTER TABLE `bounty_hosts` ADD COLUMN `reviewed_at` TIMESTAMP NULL DEFAULT NULL AFTER `reviewed_by`",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name='bounty_hosts' AND index_name='idx_host_status');
SET @sql := IF(@idx = 0,
  "ALTER TABLE `bounty_hosts` ADD INDEX `idx_host_status` (`status`)",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 2. Backfill — every host row that already existed before this migration
--    was implicitly approved under the old auto-create behavior.
-- ----------------------------------------------------------------------------
UPDATE `bounty_hosts` SET `status` = 'approved' WHERE `status` = 'pending_approval' AND `created_at` < NOW();

-- ----------------------------------------------------------------------------
-- 3. Email templates for the Host Profile approval workflow
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `email_templates` (`template_key`, `subject`, `html_body`, `variables_hint`, `is_active`) VALUES

('host_profile_submitted_admin', 'New Host Profile application: {{organization_name}}',
 '<p>Hi Admin,</p><p><strong>{{username}}</strong> submitted a new Host Profile application: <strong>{{organization_name}}</strong>.</p><p><a href="{{site_url}}/admin/bounty-hosts.php" style="color:#7C3AED;font-weight:600;">Review it →</a></p>',
 '{{username}}, {{organization_name}}, {{site_url}}', 1),

('host_profile_resubmitted_admin', 'Host Profile updated after changes were requested: {{organization_name}}',
 '<p>Hi Admin,</p><p><strong>{{username}}</strong> updated and resubmitted their Host Profile: <strong>{{organization_name}}</strong>.</p><p><a href="{{site_url}}/admin/bounty-hosts.php" style="color:#7C3AED;font-weight:600;">Review it →</a></p>',
 '{{username}}, {{organization_name}}, {{site_url}}', 1),

('host_profile_approved', 'Your Host Profile was approved 🎉',
 '<p>Hi {{username}},</p><p>Good news — your Host Profile for <strong>{{organization_name}}</strong> has been approved! You can now create Hosted Bounties on Web3Edu.</p><p><a href="{{site_url}}/bounty-host-dashboard.php" style="color:#7C3AED;font-weight:600;">Go to your Host Dashboard →</a></p>',
 '{{username}}, {{organization_name}}, {{site_url}}', 1),

('host_profile_request_changes', 'Action needed on your Host Profile',
 '<p>Hi {{username}},</p><p>We reviewed your Host Profile for <strong>{{organization_name}}</strong> and need a few changes before we can approve it.</p><p><strong>Message from the Web3Edu team:</strong><br>{{message}}</p><p><a href="{{site_url}}/host-profile.php" style="color:#7C3AED;font-weight:600;">Update your Host Profile →</a></p>',
 '{{username}}, {{organization_name}}, {{message}}, {{site_url}}', 1),

('host_profile_rejected', 'Your Host Profile application was not approved',
 '<p>Hi {{username}},</p><p>Your Host Profile application for <strong>{{organization_name}}</strong> was not approved.</p><p><strong>Reason:</strong> {{message}}</p><p>You''re welcome to submit a new application if your circumstances change.</p>',
 '{{username}}, {{organization_name}}, {{message}}', 1),

('host_profile_suspended', 'Your Host Profile has been suspended',
 '<p>Hi {{username}},</p><p>Your Host Profile for <strong>{{organization_name}}</strong> has been suspended and can no longer create or manage Hosted Bounties.</p><p><strong>Reason:</strong> {{message}}</p><p>If you believe this is a mistake, please contact support.</p>',
 '{{username}}, {{organization_name}}, {{message}}', 1);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- Done. Next steps:
--   1. Existing hosts are backfilled to 'approved' automatically — no action
--      needed for them.
--   2. New hosts now go through Admin -> Bounties -> Host Applications.
-- ============================================================================
