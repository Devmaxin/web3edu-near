-- ============================================================================
-- WEB3EDU — MIGRATION: Bounty Marketplace Improvements
-- (Premium-Only gating + Cover Image for the new Admin Edit Bounty page)
--
-- Requires database/bounty-marketplace-migration.sql to already be applied.
-- Purely additive — adds two columns. Safe to run more than once.
-- ============================================================================

-- 1. is_premium_only — gates submissions to Premium members (feature 1).
--    Ignored for external bounties (submissions happen off-platform).
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'bounties' AND column_name = 'is_premium_only'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE `bounties` ADD COLUMN `is_premium_only` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Only Premium members can submit entries. Ignored for external bounties.' AFTER `is_featured`",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. cover_image — a wider banner image shown on the bounty detail page,
--    separate from the square project_logo, editable from the new Admin
--    Edit Bounty page (feature 3).
SET @col_exists2 = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'bounties' AND column_name = 'cover_image'
);
SET @sql2 = IF(@col_exists2 = 0,
  "ALTER TABLE `bounties` ADD COLUMN `cover_image` VARCHAR(255) DEFAULT NULL AFTER `project_logo`",
  'SELECT 1'
);
PREPARE stmt FROM @sql2; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Done. Nothing else changes — both columns default to empty/off for every
-- existing bounty, so nothing is retroactively locked or altered.
-- ============================================================================
