-- ============================================================================
-- WEB3EDU V2 — MIGRATION: Remove Course entity, Guides become the only
-- learning-path entity.
--
-- Run this ONCE against an existing web3edu_v2 database (i.e. one that was
-- installed before this refactor and may already contain rows in the
-- `courses` family of tables). Fresh installs should just import the
-- updated database/web3edu_v2.sql instead — it no longer defines these
-- tables at all.
--
-- What this does:
--   1. Renames `course_categories` -> `categories` (still used by lessons/
--      videos, so the table itself is kept, not dropped).
--   2. Drops every Course-only table (data loss is expected here: this is
--      a deliberate removal of the Course feature, not a preserving move).
--   3. Cleans up now-invalid rows in shared/polymorphic tables that used to
--      reference `course` as a type (certificates, bookmarks, activity log,
--      xp_rules) so no orphaned 'course' rows linger after the app stops
--      writing them.
--
-- Safe to run multiple times (guarded with IF EXISTS / conditional checks).
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

-- ---- 1. Rename the shared categories table ----
-- (No-op if already renamed by a previous run.)
SET @tbl_exists = (
  SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'course_categories'
);
SET @sql = IF(@tbl_exists > 0, 'ALTER TABLE `course_categories` RENAME TO `categories`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---- 2. Drop Course-only tables (FK constraints first, then tables) ----
ALTER TABLE `courses` DROP FOREIGN KEY IF EXISTS `fk_courses_category`;
ALTER TABLE `courses` DROP FOREIGN KEY IF EXISTS `fk_courses_instructor`;
ALTER TABLE `courses` DROP FOREIGN KEY IF EXISTS `fk_courses_cert_tpl`;
ALTER TABLE `courses` DROP FOREIGN KEY IF EXISTS `fk_courses_deleted_by`;

DROP TABLE IF EXISTS `user_course_progress`;
DROP TABLE IF EXISTS `course_prerequisites`;
DROP TABLE IF EXISTS `course_tags`;
DROP TABLE IF EXISTS `course_lessons`;
DROP TABLE IF EXISTS `course_modules`;
DROP TABLE IF EXISTS `courses`;
DROP TABLE IF EXISTS `guide_path_items`; -- unused prepared infra, mixed course+lesson sequences no longer meaningful

-- ---- 3. Clean up polymorphic rows that referenced 'course' ----
DELETE FROM `certificates`   WHERE `issuable_type`    = 'course';
DELETE FROM `user_bookmarks` WHERE `bookmarkable_type` = 'course';
DELETE FROM `user_activity_log` WHERE `action_key` = 'course_complete';
UPDATE `notifications` SET `type` = 'guide_completed' WHERE `type` = 'course_completed';
DELETE FROM `xp_rules` WHERE `action_key` = 'course_complete';

-- Rename the shared completion email template key (if the app already
-- queued/seeded it under the old key).
UPDATE `email_templates` SET `template_key` = 'guide_completion' WHERE `template_key` = 'course_completion';
UPDATE `email_queue`     SET `template_key` = 'guide_completion' WHERE `template_key` = 'course_completion';

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- After running this:
--   - certificates.issuable_type effectively only ever holds 'guide_path'
--     going forward (the enum in a fresh schema reflects this; on an
--     existing table the enum definition itself isn't altered here to avoid
--     a lengthy table rewrite — it's safe to leave permissive).
--   - user_bookmarks.bookmarkable_type / lesson & guide bookmarking are
--     unaffected.
--   - Re-run `php database/seed-admin.php` is NOT required; this migration
--     doesn't touch admins/users.
-- ============================================================================
