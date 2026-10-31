-- ============================================================================
-- CAREER PATHS — Phase 1 Personalized Learning Architecture
-- Additive migration. Does NOT modify lessons, guide_paths, guide_path_lessons,
-- user_lesson_progress, quizzes, or quiz_results in any way.
-- Safe to run once against web3edu_v2.sql. Idempotent-ish via IF NOT EXISTS
-- guards where MySQL supports them; run only once in production.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- career_paths
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `career_paths` (
  `id`                                  INT NOT NULL AUTO_INCREMENT,
  `name`                                VARCHAR(150) NOT NULL,
  `description`                         TEXT DEFAULT NULL,
  `icon`                                VARCHAR(20) DEFAULT NULL COMMENT 'emoji or icon key shown on the Welcome Modal card / Learn page',
  `theme_color`                         VARCHAR(20) DEFAULT '#8b5cf6',
  `display_order`                       INT DEFAULT 0,
  `is_active`                           TINYINT(1) DEFAULT 1,
  `is_foundation`                       TINYINT(1) DEFAULT 0 COMMENT 'marks the single "I''m New to Web3" entry point',
  `difficulty`                          ENUM('Beginner','Intermediate','Advanced') DEFAULT 'Beginner',
  `beginner_friendly`                   TINYINT(1) DEFAULT 0,
  `recommended_previous_career_path_id` INT DEFAULT NULL,
  `completion_badge_id`                 INT DEFAULT NULL,
  `certificate_template_id`             INT DEFAULT NULL,
  `created_at`                          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_recommended_previous` (`recommended_previous_career_path_id`),
  KEY `idx_completion_badge` (`completion_badge_id`),
  KEY `idx_certificate_template` (`certificate_template_id`),
  CONSTRAINT `fk_cp_recommended_previous` FOREIGN KEY (`recommended_previous_career_path_id`) REFERENCES `career_paths` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cp_completion_badge` FOREIGN KEY (`completion_badge_id`) REFERENCES `badges` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cp_certificate_template` FOREIGN KEY (`certificate_template_id`) REFERENCES `certificate_templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- career_path_milestones — optional presentation-only grouping layer
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `career_path_milestones` (
  `id`             INT NOT NULL AUTO_INCREMENT,
  `career_path_id` INT NOT NULL,
  `title`          VARCHAR(150) NOT NULL,
  `description`    TEXT DEFAULT NULL,
  `display_order`  INT DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_cpm_career_path` (`career_path_id`),
  CONSTRAINT `fk_cpm_career_path` FOREIGN KEY (`career_path_id`) REFERENCES `career_paths` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- career_path_guides — "Guide Rules": required/optional, visible, order,
-- milestone — scoped per (career_path, guide). The same guide_id can appear
-- in many career paths with different rules in each.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `career_path_guides` (
  `id`             INT NOT NULL AUTO_INCREMENT,
  `career_path_id` INT NOT NULL,
  `milestone_id`   INT DEFAULT NULL,
  `guide_id`       INT NOT NULL,
  `requirement`    ENUM('required','optional') DEFAULT 'required',
  `is_visible`     TINYINT(1) DEFAULT 1,
  `display_order`  INT DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cpg_career_path_guide` (`career_path_id`,`guide_id`),
  KEY `idx_cpg_milestone` (`milestone_id`),
  KEY `idx_cpg_guide` (`guide_id`),
  CONSTRAINT `fk_cpg_career_path` FOREIGN KEY (`career_path_id`) REFERENCES `career_paths` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cpg_milestone` FOREIGN KEY (`milestone_id`) REFERENCES `career_path_milestones` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cpg_guide` FOREIGN KEY (`guide_id`) REFERENCES `guide_paths` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- user_career_paths — history + "which one is active"
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_career_paths` (
  `id`              INT NOT NULL AUTO_INCREMENT,
  `user_id`         INT NOT NULL,
  `career_path_id`  INT NOT NULL,
  `status`          ENUM('active','paused','completed') DEFAULT 'active',
  `started_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_activity_at` TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `completed_at`    TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ucp_user_path` (`user_id`,`career_path_id`),
  KEY `idx_ucp_user_status` (`user_id`,`status`),
  CONSTRAINT `fk_ucp_career_path` FOREIGN KEY (`career_path_id`) REFERENCES `career_paths` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- user_onboarding_state — powers the "first Learn visit" Welcome Modal
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_onboarding_state` (
  `user_id`                  INT NOT NULL,
  `has_seen_learn_onboarding` TINYINT(1) DEFAULT 0,
  `skipped_at`                TIMESTAMP NULL DEFAULT NULL,
  `completed_at`              TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------------------------
-- Seed: the 8 Welcome Modal paths. Safe re-run guard via name lookup.
-- No guides attached yet — admins attach real guides via the Career Path
-- Builder (admin/career-paths.php?action=builder&id=N). Paths work fine as
-- empty shells: the roadmap simply shows "no guides attached yet".
-- ----------------------------------------------------------------------------
INSERT INTO `career_paths` (`name`,`description`,`icon`,`theme_color`,`display_order`,`is_active`,`is_foundation`,`difficulty`,`beginner_friendly`)
SELECT * FROM (SELECT
  'I''m New to Web3' AS name,
  'Start from zero: wallets, blockchains, and the core concepts every path builds on.' AS description,
  '🌱' AS icon, '#22c55e' AS theme_color, 1 AS display_order, 1 AS is_active, 1 AS is_foundation, 'Beginner' AS difficulty, 1 AS beginner_friendly
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM `career_paths` WHERE `name` = 'I''m New to Web3');

INSERT INTO `career_paths` (`name`,`description`,`icon`,`theme_color`,`display_order`,`is_active`,`is_foundation`,`difficulty`,`beginner_friendly`,`recommended_previous_career_path_id`)
SELECT 'Become a Blockchain Developer','Learn to build, deploy, and audit smart contracts and dApps.','👨‍💻','#8b5cf6',2,1,0,'Intermediate',0,
  (SELECT id FROM career_paths WHERE is_foundation = 1 LIMIT 1)
WHERE NOT EXISTS (SELECT 1 FROM career_paths WHERE name = 'Become a Blockchain Developer');

INSERT INTO `career_paths` (`name`,`description`,`icon`,`theme_color`,`display_order`,`is_active`,`is_foundation`,`difficulty`,`beginner_friendly`,`recommended_previous_career_path_id`)
SELECT 'Become a Crypto Trader','Chart reading, risk management, and market structure for traders.','📈','#f59e0b',3,1,0,'Intermediate',0,
  (SELECT id FROM career_paths WHERE is_foundation = 1 LIMIT 1)
WHERE NOT EXISTS (SELECT 1 FROM career_paths WHERE name = 'Become a Crypto Trader');

INSERT INTO `career_paths` (`name`,`description`,`icon`,`theme_color`,`display_order`,`is_active`,`is_foundation`,`difficulty`,`beginner_friendly`,`recommended_previous_career_path_id`)
SELECT 'Build a Web3 Startup','Tokenomics, fundraising, and go-to-market for founders.','🚀','#ef4444',4,1,0,'Advanced',0,
  (SELECT id FROM career_paths WHERE is_foundation = 1 LIMIT 1)
WHERE NOT EXISTS (SELECT 1 FROM career_paths WHERE name = 'Build a Web3 Startup');

INSERT INTO `career_paths` (`name`,`description`,`icon`,`theme_color`,`display_order`,`is_active`,`is_foundation`,`difficulty`,`beginner_friendly`,`recommended_previous_career_path_id`)
SELECT 'Earn Through Airdrops','Find, qualify for, and farm airdrops safely and efficiently.','🪂','#06b6d4',5,1,0,'Beginner',1,
  (SELECT id FROM career_paths WHERE is_foundation = 1 LIMIT 1)
WHERE NOT EXISTS (SELECT 1 FROM career_paths WHERE name = 'Earn Through Airdrops');

INSERT INTO `career_paths` (`name`,`description`,`icon`,`theme_color`,`display_order`,`is_active`,`is_foundation`,`difficulty`,`beginner_friendly`,`recommended_previous_career_path_id`)
SELECT 'Get a Web3 Job','Portfolio, interview prep, and the skills employers screen for.','💼','#3b82f6',6,1,0,'Intermediate',0,
  (SELECT id FROM career_paths WHERE is_foundation = 1 LIMIT 1)
WHERE NOT EXISTS (SELECT 1 FROM career_paths WHERE name = 'Get a Web3 Job');

INSERT INTO `career_paths` (`name`,`description`,`icon`,`theme_color`,`display_order`,`is_active`,`is_foundation`,`difficulty`,`beginner_friendly`,`recommended_previous_career_path_id`)
SELECT 'Build AI + Web3 Apps','Combine LLMs and agents with smart contracts and on-chain data.','🤖','#a855f7',7,1,0,'Advanced',0,
  (SELECT id FROM career_paths WHERE is_foundation = 1 LIMIT 1)
WHERE NOT EXISTS (SELECT 1 FROM career_paths WHERE name = 'Build AI + Web3 Apps');

INSERT INTO `career_paths` (`name`,`description`,`icon`,`theme_color`,`display_order`,`is_active`,`is_foundation`,`difficulty`,`beginner_friendly`,`recommended_previous_career_path_id`)
SELECT 'Become a Web3 Content Creator','Grow an audience explaining crypto through content and community.','🎨','#ec4899',8,1,0,'Beginner',1,
  (SELECT id FROM career_paths WHERE is_foundation = 1 LIMIT 1)
WHERE NOT EXISTS (SELECT 1 FROM career_paths WHERE name = 'Become a Web3 Content Creator');
