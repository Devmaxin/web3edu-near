-- ============================================================================
-- WEB3EDU — MIGRATION: Certificate Management System v2
--
-- Run this ONCE against the existing web3edu_v2 database (phpMyAdmin ->
-- your database -> SQL tab -> paste -> Go). It only ADDS tables/columns —
-- nothing existing is dropped, renamed, or has its data touched, so every
-- certificate already issued keeps working exactly as before.
--
-- What this does:
--   1. Creates `certificate_types`      — the catalog of certificate kinds
--      (Course Completion, Guide Completion, Event, etc). Seeded with 8
--      starter types; admins can add more from Admin -> Certificate Types.
--   2. Creates `certificate_backgrounds`— uploaded PNG/JPG/SVG background
--      library, reusable across templates.
--   3. Creates `certificate_rules`     — "award this certificate when..."
--      automation rules (quiz score, XP level, badge earned, premium
--      purchased, custom event). Guide-completion stays driven by the
--      guide's own settings (see #5) rather than a rule row, since every
--      guide already has a natural 1:1 relationship with its certificate.
--   4. Creates `certificate_verifications` and `certificate_downloads`     —
--      append-only logs so the admin dashboard can show real verification/
--      download activity per certificate.
--   5. Creates `certificate_sequences` — powers human-readable certificate
--      IDs like WEB3-2026-000012 (one counter per type+year).
--   6. Expands `certificate_templates` with the certificate-builder fields
--      (subtitle, body text, signatures, QR toggle, background, etc) and
--      loosens `html_template` to optional (advanced/custom-HTML mode).
--   7. Expands `certificates` with type/rule linkage, expiry, revoke
--      metadata, and a JSON metadata bag — and widens `issuable_type` from
--      a 1-value ENUM to a VARCHAR so future issuable kinds (courses,
--      challenges, events...) never require another schema migration.
--   8. Expands `guide_paths` with per-guide certificate rules (minimum
--      completion %, minimum quiz score, require final quiz, require
--      premium, allow re-download, expiration).
--   9. Backfills existing certificate_templates/certificates rows onto the
--      new "Guide / Learning Path Completion" type so nothing already
--      issued ever looks unmigrated.
--
-- Safe to run multiple times — every step is guarded (CREATE TABLE IF NOT
-- EXISTS, or an information_schema existence check before ALTER), matching
-- the convention already used by migrate-xp-levels.sql / migrate-remove-courses.sql.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. certificate_types
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `certificate_types` (
  `id`          INT NOT NULL AUTO_INCREMENT,
  `type_key`    VARCHAR(50) NOT NULL COMMENT 'stable machine key, e.g. guide_completion',
  `label`       VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `icon`        VARCHAR(20) DEFAULT '🎓',
  `id_prefix`   VARCHAR(12) NOT NULL DEFAULT 'WEB3' COMMENT 'prefix used in generated certificate IDs for this type',
  `is_active`   TINYINT(1) DEFAULT 1,
  `sort_order`  INT DEFAULT 0,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`  TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_type_key` (`type_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. certificate_backgrounds
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `certificate_backgrounds` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(150) DEFAULT NULL,
  `path`          VARCHAR(255) NOT NULL COMMENT 'relative to uploads/, e.g. certificates/bg_123.svg',
  `mime_type`     VARCHAR(100) DEFAULT NULL,
  `is_svg`        TINYINT(1) DEFAULT 0,
  `uploaded_by`   INT DEFAULT NULL COMMENT 'FK -> admins.id',
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at`    TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. certificate_rules — automatic award rules
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `certificate_rules` (
  `id`                  INT NOT NULL AUTO_INCREMENT,
  `name`                VARCHAR(150) NOT NULL,
  `certificate_type_id` INT NOT NULL,
  `template_id`         INT DEFAULT NULL COMMENT 'NULL = use the type default template at issue time',
  `trigger_type`        VARCHAR(30) NOT NULL COMMENT 'quiz_score | xp_level | badge_earned | premium_active | custom_event',
  `trigger_config`      JSON DEFAULT NULL COMMENT 'e.g. {"quiz_id":3,"min_score":80} or {"level_number":5} or {"badge_id":2} or {"event_key":"hackathon_2026"}',
  `issuable_type`       VARCHAR(50) NOT NULL DEFAULT 'rule' COMMENT 'stored on the issued certificate as certificates.issuable_type',
  `is_active`           TINYINT(1) DEFAULT 1,
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`          TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rule_trigger` (`trigger_type`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. certificate_verifications / certificate_downloads — activity logs
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `certificate_verifications` (
  `id`             BIGINT NOT NULL AUTO_INCREMENT,
  `certificate_id` INT NOT NULL,
  `ip_address`     VARCHAR(64) DEFAULT NULL,
  `user_agent`     VARCHAR(255) DEFAULT NULL,
  `verified_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_verif_cert` (`certificate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `certificate_downloads` (
  `id`             BIGINT NOT NULL AUTO_INCREMENT,
  `certificate_id` INT NOT NULL,
  `user_id`        INT DEFAULT NULL,
  `format`         VARCHAR(10) DEFAULT 'pdf',
  `ip_address`     VARCHAR(64) DEFAULT NULL,
  `downloaded_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dl_cert` (`certificate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. certificate_sequences — per type+year counters for WEB3-2026-000012 IDs
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `certificate_sequences` (
  `id`         INT NOT NULL AUTO_INCREMENT,
  `seq_key`    VARCHAR(40) NOT NULL COMMENT 'e.g. WEB3-2026',
  `next_value` INT NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_seq_key` (`seq_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. Expand certificate_templates
-- ----------------------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificate_templates' AND column_name = 'certificate_type_id'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificate_templates` ADD COLUMN `certificate_type_id` INT DEFAULT NULL AFTER `name`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificate_templates' AND column_name = 'render_mode'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificate_templates` ADD COLUMN `render_mode` VARCHAR(20) NOT NULL DEFAULT ''builder'' COMMENT ''builder or custom_html'' AFTER `certificate_type_id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificate_templates' AND column_name = 'subtitle'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificate_templates` ADD COLUMN `subtitle` VARCHAR(255) DEFAULT NULL AFTER `render_mode`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificate_templates' AND column_name = 'body_text'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificate_templates` ADD COLUMN `body_text` TEXT DEFAULT NULL AFTER `subtitle`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificate_templates' AND column_name = 'footer_text'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificate_templates` ADD COLUMN `footer_text` VARCHAR(255) DEFAULT NULL AFTER `body_text`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificate_templates' AND column_name = 'signature1_name'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificate_templates` ADD COLUMN `signature1_name` VARCHAR(100) DEFAULT NULL AFTER `footer_text`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificate_templates' AND column_name = 'signature1_title'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificate_templates` ADD COLUMN `signature1_title` VARCHAR(100) DEFAULT NULL AFTER `signature1_name`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificate_templates' AND column_name = 'signature2_name'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificate_templates` ADD COLUMN `signature2_name` VARCHAR(100) DEFAULT NULL AFTER `signature1_title`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificate_templates' AND column_name = 'signature2_title'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificate_templates` ADD COLUMN `signature2_title` VARCHAR(100) DEFAULT NULL AFTER `signature2_name`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificate_templates' AND column_name = 'issue_date_format'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificate_templates` ADD COLUMN `issue_date_format` VARCHAR(30) NOT NULL DEFAULT ''F j, Y'' AFTER `signature2_title`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificate_templates' AND column_name = 'show_qr'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificate_templates` ADD COLUMN `show_qr` TINYINT(1) DEFAULT 1 AFTER `issue_date_format`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificate_templates' AND column_name = 'verification_url_override'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificate_templates` ADD COLUMN `verification_url_override` VARCHAR(255) DEFAULT NULL AFTER `show_qr`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificate_templates' AND column_name = 'site_logo_override'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificate_templates` ADD COLUMN `site_logo_override` VARCHAR(255) DEFAULT NULL AFTER `verification_url_override`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificate_templates' AND column_name = 'background_id'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificate_templates` ADD COLUMN `background_id` INT DEFAULT NULL AFTER `background_image`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificate_templates' AND column_name = 'orientation'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificate_templates` ADD COLUMN `orientation` VARCHAR(12) NOT NULL DEFAULT ''landscape'' AFTER `background_id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- html_template used to be required (custom-HTML-only design). Builder mode
-- doesn't need it, so widen it to optional. Guarded so re-running is a no-op.
SET @nullable = (
  SELECT IS_NULLABLE FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificate_templates' AND column_name = 'html_template'
);
SET @sql = IF(@nullable = 'NO', 'ALTER TABLE `certificate_templates` MODIFY COLUMN `html_template` LONGTEXT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 7. Expand certificates
-- ----------------------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificates' AND column_name = 'certificate_type_id'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificates` ADD COLUMN `certificate_type_id` INT DEFAULT NULL AFTER `issuable_id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificates' AND column_name = 'rule_id'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificates` ADD COLUMN `rule_id` INT DEFAULT NULL AFTER `certificate_type_id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificates' AND column_name = 'reissued_from_id'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificates` ADD COLUMN `reissued_from_id` INT DEFAULT NULL COMMENT ''original certificate this replaced, if any'' AFTER `rule_id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificates' AND column_name = 'achievement_title'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificates` ADD COLUMN `achievement_title` VARCHAR(255) DEFAULT NULL AFTER `template_id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificates' AND column_name = 'metadata'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificates` ADD COLUMN `metadata` JSON DEFAULT NULL AFTER `achievement_title`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificates' AND column_name = 'expires_at'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificates` ADD COLUMN `expires_at` TIMESTAMP NULL DEFAULT NULL AFTER `issued_at`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificates' AND column_name = 'revoke_reason'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificates` ADD COLUMN `revoke_reason` VARCHAR(255) DEFAULT NULL AFTER `is_revoked`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificates' AND column_name = 'revoked_at'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificates` ADD COLUMN `revoked_at` TIMESTAMP NULL DEFAULT NULL AFTER `revoke_reason`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificates' AND column_name = 'revoked_by'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificates` ADD COLUMN `revoked_by` INT DEFAULT NULL AFTER `revoked_at`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificates' AND column_name = 'download_count'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificates` ADD COLUMN `download_count` INT NOT NULL DEFAULT 0 AFTER `pdf_path`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificates' AND column_name = 'last_downloaded_at'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `certificates` ADD COLUMN `last_downloaded_at` TIMESTAMP NULL DEFAULT NULL AFTER `download_count`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- issuable_type: ENUM('guide_path') -> VARCHAR(50), so future issuable kinds
-- (course, quiz, badge, event, manual, rule, ...) never need another migration.
SET @coltype = (
  SELECT DATA_TYPE FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'certificates' AND column_name = 'issuable_type'
);
SET @sql = IF(@coltype = 'enum', "ALTER TABLE `certificates` MODIFY COLUMN `issuable_type` VARCHAR(50) NOT NULL", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- The original schema enforced "one certificate per (user, issuable_type,
-- issuable_id)" with a hard unique key. Reissuing a certificate now keeps
-- the old (revoked) row for history AND inserts a new row for the same
-- issuable, which that hard constraint would block. Idempotency for new
-- issuance is instead enforced in app code (issueCertificateV2() only
-- checks for an existing NON-revoked row), so the hard constraint is
-- dropped here.
SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'certificates' AND index_name = 'uniq_user_issuable'
);
SET @sql = IF(@idx_exists > 0, 'ALTER TABLE `certificates` DROP INDEX `uniq_user_issuable`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Re-add it as a regular (non-unique) index so lookups by (user, issuable)
-- stay fast.
SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'certificates' AND index_name = 'idx_user_issuable'
);
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE `certificates` ADD INDEX `idx_user_issuable` (`user_id`,`issuable_type`,`issuable_id`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 8. Expand guide_paths — per-guide certificate rules
-- ----------------------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'guide_paths' AND column_name = 'certificate_enabled'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `guide_paths` ADD COLUMN `certificate_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `certificate_template_id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'guide_paths' AND column_name = 'min_completion_percent'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `guide_paths` ADD COLUMN `min_completion_percent` INT NOT NULL DEFAULT 100 AFTER `certificate_enabled`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'guide_paths' AND column_name = 'min_quiz_score'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `guide_paths` ADD COLUMN `min_quiz_score` INT DEFAULT NULL AFTER `min_completion_percent`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'guide_paths' AND column_name = 'require_final_quiz'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `guide_paths` ADD COLUMN `require_final_quiz` TINYINT(1) NOT NULL DEFAULT 0 AFTER `min_quiz_score`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'guide_paths' AND column_name = 'require_premium'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `guide_paths` ADD COLUMN `require_premium` TINYINT(1) NOT NULL DEFAULT 0 AFTER `require_final_quiz`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'guide_paths' AND column_name = 'allow_redownload'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `guide_paths` ADD COLUMN `allow_redownload` TINYINT(1) NOT NULL DEFAULT 1 AFTER `require_premium`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'guide_paths' AND column_name = 'certificate_expires_days'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `guide_paths` ADD COLUMN `certificate_expires_days` INT DEFAULT NULL AFTER `allow_redownload`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 9. Seed certificate types (idempotent — INSERT IGNORE on unique type_key)
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `certificate_types` (`type_key`, `label`, `description`, `icon`, `id_prefix`, `sort_order`) VALUES
('guide_completion',       'Learning Path / Guide Completion', 'Awarded when a user finishes every lesson in a guide path.', '🎓', 'WEB3', 10),
('course_completion',      'Course Completion',                'Awarded when a user finishes a full course.',                 '📘', 'WEB3', 20),
('premium_course',         'Premium Course Certificate',       'Awarded for completing premium-only content.',                '💎', 'WEB3', 30),
('challenge_completion',   'Challenge Completion',             'Awarded for completing a timed or scored challenge.',         '🏆', 'WEB3', 40),
('special_achievement',    'Special Achievement',              'Manually awarded for a standout achievement.',                '⭐', 'WEB3', 50),
('event_certificate',      'Event Certificate',                'Awarded for attending or completing an event.',               '📅', 'WEB3', 60),
('community_contribution', 'Community Contribution',           'Awarded for a notable community contribution.',               '🤝', 'WEB3', 70),
('quiz_mastery',           'Quiz / Assessment Mastery',        'Awarded for scoring above a threshold on a quiz.',            '📝', 'WEB3', 80);

-- ----------------------------------------------------------------------------
-- 10. Backfill: point every existing template/certificate at the
--     "Learning Path / Guide Completion" type so nothing looks unmigrated.
-- ----------------------------------------------------------------------------
SET @guide_type_id = (SELECT id FROM certificate_types WHERE type_key = 'guide_completion' LIMIT 1);

UPDATE `certificate_templates` SET `certificate_type_id` = @guide_type_id WHERE `certificate_type_id` IS NULL;
UPDATE `certificate_templates` SET `render_mode` = 'custom_html' WHERE `render_mode` = 'builder' AND `html_template` IS NOT NULL AND `html_template` != '';
UPDATE `certificates` SET `certificate_type_id` = @guide_type_id WHERE `certificate_type_id` IS NULL;

-- Existing rows' issuable_type is already the literal string 'guide_path' —
-- nothing to rewrite there.

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- Done. Next steps:
--   1. Visit Admin -> Certificates -> Templates and review the auto-migrated
--      default template (it now carries certificate_type_id = "Learning
--      Path / Guide Completion" and keeps rendering exactly as before via
--      render_mode = custom_html).
--   2. Visit Admin -> Guides -> edit any guide to see the new certificate
--      settings panel (minimum completion %, minimum quiz score, require
--      premium, allow re-download, expiration).
--   3. Visit Admin -> Certificates -> Rules to set up automatic awards for
--      quiz scores, XP levels, badges, and premium purchases.
-- ============================================================================
