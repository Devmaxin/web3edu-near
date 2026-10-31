-- ============================================================================
-- WEB3EDU — MIGRATION: Bounty Marketplace Phase 2
--
-- REQUIRES database/bounty-marketplace-migration.sql to already be applied.
--
-- Run this ONCE via phpMyAdmin -> your database -> SQL tab -> paste -> Go.
-- Purely additive — nothing existing is dropped, renamed, or has its data
-- touched. Safe to run more than once.
--
-- Adds:
--   bounty_submissions.host_feedback  — free-text feedback a host/admin can
--     leave on a submission, surfaced on the participant's My Submissions page.
--   bounty_submissions.status ENUM widened to add 'cancelled' — the original
--     migration's enum only had submitted/under_review/winner/rejected, but
--     "Cancelled" is one of the statuses My Submissions and Admin Submission
--     Management need to show/set. Without this, setting a submission to
--     Cancelled from Admin -> Submissions would fail.
-- ============================================================================

SET @status_has_cancelled = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'bounty_submissions' AND column_name = 'status'
    AND column_type LIKE '%''cancelled''%'
);
SET @sql0 = IF(@status_has_cancelled = 0,
  "ALTER TABLE `bounty_submissions` MODIFY COLUMN `status` ENUM('submitted','under_review','winner','rejected','cancelled') NOT NULL DEFAULT 'submitted'",
  'SELECT 1'
);
PREPARE stmt0 FROM @sql0; EXECUTE stmt0; DEALLOCATE PREPARE stmt0;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'bounty_submissions' AND column_name = 'host_feedback'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE `bounty_submissions` ADD COLUMN `host_feedback` VARCHAR(1000) DEFAULT NULL COMMENT 'Optional feedback from the host/admin, shown to the participant' AFTER `certificate_code`",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Helpful index for the new Admin Submission Management filters (by
-- participant, since bounty_id/status already have indexes from migration 1).
SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'bounty_submissions' AND index_name = 'idx_sub_user'
);
SET @sql2 = IF(@idx_exists = 0,
  "ALTER TABLE `bounty_submissions` ADD INDEX `idx_sub_user` (`user_id`)",
  'SELECT 1'
);
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;
