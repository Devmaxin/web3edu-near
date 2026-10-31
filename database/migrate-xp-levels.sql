-- ============================================================================
-- WEB3EDU — MIGRATION: XP & Level system fixes
--
-- Run this ONCE against an existing web3edu_v2 database. Fresh installs
-- should just import the updated database/web3edu_v2.sql instead — it
-- already defines guide_path_lessons.xp_reward.
--
-- What this does:
--   1. Adds `xp_reward` to `guide_path_lessons` so admins can set a custom
--      XP payout for an individual lesson within a specific guide path.
--      NULL (the default) means "use the global xp_rules.lesson_complete
--      value", so existing rows keep behaving exactly as before.
--
-- Safe to run multiple times (guarded with a column-existence check).
-- ============================================================================

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'guide_path_lessons'
    AND column_name = 'xp_reward'
);

SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `guide_path_lessons` ADD COLUMN `xp_reward` INT DEFAULT NULL COMMENT ''per-lesson XP override; NULL = use xp_rules.lesson_complete'' AFTER `sort_order`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
