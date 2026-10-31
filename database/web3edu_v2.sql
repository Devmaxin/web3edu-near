-- ============================================================================
-- WEB3EDU V2 — PRODUCTION DATABASE SCHEMA
-- Single-file, fresh-install schema representing the final v2 architecture.
-- Not a migration log — every rename, drop, and correction from the original
-- schema is already applied. Import directly into an empty MySQL database.
--
-- Engine: InnoDB throughout (required for foreign keys).
-- Charset: utf8mb4 / utf8mb4_unicode_ci throughout.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;

-- ============================================================================
-- SECTION 1 — AUTH: admins, users
-- ============================================================================

CREATE TABLE `admins` (
  `id`         INT NOT NULL AUTO_INCREMENT,
  `email`      VARCHAR(255) NOT NULL,
  `password`   VARCHAR(255) NOT NULL,
  `name`       VARCHAR(100) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  `deleted_by` INT DEFAULT NULL COMMENT 'self-referencing: another admin who deactivated this one',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id`                   INT NOT NULL AUTO_INCREMENT,
  `username`             VARCHAR(50) DEFAULT NULL,
  `email`                VARCHAR(255) NOT NULL,
  `password`             VARCHAR(255) NOT NULL COMMENT 'empty string for Google-only accounts',
  `avatar`               VARCHAR(100) DEFAULT 'cyber_01.png',
  `membership_type`      ENUM('free','premium') DEFAULT 'free' COMMENT 'cached flag kept in sync with user_subscriptions — NOT the source of truth',
  `total_xp`             INT DEFAULT 0,
  `current_level`        INT DEFAULT 1,
  `current_streak_days`  INT DEFAULT 0,
  `longest_streak_days`  INT DEFAULT 0,
  `last_activity_date`   DATE DEFAULT NULL,
  `profile_completed`    TINYINT(1) DEFAULT 0,
  `google_id`            VARCHAR(255) DEFAULT NULL,
  `login_provider`       VARCHAR(50) DEFAULT 'email',
  `reset_token_hash`     VARCHAR(64) DEFAULT NULL COMMENT 'sha256 of the reset token — never store the raw token',
  `reset_token_expires_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`           TIMESTAMP NULL DEFAULT NULL,
  `deleted_by`           INT DEFAULT NULL COMMENT 'admin who deactivated this account, if applicable',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_users_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 2 — CONTENT: categories, lessons, tags
-- ============================================================================

CREATE TABLE `categories` (
  `id`         INT NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100) NOT NULL,
  `slug`       VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `icon`       VARCHAR(50) DEFAULT 'play',
  `sort_order` INT DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tags: many-to-many labeling for filtering/search (schema prepared,
-- admin UI not yet built — see accompanying project notes).
CREATE TABLE `tags` (
  `id`   INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `slug` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tag_name` (`name`),
  UNIQUE KEY `uniq_tag_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lessons` (
  `id`                INT NOT NULL AUTO_INCREMENT,
  `title`             VARCHAR(255) NOT NULL,
  `slug`              VARCHAR(255) NOT NULL,
  `category`          VARCHAR(100) DEFAULT 'General',
  `preview_content`   TEXT DEFAULT NULL,
  `full_content`      LONGTEXT DEFAULT NULL,
  `video_url`         TEXT DEFAULT NULL,
  `video_id`          VARCHAR(50) DEFAULT NULL,
  `sort_order`        INT DEFAULT 0 COMMENT 'renamed from position for sitewide sort_order consistency',
  `video_category_id` INT DEFAULT NULL COMMENT 'FK -> categories.id',
  `access_level`      ENUM('free','premium') DEFAULT 'free',
  `standalone_type`   ENUM('general','featured','quick_read','announcement') DEFAULT NULL COMMENT 'curates where standalone lessons surface',
  `thumbnail`         VARCHAR(255) DEFAULT NULL,
  `duration`          VARCHAR(50) DEFAULT '10 min',
  `is_featured`       TINYINT(1) DEFAULT 0,
  `audio_url`         VARCHAR(255) DEFAULT NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`        TIMESTAMP NULL DEFAULT NULL,
  `deleted_by`        INT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_sort_order` (`sort_order`),
  KEY `idx_video_category` (`video_category_id`),
  KEY `idx_standalone_type` (`standalone_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Downloadable resources / attachments per lesson (schema prepared, admin UI
-- not yet built).
CREATE TABLE `lesson_resources` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `lesson_id`     INT NOT NULL,
  `title`         VARCHAR(255) NOT NULL,
  `resource_type` ENUM('file','link') NOT NULL DEFAULT 'file',
  `url`           VARCHAR(500) NOT NULL,
  `sort_order`    INT DEFAULT 0,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_resource_lesson` (`lesson_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 3 — GUIDES (Learning Paths) & QUIZZES
-- ============================================================================

CREATE TABLE `guide_paths` (
  `id`                      INT NOT NULL AUTO_INCREMENT,
  `title`                   VARCHAR(150) NOT NULL,
  `description`             TEXT DEFAULT NULL,
  `thumbnail`               VARCHAR(255) DEFAULT NULL,
  `slug`                    VARCHAR(150) DEFAULT NULL,
  `difficulty`              ENUM('Beginner','Intermediate','Advanced') DEFAULT 'Beginner',
  `is_active`               TINYINT(1) DEFAULT 1,
  `xp_reward`               INT DEFAULT NULL,
  `certificate_template_id` INT DEFAULT NULL,
  `created_at`              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`              TIMESTAMP NULL DEFAULT NULL,
  `deleted_by`              INT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_guide_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Currently the ACTIVE table backing real guide-taking functionality
-- (guide.php, dashboard progress, admin/guides.php). Flat lesson sequence.
CREATE TABLE `guide_path_lessons` (
  `id`         INT NOT NULL AUTO_INCREMENT,
  `guide_id`   INT NOT NULL,
  `lesson_id`  INT NOT NULL,
  `sort_order` INT DEFAULT 0,
  `xp_reward`  INT DEFAULT NULL COMMENT 'per-lesson XP override for lesson_complete when this lesson is completed as part of this guide; NULL = fall back to xp_rules.lesson_complete',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_guide_lesson` (`guide_id`,`lesson_id`),
  KEY `lesson_id` (`lesson_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `quizzes` (
  `id`           INT NOT NULL AUTO_INCREMENT,
  `title`        VARCHAR(150) NOT NULL,
  `guide_id`     INT DEFAULT NULL COMMENT 'Guide path this quiz belongs to',
  `pass_percent` INT DEFAULT 70,
  `badge_id`     INT DEFAULT NULL COMMENT 'Badge awarded on pass',
  `is_active`    TINYINT(1) DEFAULT 1,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`   TIMESTAMP NULL DEFAULT NULL,
  `deleted_by`   INT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `guide_id` (`guide_id`),
  KEY `badge_id` (`badge_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `quiz_questions` (
  `id`         INT NOT NULL AUTO_INCREMENT,
  `quiz_id`    INT NOT NULL,
  `question`   TEXT NOT NULL,
  `option_a`   VARCHAR(255) NOT NULL,
  `option_b`   VARCHAR(255) NOT NULL,
  `option_c`   VARCHAR(255) NOT NULL,
  `option_d`   VARCHAR(255) NOT NULL,
  `correct`    ENUM('a','b','c','d') NOT NULL,
  `sort_order` INT DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `quiz_id` (`quiz_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `quiz_required_lessons` (
  `id`        INT NOT NULL AUTO_INCREMENT,
  `quiz_id`   INT NOT NULL,
  `lesson_id` INT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_quiz_lesson_req` (`quiz_id`,`lesson_id`),
  KEY `lesson_id` (`lesson_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `quiz_results` (
  `id`       INT NOT NULL AUTO_INCREMENT,
  `user_id`  INT NOT NULL,
  `quiz_id`  INT NOT NULL,
  `score`    INT NOT NULL DEFAULT 0 COMMENT 'Percentage score 0-100',
  `passed`   TINYINT(1) DEFAULT 0,
  `taken_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_quiz` (`user_id`,`quiz_id`),
  KEY `quiz_id` (`quiz_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `quiz_unlock_log` (
  `id`       INT NOT NULL AUTO_INCREMENT,
  `user_id`  INT NOT NULL,
  `quiz_id`  INT NOT NULL,
  `shown_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_quiz_shown` (`user_id`,`quiz_id`),
  KEY `quiz_id` (`quiz_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 4 — BADGES, PROGRESS TRACKING
-- ============================================================================

CREATE TABLE `badges` (
  `id`               INT NOT NULL AUTO_INCREMENT,
  `name`             VARCHAR(100) NOT NULL,
  `description`      VARCHAR(255) DEFAULT '',
  `icon`             VARCHAR(20) DEFAULT '',
  `required_lessons` INT DEFAULT 0 COMMENT 'lessons needed to earn this badge (0 = quiz-only)',
  `quiz_id`          INT DEFAULT NULL COMMENT 'optional: quiz that awards this badge',
  `xp_reward`        INT DEFAULT 0 COMMENT 'per-badge XP override; 0 = use xp_rules badge_earned default',
  `is_active`        TINYINT(1) DEFAULT 1,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`       TIMESTAMP NULL DEFAULT NULL,
  `deleted_by`       INT DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_badges` (
  `id`         INT NOT NULL AUTO_INCREMENT,
  `user_id`    INT NOT NULL,
  `badge_id`   INT NOT NULL,
  `earned_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_badge` (`user_id`,`badge_id`),
  KEY `badge_id` (`badge_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTE: the original schema had a `completed` tinyint column here, always 1
-- and never read by any code (row existence alone signals completion) —
-- deliberately dropped as a genuinely dead column, unlike badges.required_lessons
-- which IS live business logic and was preserved.
CREATE TABLE `user_lesson_progress` (
  `id`           INT NOT NULL AUTO_INCREMENT,
  `user_id`      INT NOT NULL,
  `lesson_id`    INT NOT NULL,
  `completed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_lesson` (`user_id`,`lesson_id`),
  KEY `lesson_id` (`lesson_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 5 — XP, LEVELING, STREAKS
-- ============================================================================

CREATE TABLE `xp_rules` (
  `id`         INT NOT NULL AUTO_INCREMENT,
  `action_key` VARCHAR(50) NOT NULL,
  `label`      VARCHAR(150) NOT NULL,
  `xp_value`   INT NOT NULL DEFAULT 0,
  `is_active`  TINYINT(1) DEFAULT 1,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_action_key` (`action_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ledger: source of truth for XP history and the "Recent Activity" feed.
-- (Final name — superseded the interim "user_xp_log" naming used mid-build.)
CREATE TABLE `user_activity_log` (
  `id`             BIGINT NOT NULL AUTO_INCREMENT,
  `user_id`        INT NOT NULL,
  `action_key`     VARCHAR(50) NOT NULL,
  `reference_type` VARCHAR(50) DEFAULT NULL COMMENT 'lesson, guide, quiz, badge, streak',
  `reference_id`   INT DEFAULT NULL,
  `xp_awarded`     INT NOT NULL DEFAULT 0,
  `metadata`       JSON DEFAULT NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_user_time` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `levels` (
  `id`           INT NOT NULL AUTO_INCREMENT,
  `level_number` INT NOT NULL,
  `title`        VARCHAR(100) NOT NULL,
  `xp_required`  INT NOT NULL,
  `icon`         VARCHAR(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_level_number` (`level_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `streak_milestones` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `days_required` INT NOT NULL,
  `xp_bonus`      INT DEFAULT 0,
  `badge_id`      INT DEFAULT NULL,
  `is_active`     TINYINT(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_days_required` (`days_required`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 6 — CERTIFICATES
-- ============================================================================

CREATE TABLE `certificate_templates` (
  `id`               INT NOT NULL AUTO_INCREMENT,
  `name`             VARCHAR(150) NOT NULL,
  `html_template`    LONGTEXT NOT NULL,
  `background_image` VARCHAR(255) DEFAULT NULL,
  `is_default`       TINYINT(1) DEFAULT 0,
  `is_active`        TINYINT(1) DEFAULT 1,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`       TIMESTAMP NULL DEFAULT NULL,
  `deleted_by`       INT DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `certificates` (
  `id`               INT NOT NULL AUTO_INCREMENT,
  `certificate_code` VARCHAR(30) NOT NULL,
  `user_id`          INT NOT NULL,
  `issuable_type`    ENUM('guide_path') NOT NULL,
  `issuable_id`      INT NOT NULL,
  `template_id`      INT NOT NULL,
  `pdf_path`         VARCHAR(255) DEFAULT NULL,
  `is_revoked`       TINYINT(1) DEFAULT 0,
  `issued_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_certificate_code` (`certificate_code`),
  UNIQUE KEY `uniq_user_issuable` (`user_id`,`issuable_type`,`issuable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 7 — AIRDROPS, BLOG, HOMEPAGE CONTENT
-- ============================================================================

-- NOTE: featured_image existed in the original schema but is referenced by
-- no code anywhere (public pages or admin) — dropped as genuinely dead,
-- same reasoning as user_lesson_progress.completed above.
CREATE TABLE `airdrops` (
  `id`           INT NOT NULL AUTO_INCREMENT,
  `title`        VARCHAR(255) NOT NULL,
  `slug`         VARCHAR(255) DEFAULT NULL,
  `description`  TEXT DEFAULT NULL,
  `full_guide`   LONGTEXT DEFAULT NULL,
  `project_url`  TEXT DEFAULT NULL,
  `reward`       VARCHAR(100) DEFAULT NULL,
  `difficulty`   ENUM('Easy','Medium','Hard') DEFAULT 'Easy',
  `deadline`     DATE DEFAULT NULL,
  `status`       ENUM('active','ended','upcoming') DEFAULT 'active',
  `access_level` ENUM('public','premium') DEFAULT 'public',
  `is_featured`  TINYINT(1) DEFAULT 0,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`   TIMESTAMP NULL DEFAULT NULL,
  `deleted_by`   INT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `blog_posts` (
  `id`          INT NOT NULL AUTO_INCREMENT,
  `title`       VARCHAR(255) NOT NULL,
  `slug`        VARCHAR(255) NOT NULL,
  `excerpt`     VARCHAR(500) DEFAULT NULL,
  `content`     LONGTEXT DEFAULT NULL,
  `cover_image` VARCHAR(255) DEFAULT NULL,
  `author_id`   INT DEFAULT NULL COMMENT 'FK -> admins.id (corrected from the original schema, which pointed to users.id — blog posts are admin-authored)',
  `is_published` TINYINT(1) DEFAULT 0,
  `is_featured`  TINYINT(1) DEFAULT 0,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`   TIMESTAMP NULL DEFAULT NULL,
  `deleted_by`   INT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `hero_headlines` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `headline_text` VARCHAR(255) NOT NULL,
  `sort_order`    INT DEFAULT 0,
  `is_active`     TINYINT(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `crypto_carousel_items` (
  `id`         INT NOT NULL AUTO_INCREMENT,
  `coin_name`  VARCHAR(100) NOT NULL,
  `logo_path`  VARCHAR(255) NOT NULL,
  `sort_order` INT DEFAULT 0,
  `is_active`  TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 8 — PREMIUM PLANS, SUBSCRIPTIONS, PAYMENTS
-- ============================================================================

CREATE TABLE `premium_plans` (
  `id`                  INT NOT NULL AUTO_INCREMENT,
  `name`                VARCHAR(100) NOT NULL,
  `slug`                VARCHAR(50) NOT NULL,
  `billing_cycle`       ENUM('monthly','yearly') NOT NULL,
  `price_kobo`          BIGINT NOT NULL COMMENT 'minor unit (kobo) — matches Paystack native unit',
  `provider_plan_code`  VARCHAR(100) DEFAULT NULL COMMENT 'Paystack plan_code once synced — enables recurring billing',
  `description`         TEXT DEFAULT NULL,
  `is_recommended`      TINYINT(1) DEFAULT 0,
  `is_active`           TINYINT(1) DEFAULT 1,
  `sort_order`          INT DEFAULT 0,
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`          TIMESTAMP NULL DEFAULT NULL,
  `deleted_by`          INT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_plan_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `plan_features` (
  `id`           INT NOT NULL AUTO_INCREMENT,
  `plan_id`      INT NOT NULL,
  `feature_text` VARCHAR(255) NOT NULL,
  `sort_order`   INT DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_pf_plan` (`plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_subscriptions` (
  `id`                          INT NOT NULL AUTO_INCREMENT,
  `user_id`                     INT NOT NULL,
  `plan_id`                     INT NOT NULL,
  `status`                      ENUM('pending','active','cancelled','expired') NOT NULL DEFAULT 'pending',
  `provider`                    VARCHAR(30) NOT NULL DEFAULT 'paystack',
  `provider_customer_code`      VARCHAR(100) DEFAULT NULL,
  `provider_subscription_code`  VARCHAR(100) DEFAULT NULL,
  `provider_authorization_code` VARCHAR(100) DEFAULT NULL,
  `started_at`                  TIMESTAMP NULL DEFAULT NULL,
  `expires_at`                  TIMESTAMP NULL DEFAULT NULL COMMENT 'NULL = permanent/never expires (legacy or admin-granted access)',
  `renews_at`                   TIMESTAMP NULL DEFAULT NULL,
  `cancel_at_period_end`        TINYINT(1) DEFAULT 0,
  `cancelled_at`                TIMESTAMP NULL DEFAULT NULL,
  `created_at`                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sub_user` (`user_id`),
  KEY `idx_sub_status_expiry` (`status`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `purchases` (
  `id`              INT NOT NULL AUTO_INCREMENT,
  `user_id`         INT NOT NULL,
  `plan_id`         INT DEFAULT NULL,
  `subscription_id` INT DEFAULT NULL,
  `provider`        VARCHAR(30) DEFAULT 'paystack',
  `billing_cycle`   ENUM('monthly','yearly') DEFAULT NULL,
  `reference`       VARCHAR(100) NOT NULL,
  `amount`          DECIMAL(10,2) NOT NULL,
  `status`          ENUM('pending','success','failed') DEFAULT 'pending',
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference` (`reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `subscription_reminder_log` (
  `id`              INT NOT NULL AUTO_INCREMENT,
  `subscription_id` INT NOT NULL,
  `reminder_type`   ENUM('7_day','3_day','same_day','expired') NOT NULL,
  `sent_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_reminder` (`subscription_id`,`reminder_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 9 — COMMUNICATIONS: notifications, announcements, email
-- ============================================================================

CREATE TABLE `notifications` (
  `id`         INT NOT NULL AUTO_INCREMENT,
  `user_id`    INT NOT NULL,
  `type`       VARCHAR(50) NOT NULL COMMENT 'certificate_issued, badge_earned, guide_completed, premium_expiring, level_up, admin_message, etc — open-ended by design',
  `title`      VARCHAR(255) NOT NULL,
  `body`       TEXT DEFAULT NULL,
  `link`       VARCHAR(255) DEFAULT NULL,
  `is_read`    TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user_read` (`user_id`,`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `announcements` (
  `id`           INT NOT NULL AUTO_INCREMENT,
  `title`        VARCHAR(255) NOT NULL,
  `body`         TEXT NOT NULL,
  `is_active`    TINYINT(1) DEFAULT 1,
  `published_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at`   TIMESTAMP NULL DEFAULT NULL,
  `deleted_by`   INT DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_announcement_reads` (
  `id`              INT NOT NULL AUTO_INCREMENT,
  `user_id`         INT NOT NULL,
  `announcement_id` INT NOT NULL,
  `read_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_announcement` (`user_id`,`announcement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_templates` (
  `id`             INT NOT NULL AUTO_INCREMENT,
  `template_key`   VARCHAR(100) NOT NULL,
  `subject`        VARCHAR(255) NOT NULL,
  `html_body`      LONGTEXT NOT NULL,
  `variables_hint` TEXT DEFAULT NULL COMMENT 'documents available {{placeholders}} for the admin UI',
  `is_active`      TINYINT(1) DEFAULT 1,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_template_key` (`template_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_queue` (
  `id`           INT NOT NULL AUTO_INCREMENT,
  `to_email`     VARCHAR(255) NOT NULL,
  `template_key` VARCHAR(100) NOT NULL,
  `variables`    JSON DEFAULT NULL,
  `status`       ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempts`     INT DEFAULT 0,
  `last_error`   TEXT DEFAULT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at`      TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_queue_status` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 10 — BOOKMARKS, ANALYTICS
-- ============================================================================

CREATE TABLE `user_bookmarks` (
  `id`                INT NOT NULL AUTO_INCREMENT,
  `user_id`           INT NOT NULL,
  `bookmarkable_type` ENUM('lesson','guide_path','airdrop','blog_post') NOT NULL,
  `bookmarkable_id`   INT NOT NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_bookmark` (`user_id`,`bookmarkable_type`,`bookmarkable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic, schemaless-payload event log (prepared for future analytics —
-- not yet consumed by any admin dashboard).
CREATE TABLE `analytics_events` (
  `id`         BIGINT NOT NULL AUTO_INCREMENT,
  `user_id`    INT DEFAULT NULL COMMENT 'nullable for logged-out visitors',
  `event_name` VARCHAR(100) NOT NULL,
  `properties` JSON DEFAULT NULL,
  `session_id` VARCHAR(100) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_event_name_time` (`event_name`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 11 — AI TUTOR
-- ============================================================================

CREATE TABLE `ai_conversations` (
  `id`         INT NOT NULL AUTO_INCREMENT,
  `user_id`    INT NOT NULL,
  `title`      VARCHAR(255) DEFAULT 'New Conversation',
  `source`     ENUM('page','widget') DEFAULT 'page' COMMENT 'page (full AI Tutor) or widget (floating popup)',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_conv_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ai_messages` (
  `id`              INT NOT NULL AUTO_INCREMENT,
  `conversation_id` INT NOT NULL,
  `role`            ENUM('user','assistant') NOT NULL,
  `content`         MEDIUMTEXT NOT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_msg_conversation` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ai_prompts` (
  `id`         INT NOT NULL AUTO_INCREMENT,
  `prompt_key` VARCHAR(100) NOT NULL,
  `label`      VARCHAR(255) NOT NULL,
  `content`    TEXT NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prompt_key` (`prompt_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ai_usage_log` (
  `id`              INT NOT NULL AUTO_INCREMENT,
  `user_id`         INT DEFAULT NULL,
  `type`            ENUM('chat','study_card') NOT NULL,
  `membership_type` ENUM('free','premium') DEFAULT 'free',
  `provider`        VARCHAR(50) DEFAULT NULL,
  `model`           VARCHAR(100) DEFAULT NULL,
  `tokens_input`    INT DEFAULT 0,
  `tokens_output`   INT DEFAULT 0,
  `cost_estimated`  DECIMAL(10,6) DEFAULT 0.000000,
  `success`         TINYINT(1) DEFAULT 1,
  `error_message`   VARCHAR(500) DEFAULT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_usage_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 12 — MEDIA LIBRARY
-- ============================================================================

-- Governance/reuse-tracking layer for uploads. Content tables keep their own
-- direct path columns (thumbnail, cover_image, etc.) for fast rendering
-- without a join — this table is for the admin media browser and dedup, not
-- the sole source of a file's path.
CREATE TABLE `media_library` (
  `id`             INT NOT NULL AUTO_INCREMENT,
  `filename`       VARCHAR(255) NOT NULL,
  `path`           VARCHAR(500) DEFAULT NULL,
  `original_name`  VARCHAR(255) DEFAULT NULL,
  `mime_type`      VARCHAR(100) DEFAULT NULL,
  `width`          INT DEFAULT NULL,
  `height`         INT DEFAULT NULL,
  `alt_text`       VARCHAR(255) DEFAULT NULL,
  `size`           INT DEFAULT NULL,
  `attachable_type` VARCHAR(50) DEFAULT NULL COMMENT 'lesson, blog, airdrop, avatar, certificate_template, carousel, category, general — open-ended, no enum migration needed for new types',
  `attachable_id`   INT DEFAULT NULL,
  `uploaded_by`    INT DEFAULT NULL COMMENT 'FK -> admins.id',
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`     TIMESTAMP NULL DEFAULT NULL,
  `deleted_by`     INT DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 13 — SYSTEM: settings, maintenance mode
-- ============================================================================

CREATE TABLE `site_settings` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `setting_key`   VARCHAR(100) NOT NULL,
  `setting_val`   TEXT NOT NULL,
  `label`         VARCHAR(255) DEFAULT NULL,
  `description`   TEXT DEFAULT NULL,
  `updated_at`    TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `maintenance_exceptions` (
  `id`         INT NOT NULL AUTO_INCREMENT,
  `user_id`    INT NOT NULL,
  `added_by`   INT DEFAULT NULL COMMENT 'FK -> admins.id',
  `note`       VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 14 — FOREIGN KEY CONSTRAINTS
-- (Applied after every table exists, to avoid ordering issues on import.)
-- ============================================================================

ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL;

ALTER TABLE `admins`
  ADD CONSTRAINT `fk_admins_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL;

ALTER TABLE `lessons`
  ADD CONSTRAINT `fk_lessons_category`   FOREIGN KEY (`video_category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_lessons_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL;

ALTER TABLE `lesson_resources`
  ADD CONSTRAINT `fk_resources_lesson` FOREIGN KEY (`lesson_id`) REFERENCES `lessons`(`id`) ON DELETE CASCADE;

-- Guides
ALTER TABLE `guide_paths`
  ADD CONSTRAINT `fk_guides_cert_tpl`   FOREIGN KEY (`certificate_template_id`) REFERENCES `certificate_templates`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_guides_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL;

ALTER TABLE `guide_path_lessons`
  ADD CONSTRAINT `fk_gpl_guide`  FOREIGN KEY (`guide_id`) REFERENCES `guide_paths`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_gpl_lesson` FOREIGN KEY (`lesson_id`) REFERENCES `lessons`(`id`) ON DELETE CASCADE;

-- Quizzes
ALTER TABLE `quizzes`
  ADD CONSTRAINT `fk_quizzes_guide`      FOREIGN KEY (`guide_id`) REFERENCES `guide_paths`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_quizzes_badge`      FOREIGN KEY (`badge_id`) REFERENCES `badges`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_quizzes_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL;

ALTER TABLE `quiz_questions`
  ADD CONSTRAINT `fk_qq_quiz` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes`(`id`) ON DELETE CASCADE;

ALTER TABLE `quiz_required_lessons`
  ADD CONSTRAINT `fk_qrl_quiz`   FOREIGN KEY (`quiz_id`) REFERENCES `quizzes`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_qrl_lesson` FOREIGN KEY (`lesson_id`) REFERENCES `lessons`(`id`) ON DELETE CASCADE;

ALTER TABLE `quiz_results`
  ADD CONSTRAINT `fk_qr_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_qr_quiz` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes`(`id`) ON DELETE CASCADE;

ALTER TABLE `quiz_unlock_log`
  ADD CONSTRAINT `fk_qul_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_qul_quiz` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes`(`id`) ON DELETE CASCADE;

-- Badges / progress
ALTER TABLE `badges`
  ADD CONSTRAINT `fk_badges_quiz`       FOREIGN KEY (`quiz_id`) REFERENCES `quizzes`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_badges_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL;

ALTER TABLE `user_badges`
  ADD CONSTRAINT `fk_ub_user`  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ub_badge` FOREIGN KEY (`badge_id`) REFERENCES `badges`(`id`) ON DELETE CASCADE;

ALTER TABLE `user_lesson_progress`
  ADD CONSTRAINT `fk_ulp_user`   FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ulp_lesson` FOREIGN KEY (`lesson_id`) REFERENCES `lessons`(`id`) ON DELETE CASCADE;

-- XP / leveling
ALTER TABLE `user_activity_log`
  ADD CONSTRAINT `fk_activity_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;

ALTER TABLE `streak_milestones`
  ADD CONSTRAINT `fk_streak_badge` FOREIGN KEY (`badge_id`) REFERENCES `badges`(`id`) ON DELETE SET NULL;

-- Certificates
ALTER TABLE `certificate_templates`
  ADD CONSTRAINT `fk_ctpl_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL;

ALTER TABLE `certificates`
  ADD CONSTRAINT `fk_cert_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cert_tpl`  FOREIGN KEY (`template_id`) REFERENCES `certificate_templates`(`id`) ON DELETE RESTRICT;
  -- RESTRICT: a template in active use by issued certificates cannot be
  -- deleted, only deactivated (is_active = 0).

-- Airdrops / Blog
ALTER TABLE `airdrops`
  ADD CONSTRAINT `fk_airdrops_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL;

ALTER TABLE `blog_posts`
  ADD CONSTRAINT `fk_blog_author`     FOREIGN KEY (`author_id`) REFERENCES `admins`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_blog_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL;

-- Premium plans / subscriptions / payments
ALTER TABLE `plan_features`
  ADD CONSTRAINT `fk_pf_plan` FOREIGN KEY (`plan_id`) REFERENCES `premium_plans`(`id`) ON DELETE CASCADE;

ALTER TABLE `premium_plans`
  ADD CONSTRAINT `fk_plans_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL;

ALTER TABLE `user_subscriptions`
  ADD CONSTRAINT `fk_sub_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sub_plan` FOREIGN KEY (`plan_id`) REFERENCES `premium_plans`(`id`) ON DELETE RESTRICT;

ALTER TABLE `purchases`
  ADD CONSTRAINT `fk_purchases_user`         FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_purchases_plan`         FOREIGN KEY (`plan_id`) REFERENCES `premium_plans`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_purchases_subscription` FOREIGN KEY (`subscription_id`) REFERENCES `user_subscriptions`(`id`) ON DELETE SET NULL;

ALTER TABLE `subscription_reminder_log`
  ADD CONSTRAINT `fk_reminder_sub` FOREIGN KEY (`subscription_id`) REFERENCES `user_subscriptions`(`id`) ON DELETE CASCADE;

-- Communications
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;

ALTER TABLE `announcements`
  ADD CONSTRAINT `fk_announce_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL;

ALTER TABLE `user_announcement_reads`
  ADD CONSTRAINT `fk_uar_user`         FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_uar_announcement` FOREIGN KEY (`announcement_id`) REFERENCES `announcements`(`id`) ON DELETE CASCADE;

-- Bookmarks / analytics
ALTER TABLE `user_bookmarks`
  ADD CONSTRAINT `fk_bookmark_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;

ALTER TABLE `analytics_events`
  ADD CONSTRAINT `fk_analytics_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL;

-- AI Tutor
ALTER TABLE `ai_conversations`
  ADD CONSTRAINT `fk_aiconv_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;

ALTER TABLE `ai_messages`
  ADD CONSTRAINT `fk_aimsg_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations`(`id`) ON DELETE CASCADE;

ALTER TABLE `ai_usage_log`
  ADD CONSTRAINT `fk_aiusage_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL;

-- Media / system
ALTER TABLE `media_library`
  ADD CONSTRAINT `fk_media_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_media_deleted_by`  FOREIGN KEY (`deleted_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL;

ALTER TABLE `maintenance_exceptions`
  ADD CONSTRAINT `fk_maint_user`     FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_maint_added_by` FOREIGN KEY (`added_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL;

-- ============================================================================
-- SECTION 15 — DEFAULT / SEED DATA
-- (Configuration and defaults only — no sample content, users, or admin
-- credentials. This is a fresh-install baseline, not a data migration.)
-- ============================================================================

-- ---- XP rules (admin-configurable via the future XP settings page) ----
INSERT INTO `xp_rules` (`action_key`, `label`, `xp_value`) VALUES
('lesson_complete', 'Lesson Completed', 10),
('guide_complete',  'Learning Path Completed', 100),
('quiz_pass',       'Quiz Passed', 25),
('badge_earned',    'Badge Earned (default)', 20),
('daily_streak',    'Daily Login Streak', 5);

-- ---- Level curve ----
INSERT INTO `levels` (`level_number`, `title`, `xp_required`) VALUES
(1, 'Newcomer',    0),
(2, 'Learner',     100),
(3, 'Explorer',    300),
(4, 'Builder',     700),
(5, 'Web3 Adept',  1500),
(6, 'Chain Master',3000),
(7, 'Web3 Sage',   6000);

-- ---- Streak milestone bonuses ----
INSERT INTO `streak_milestones` (`days_required`, `xp_bonus`) VALUES
(7, 50), (30, 200), (100, 750);

-- ---- Hero rotating headlines ----
INSERT INTO `hero_headlines` (`headline_text`, `sort_order`) VALUES
('Learn Web3 & Blockchain', 1),
('Learn Crypto Trading', 2),
('Create Your First Wallet', 3),
('Create Your First Memecoin', 4),
('Find Airdrop Opportunities', 5),
('Master DeFi', 6),
('Follow Structured Learning Paths', 7);

-- ---- Premium plans (edit prices/features anytime via admin/premium-plans.php) ----
INSERT INTO `premium_plans` (`name`, `slug`, `billing_cycle`, `price_kobo`, `description`, `is_recommended`, `sort_order`) VALUES
('Monthly',  'monthly',  'monthly',  300000,   'Full premium access, billed every month.', 0, 1),
('Yearly',   'yearly',   'yearly',   3000000,  'Full premium access, billed every year — save vs monthly.', 1, 2);

INSERT INTO `plan_features` (`plan_id`, `feature_text`, `sort_order`)
SELECT id, f.txt, f.ord FROM premium_plans
JOIN (
  SELECT 1 AS ord, 'All premium lessons and guides' AS txt UNION ALL
  SELECT 2, 'All premium airdrop guides' UNION ALL
  SELECT 3, 'Unlimited AI Tutor conversations' UNION ALL
  SELECT 4, 'Certificates of completion'
) f ON 1=1;

-- ---- Email templates (all 12 supported types) ----
INSERT INTO `email_templates` (`template_key`, `subject`, `html_body`, `variables_hint`, `is_active`) VALUES

('welcome', 'Welcome to Web3Edu, {{username}}! 🎉',
 '<p>Hi {{username}},</p><p>Welcome to Web3Edu! Your account is ready, and a whole curriculum of Web3, blockchain, and DeFi lessons is waiting for you.</p><p><a href="{{site_url}}/learn.php" style="color:#7C3AED;font-weight:600;">Start learning →</a></p>',
 '{{username}}, {{site_url}}', 1),

('verification', 'Verify your Web3Edu email address',
 '<p>Hi {{username}},</p><p>Please confirm your email address to finish setting up your account:</p><p><a href="{{verification_link}}" style="color:#7C3AED;font-weight:600;">Verify my email →</a></p><p>If you didn''t create this account, you can safely ignore this email.</p>',
 '{{username}}, {{verification_link}}', 1),

('forgot_password', 'Reset your Web3Edu password',
 '<p>Hi {{username}},</p><p>We received a request to reset your password. Click below to choose a new one:</p><p><a href="{{reset_link}}" style="color:#7C3AED;font-weight:600;">Reset password →</a></p><p>This link expires in 1 hour. If you didn''t request this, you can ignore this email.</p>',
 '{{username}}, {{reset_link}}', 1),

('password_changed', 'Your Web3Edu password was changed',
 '<p>Hi {{username}},</p><p>Your password was just changed. If this wasn''t you, please contact support immediately.</p>',
 '{{username}}', 1),

('premium_purchase', 'Welcome to Web3Edu Premium! ⭐',
 '<p>Hi {{username}},</p><p>Your <strong>{{plan_name}}</strong> plan is now active — thank you for your purchase of ₦{{amount}}. You now have full access to every premium lesson, guide, and airdrop guide.</p><p><a href="{{site_url}}/dashboard.php" style="color:#7C3AED;font-weight:600;">Go to your dashboard →</a></p>',
 '{{username}}, {{plan_name}}, {{amount}}, {{site_url}}', 1),

('premium_renewed', 'Your Web3Edu {{plan_name}} plan renewed',
 '<p>Hi {{username}},</p><p>Your <strong>{{plan_name}}</strong> plan renewed successfully. Next renewal: {{renewal_date}}.</p>',
 '{{username}}, {{plan_name}}, {{renewal_date}}', 1),

('premium_expiring', 'Your Web3Edu premium access expires in {{days_remaining}} days',
 '<p>Hi {{username}},</p><p>Your <strong>{{plan_name}}</strong> plan expires on {{expiry_date}}. Renew now to keep uninterrupted access to every premium lesson and airdrop guide.</p><p><a href="{{site_url}}/upgrade.php" style="color:#7C3AED;font-weight:600;">Renew now →</a></p>',
 '{{username}}, {{plan_name}}, {{days_remaining}}, {{expiry_date}}, {{site_url}}', 1),

('premium_expired', 'Your Web3Edu premium access has expired',
 '<p>Hi {{username}},</p><p>Your premium access has expired. Your progress is saved — renew anytime to unlock premium content again.</p><p><a href="{{site_url}}/upgrade.php" style="color:#7C3AED;font-weight:600;">View plans →</a></p>',
 '{{username}}, {{site_url}}', 1),

('guide_completion', 'You completed {{title}}! 🎉',
 '<p>Hi {{username}},</p><p>Congratulations — you''ve completed <strong>{{title}}</strong>! Your certificate is ready to view and download.</p><p><a href="{{site_url}}/certificates.php" style="color:#7C3AED;font-weight:600;">View your certificate →</a></p>',
 '{{username}}, {{title}}, {{site_url}}', 1),

('certificate', 'Your certificate for {{title}} is ready 🎓',
 '<p>Hi {{username}},</p><p>Your certificate for completing <strong>{{title}}</strong> has been issued.</p><p>Certificate code: <strong>{{certificate_code}}</strong><br>Issued: {{date}}</p><p><a href="{{site_url}}/certificates.php" style="color:#7C3AED;font-weight:600;">View & download →</a></p>',
 '{{username}}, {{title}}, {{certificate_code}}, {{date}}, {{site_url}}', 1),

('announcement', '{{title}}',
 '<p>Hi {{username}},</p><p>{{body}}</p>',
 '{{username}}, {{title}}, {{body}}', 1),

('general_notification', '{{subject}}',
 '<p>Hi {{username}},</p><p>{{message}}</p>',
 '{{username}}, {{subject}}, {{message}}', 1);

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;

-- ============================================================================
-- END OF FILE — database/web3edu_v2.sql
-- Import this single file into an empty MySQL database (phpMyAdmin: Import,
-- or `mysql -u root -p database_name < web3edu_v2.sql`). After import:
--   1. Run `php database/seed-admin.php` (or visit it in a browser) to create
--      the default admin account with a correctly-hashed password — raw SQL
--      can't call PHP's password_hash(), so this is done via a small PHP
--      seeder rather than a hardcoded hash. Delete/restrict the file after.
--   2. Set PAYSTACK_SECRET_KEY / PAYSTACK_PUBLIC_KEY and RESEND_API_KEY in
--      includes/config.php (or move them to environment variables).
--   3. Create at least one certificate_templates row and mark it is_default=1
--      before guide completions try to issue certificates.
--   4. Schedule cron/subscription-cron.php and cron/email-cron.php.
-- ============================================================================
