-- ============================================================================
-- PHASE 3 — Opportunities Hub + Gamification extensions
-- Additive-only, idempotent. Reuses users.total_xp/current_level/streak
-- columns, xp_rules, user_activity_log, levels, streak_milestones, badges,
-- user_badges, notifications, and user_bookmarks exactly as they already
-- exist — nothing here replaces that engine, only extends its inputs.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. opportunities — Jobs, Grants, Hackathons, Bounties, Ambassador Programs,
--    Events, Fellowships, Internships. One flat table, category distinguishes
--    kind (mirrors how career_paths.difficulty is a plain ENUM, not a table).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `opportunities` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `title`         VARCHAR(255) NOT NULL,
  `organization`  VARCHAR(150) DEFAULT NULL,
  `category`      ENUM('job','grant','hackathon','bounty','ambassador','event','fellowship','internship') NOT NULL,
  `difficulty`    ENUM('Beginner','Intermediate','Advanced') DEFAULT 'Beginner',
  `location`      VARCHAR(150) DEFAULT NULL,
  `work_mode`     ENUM('remote','hybrid','onsite') DEFAULT 'remote',
  `description`   TEXT DEFAULT NULL,
  `requirements`  TEXT DEFAULT NULL,
  `deadline`      DATE DEFAULT NULL,
  `reward`        VARCHAR(150) DEFAULT NULL COMMENT 'free text, e.g. "$2,000" or "Equity" — optional per spec',
  `official_link` VARCHAR(500) NOT NULL,
  `tags`          VARCHAR(255) DEFAULT NULL COMMENT 'comma-separated, mirrors projects.technologies convention',
  `is_featured`   TINYINT(1) DEFAULT 0,
  `status`        ENUM('active','archived') NOT NULL DEFAULT 'active',
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    TIMESTAMP NULL DEFAULT NULL,
  `deleted_by`    INT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_opp_category_status` (`category`,`status`),
  KEY `idx_opp_featured` (`is_featured`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. career_path_opportunities — join table, same shape as career_path_guides
--    /guide_projects. Relevance is a property of the link, not the content —
--    the same opportunity (e.g. a general hackathon) can be relevant to
--    multiple career paths without duplication.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `career_path_opportunities` (
  `id`              INT NOT NULL AUTO_INCREMENT,
  `career_path_id`  INT NOT NULL,
  `opportunity_id`  INT NOT NULL,
  `display_order`   INT DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cpo` (`career_path_id`,`opportunity_id`),
  KEY `idx_cpo_opportunity` (`opportunity_id`),
  CONSTRAINT `fk_cpo_career_path` FOREIGN KEY (`career_path_id`) REFERENCES `career_paths` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cpo_opportunity` FOREIGN KEY (`opportunity_id`) REFERENCES `opportunities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------------------------
-- 3. user_bookmarks — widen ENUM once more for 'opportunity' (same defensive
--    pattern as every prior widening of this column).
-- ----------------------------------------------------------------------------
SET @col_type = (
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE() AND table_name = 'user_bookmarks' AND column_name = 'bookmarkable_type'
);
SET @sql = IF(@col_type NOT LIKE '%opportunity%',
  "ALTER TABLE `user_bookmarks` MODIFY COLUMN `bookmarkable_type` ENUM('lesson','guide_path','airdrop','blog_post','bounty','project','resource','opportunity') NOT NULL",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 4. badges — generalize the "one badge service" beyond required_lessons/
--    quiz_id: three new parallel count-thresholds (career paths completed,
--    projects approved, certificates earned), matching the existing
--    required_lessons convention exactly rather than introducing a new
--    paradigm. Every existing badge row defaults these to 0 (inert), so
--    current lesson-count and quiz badges behave identically to before.
-- ----------------------------------------------------------------------------
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='badges' AND column_name='required_career_paths');
SET @sql = IF(@col_exists=0, 'ALTER TABLE `badges` ADD COLUMN `required_career_paths` INT DEFAULT 0 AFTER `required_lessons`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='badges' AND column_name='required_projects');
SET @sql = IF(@col_exists=0, 'ALTER TABLE `badges` ADD COLUMN `required_projects` INT DEFAULT 0 AFTER `required_career_paths`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='badges' AND column_name='required_certificates');
SET @sql = IF(@col_exists=0, 'ALTER TABLE `badges` ADD COLUMN `required_certificates` INT DEFAULT 0 AFTER `required_projects`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 5. xp_rules — new action_keys covering the events Phase 3 asks for that
--    the existing engine doesn't fire yet. lesson_complete/guide_complete/
--    quiz_pass (covers assessments too, quiz_type-agnostic)/badge_earned/
--    daily_streak already existed and are untouched.
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `xp_rules` (`action_key`, `label`, `xp_value`) VALUES
('career_path_complete', 'Career Path Completed', 150),
('project_submitted',    'Project Submitted', 15),
('project_approved',     'Project Approved', 75),
('daily_login',          'Daily Login', 5),
('bookmark_created',     'Bookmark Saved', 2),
('resource_viewed',      'Resource Viewed', 2),
('note_created',         'Note Created', 3),
('highlight_created',    'Highlight Created', 2);
