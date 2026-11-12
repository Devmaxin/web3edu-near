-- ============================================================================
-- WEB3EDU — MIGRATION: Gamification Phase 1 (Onboarding Reward + Streak Freezes)
--
-- Run this ONCE against the existing web3edu_v2 database. Fresh installs
-- should fold this into database/web3edu_v2.sql instead.
--
-- What this does:
--   1. Adds `badges.badge_key` — a stable machine key (like
--      certificate_types.type_key) so code can look up a specific badge
--      (e.g. the registration badge) without depending on its auto-increment
--      id or matching on the admin-editable `name` column. NULL/unique, so
--      every existing badge row is left untouched.
--   2. Seeds `xp_rules` with the 'onboarding_complete' action (10 XP),
--      reusing the existing xp_rules/awardXp() ledger — no new points
--      table. INSERT IGNORE against the existing uniq_action_key key, so
--      this is a no-op if it's already been run.
--   3. Seeds `badges` with the "First Step" registration badge
--      (badge_key='first_step'). required_lessons/career_paths/projects/
--      certificates are all left at 0, so evaluateBadgeService() (the
--      count-based auto-awarder) will never pick it up automatically —
--      it's only ever awarded directly by awardOnboardingReward().
--   4. Adds `users.streak_freeze_count` — the user's available freeze
--      balance. Reuses the existing streak columns
--      (current_streak_days/longest_streak_days/last_activity_date) for
--      everything else; no parallel streak table.
--   5. Creates `streak_freeze_log` — an audit trail of every freeze earned
--      or consumed, with a per-user unique constraint on
--      (user_id, protected_date) for 'consumed' rows so the same missed
--      calendar day can never burn two freezes, even under a retry/race.
--
-- Safe to run multiple times (every step is guarded).
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. badges.badge_key
-- ---------------------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'badges'
    AND column_name = 'badge_key'
);

SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `badges` ADD COLUMN `badge_key` VARCHAR(50) DEFAULT NULL COMMENT ''stable machine key for badges code needs to look up directly, e.g. first_step'' AFTER `name`, ADD UNIQUE KEY `uniq_badge_key` (`badge_key`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. xp_rules: onboarding_complete
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `xp_rules` (`action_key`, `label`, `xp_value`, `is_active`)
VALUES ('onboarding_complete', 'Onboarding Completed', 10, 1);

-- ---------------------------------------------------------------------------
-- 3. badges: First Step (registration badge)
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `badges`
  (`badge_key`, `name`, `description`, `icon`, `required_lessons`, `xp_reward`, `is_active`)
VALUES
  ('first_step', 'First Step', 'Completed your Web3Edu onboarding.', '🎉', 0, 0, 1);

-- ---------------------------------------------------------------------------
-- 4. users.streak_freeze_count
-- ---------------------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'users'
    AND column_name = 'streak_freeze_count'
);

SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `streak_freeze_count` INT NOT NULL DEFAULT 0 COMMENT ''available streak freezes; never allowed to go negative'' AFTER `longest_streak_days`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 5. streak_freeze_log
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `streak_freeze_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `action` enum('earned','consumed') NOT NULL,
  `source` varchar(50) NOT NULL COMMENT 'e.g. admin_grant, streak_missed_day (more sources land in later phases)',
  `protected_date` date DEFAULT NULL COMMENT 'the missed calendar day this freeze protected (consumed rows only)',
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_streak_freeze_log_user` (`user_id`),
  UNIQUE KEY `uniq_user_protected_date` (`user_id`, `protected_date`),
  CONSTRAINT `fk_streak_freeze_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
