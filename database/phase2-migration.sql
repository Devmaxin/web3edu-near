-- ============================================================================
-- PHASE 2 — Learning by Doing
-- Projects, Submissions, Portfolio (computed), Resources, Notes, Highlights,
-- smarter Continue Learning, richer Assessments, Guide Completion Requirements.
--
-- Architecture notes (read before extending further):
--   * guide_paths ALREADY has a full "guide completion rules" system
--     (certificate_enabled, min_completion_percent, min_quiz_score,
--     require_final_quiz, require_premium, allow_redownload,
--     certificate_expires_days) from the certificates-v2 migration. We
--     EXTEND it with require_project / require_assessment rather than
--     inventing a parallel "guide_completion_rules" table.
--   * quizzes/quiz_questions/quiz_results ALREADY are the quiz engine. We
--     extend them (quiz_type, question_type, correct_answers, code_snippet,
--     image_url) so "Assessments" reuse the exact same engine instead of a
--     new one, per the brief's "reuse existing quiz architecture" directive.
--   * user_bookmarks ALREADY is a polymorphic bookmark table. We widen its
--     ENUM (same pattern bounty-marketplace-migration.sql already used to
--     add 'bounty') instead of creating a second bookmarks table.
--   * Portfolio is NOT a stored table. Like Roadmap progress in Phase 1, a
--     user's portfolio is always computed live from project_submissions
--     (status = 'approved') joined through guide_projects / career paths —
--     there is nothing to keep in sync and nothing that can drift.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. projects — reusable, never duplicated. One Project -> many Guides
--    (via guide_projects), same principle as guide_paths -> career_paths.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `projects` (
  `id`                  INT NOT NULL AUTO_INCREMENT,
  `title`               VARCHAR(255) NOT NULL,
  `slug`                VARCHAR(255) DEFAULT NULL,
  `description`         TEXT DEFAULT NULL,
  `project_type`        ENUM('beginner','intermediate','advanced','capstone') NOT NULL DEFAULT 'beginner',
  `estimated_duration`  VARCHAR(50) DEFAULT NULL COMMENT 'free text, e.g. "3-5 hours" — optional per spec',
  `instructions`        LONGTEXT DEFAULT NULL COMMENT 'rich text, CKEditor-authored like lesson content',
  `learning_objectives` TEXT DEFAULT NULL,
  `requirements`        TEXT DEFAULT NULL,
  `deliverables`        TEXT DEFAULT NULL,
  `starter_files_url`   VARCHAR(500) DEFAULT NULL,
  `reference_links`     JSON DEFAULT NULL COMMENT 'array of {label,url} — additional structured links belong in resources/resource_links instead',
  `is_active`           TINYINT(1) DEFAULT 1,
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`          TIMESTAMP NULL DEFAULT NULL,
  `deleted_by`          INT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_project_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. guide_projects — join table, mirrors career_path_guides' shape exactly.
--    Multiple projects may exist inside one guide; projects reorderable;
--    required/optional never blocks the pipeline the way career paths don't.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `guide_projects` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `guide_id`      INT NOT NULL,
  `project_id`    INT NOT NULL,
  `requirement`   ENUM('required','optional') NOT NULL DEFAULT 'required',
  `is_visible`    TINYINT(1) DEFAULT 1,
  `display_order` INT DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_guide_project` (`guide_id`,`project_id`),
  KEY `idx_gp_project` (`project_id`),
  CONSTRAINT `fk_gp_guide` FOREIGN KEY (`guide_id`) REFERENCES `guide_paths` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gp_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. project_submissions — one active submission per (user, project, guide):
--    a project reused across two guides gets two independent submissions,
--    since "done for Guide A" shouldn't silently mark it done for Guide B.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `project_submissions` (
  `id`              INT NOT NULL AUTO_INCREMENT,
  `user_id`         INT NOT NULL,
  `project_id`      INT NOT NULL,
  `guide_id`        INT DEFAULT NULL COMMENT 'which guide context this was submitted under; NULL = submitted outside any guide',
  `github_url`      VARCHAR(500) DEFAULT NULL,
  `live_demo_url`   VARCHAR(500) DEFAULT NULL,
  `video_demo_url`  VARCHAR(500) DEFAULT NULL,
  `notes`           TEXT DEFAULT NULL,
  `file_path`       VARCHAR(500) DEFAULT NULL COMMENT 'optional uploaded file, stored under /uploads',
  `technologies`    VARCHAR(500) DEFAULT NULL COMMENT 'comma-separated tags shown on Portfolio, e.g. "Solidity, Hardhat, React"',
  `status`          ENUM('draft','submitted','needs_revision','approved','rejected') NOT NULL DEFAULT 'draft',
  `submitted_at`    TIMESTAMP NULL DEFAULT NULL,
  `reviewed_at`     TIMESTAMP NULL DEFAULT NULL,
  `reviewed_by`     INT DEFAULT NULL COMMENT 'FK -> admins.id',
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_submission_user_project_guide` (`user_id`,`project_id`,`guide_id`),
  KEY `idx_sub_project` (`project_id`),
  KEY `idx_sub_status` (`status`),
  CONSTRAINT `fk_sub_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sub_guide` FOREIGN KEY (`guide_id`) REFERENCES `guide_paths` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. project_feedback — append-only review history. Lets a future "assign
--    a specific instructor" or multi-round review flow slot in later without
--    changing this table: reviewer identity + decision + note per row.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `project_feedback` (
  `id`             INT NOT NULL AUTO_INCREMENT,
  `submission_id`  INT NOT NULL,
  `admin_id`       INT DEFAULT NULL,
  `decision`       ENUM('approved','rejected','needs_revision') NOT NULL,
  `feedback`       TEXT DEFAULT NULL COMMENT 'visible to the user',
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_feedback_submission` (`submission_id`),
  CONSTRAINT `fk_feedback_submission` FOREIGN KEY (`submission_id`) REFERENCES `project_submissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. resources — general reusable resource library. lesson_resources (Phase 1
--    era, lesson-only) is untouched; this is the broader layer that can
--    attach to lessons, guides, projects, AND career paths via resource_links.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `resources` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `title`         VARCHAR(255) NOT NULL,
  `description`   TEXT DEFAULT NULL,
  `resource_type` ENUM('documentation','github','article','youtube','download','tool','template') NOT NULL DEFAULT 'article',
  `url`           VARCHAR(500) NOT NULL,
  `thumbnail`     VARCHAR(255) DEFAULT NULL,
  `is_active`     TINYINT(1) DEFAULT 1,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `resource_links` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `resource_id`   INT NOT NULL,
  `linkable_type` ENUM('lesson','guide_path','project','career_path') NOT NULL,
  `linkable_id`   INT NOT NULL,
  `display_order` INT DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_resource_link` (`resource_id`,`linkable_type`,`linkable_id`),
  KEY `idx_link_target` (`linkable_type`,`linkable_id`),
  CONSTRAINT `fk_link_resource` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tracks which resources a user has already opened, so the smarter Continue
-- Learning resolver's 'resource' step only ever surfaces something new —
-- without this there'd be no way to tell "seen" from "unseen" resources.
CREATE TABLE IF NOT EXISTS `resource_views` (
  `id`          INT NOT NULL AUTO_INCREMENT,
  `user_id`     INT NOT NULL,
  `resource_id` INT NOT NULL,
  `viewed_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_resource_view` (`user_id`,`resource_id`),
  CONSTRAINT `fk_view_resource` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. lesson_notes — private, one note per (user, lesson), auto-saved/edited
--    in place. Never public, never joined into any public-facing query.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lesson_notes` (
  `id`         INT NOT NULL AUTO_INCREMENT,
  `user_id`    INT NOT NULL,
  `lesson_id`  INT NOT NULL,
  `content`    LONGTEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_note_user_lesson` (`user_id`,`lesson_id`),
  CONSTRAINT `fk_note_lesson` FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7. lesson_highlights — private, many per (user, lesson). Persisted via a
--    stable text anchor (quoted text + character offsets within the
--    rendered lesson content) rather than fragile DOM node paths.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lesson_highlights` (
  `id`               INT NOT NULL AUTO_INCREMENT,
  `user_id`          INT NOT NULL,
  `lesson_id`        INT NOT NULL,
  `highlighted_text` TEXT NOT NULL,
  `start_offset`     INT DEFAULT NULL,
  `end_offset`       INT DEFAULT NULL,
  `color`            VARCHAR(20) NOT NULL DEFAULT 'yellow',
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_highlight_user_lesson` (`user_id`,`lesson_id`),
  CONSTRAINT `fk_highlight_lesson` FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- ADDITIVE EXTENSIONS TO EXISTING TABLES
-- All wrapped in information_schema guards (same defensive pattern used by
-- bounty-marketplace-migration.sql) so this migration is safe to re-run.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 8. guide_paths — extend the EXISTING completion-rules columns with the two
--    new gates. Defaults (0) mean every existing guide's completion behavior
--    is byte-for-byte unchanged until an admin opts in.
-- ----------------------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE() AND table_name = 'guide_paths' AND column_name = 'require_project'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `guide_paths` ADD COLUMN `require_project` TINYINT(1) NOT NULL DEFAULT 0 AFTER `require_final_quiz`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE() AND table_name = 'guide_paths' AND column_name = 'require_assessment'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `guide_paths` ADD COLUMN `require_assessment` TINYINT(1) NOT NULL DEFAULT 0 AFTER `require_project`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 9. quizzes — distinguish a guide's regular Quiz from its Final Assessment.
--    Every existing row defaults to 'quiz', so the two existing fetch sites
--    (guide.php, gamification.php) keep returning exactly what they did
--    before once we add an explicit quiz_type='quiz' filter to them.
-- ----------------------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE() AND table_name = 'quizzes' AND column_name = 'quiz_type'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE `quizzes` ADD COLUMN `quiz_type` ENUM('quiz','assessment') NOT NULL DEFAULT 'quiz' AFTER `title`",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- A guide previously could have at most one quiz row; now it may have one
-- 'quiz' and one 'assessment' row, so the old implicit-uniqueness assumption
-- needs an explicit index (not a hard UNIQUE — standalone/unattached quizzes
-- with guide_id NULL must still be allowed to coexist freely).
SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE table_schema = DATABASE() AND table_name = 'quizzes' AND index_name = 'idx_guide_quiz_type'
);
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE `quizzes` ADD INDEX `idx_guide_quiz_type` (`guide_id`,`quiz_type`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 10. quiz_questions — richer question types. option_a-d/correct stay as the
--     storage for multiple_choice & true_false (unchanged); new types use
--     correct_answers (JSON array of accepted answers) instead.
-- ----------------------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE() AND table_name = 'quiz_questions' AND column_name = 'question_type'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE `quiz_questions` ADD COLUMN `question_type` ENUM('multiple_choice','true_false','multiple_select','short_answer','code','scenario','image') NOT NULL DEFAULT 'multiple_choice' AFTER `quiz_id`",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE() AND table_name = 'quiz_questions' AND column_name = 'correct_answers'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE `quiz_questions` ADD COLUMN `correct_answers` JSON DEFAULT NULL COMMENT 'accepted answers for multiple_select/short_answer/code/scenario' AFTER `correct`",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE() AND table_name = 'quiz_questions' AND column_name = 'code_snippet'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE `quiz_questions` ADD COLUMN `code_snippet` LONGTEXT DEFAULT NULL COMMENT 'prompt code shown for a code question' AFTER `correct_answers`",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE() AND table_name = 'quiz_questions' AND column_name = 'image_url'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE `quiz_questions` ADD COLUMN `image_url` VARCHAR(255) DEFAULT NULL COMMENT 'future-ready image question prompt' AFTER `code_snippet`",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- multiple_choice/true_false/image reuse option_a-d/correct which are
-- NOT NULL today; relax so multiple_select/short_answer/code/scenario rows
-- (which don't use these columns) don't need dummy placeholder values.
ALTER TABLE `quiz_questions`
    MODIFY COLUMN `option_a` VARCHAR(255) DEFAULT NULL,
    MODIFY COLUMN `option_b` VARCHAR(255) DEFAULT NULL,
    MODIFY COLUMN `option_c` VARCHAR(255) DEFAULT NULL,
    MODIFY COLUMN `option_d` VARCHAR(255) DEFAULT NULL,
    MODIFY COLUMN `correct`  ENUM('a','b','c','d') DEFAULT NULL;

-- ----------------------------------------------------------------------------
-- 11. user_bookmarks — widen ENUM to add 'project' and 'resource', same
--     defensive pattern bounty-marketplace-migration.sql used for 'bounty'.
-- ----------------------------------------------------------------------------
SET @col_type = (
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE() AND table_name = 'user_bookmarks' AND column_name = 'bookmarkable_type'
);
SET @sql = IF(@col_type NOT LIKE '%project%',
  "ALTER TABLE `user_bookmarks` MODIFY COLUMN `bookmarkable_type` ENUM('lesson','guide_path','airdrop','blog_post','bounty','project','resource') NOT NULL",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 12. certificate_types — add Career Path Completion (wired this phase) and
--     Project Completion (future-ready per spec, not auto-issued yet).
--     quiz_mastery already exists and doubles as Assessment Completion.
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `certificate_types` (`type_key`, `label`, `description`, `icon`, `id_prefix`, `sort_order`) VALUES
('career_path_completion', 'Career Path Completion', 'Awarded when a user completes every required guide on a Career Path.', '🏅', 'WEB3', 15),
('project_completion',     'Project Completion',     'Future-ready: awarded when a submitted project is approved.',         '🛠️', 'WEB3', 45);

-- ----------------------------------------------------------------------------
-- 13. career_paths already has completion_badge_id / certificate_template_id
--     from Phase 1 (unused until now) — no schema change needed, only the
--     application code in includes/career_paths.php now reads them.
-- ----------------------------------------------------------------------------

-- ============================================================================
-- PHASE 2 POLISH — public portfolio + Checkpoint Quiz distinction
-- ============================================================================

-- Portfolio privacy is per-user metadata, not portfolio content (which stays
-- computed live) — a tiny dedicated table rather than a column bolted onto
-- `users`, so it's optional/additive and easy to reason about.
CREATE TABLE IF NOT EXISTS `user_portfolio_settings` (
  `user_id`    INT NOT NULL,
  `is_public`  TINYINT(1) NOT NULL DEFAULT 0,
  `slug`       VARCHAR(64) DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_portfolio_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- quiz_type gains 'checkpoint': a quiz tied to specific lessons mid-guide via
-- the pre-existing quiz_required_lessons table — distinct from a guide's
-- single gating 'quiz' (require_final_quiz) and its 'assessment'
-- (require_assessment). Existing rows are unaffected (still 'quiz').
SET @col_type = (
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE() AND table_name = 'quizzes' AND column_name = 'quiz_type'
);
SET @sql = IF(@col_type NOT LIKE '%checkpoint%',
  "ALTER TABLE `quizzes` MODIFY COLUMN `quiz_type` ENUM('quiz','checkpoint','assessment') NOT NULL DEFAULT 'quiz'",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
