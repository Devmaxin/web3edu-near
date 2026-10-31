-- ============================================================================
-- WEB3EDU — MIGRATION: Gamification Phase 3 (Admin Milestone System)
--
-- Run this ONCE against the live database (after Phase 1 and Phase 2's
-- migrations). Safe to run multiple times — every statement is guarded.
--
-- Deliberately zero ALTER statements against any existing table. Every
-- requirement type reads live from tables already confirmed healthy on
-- the live DB (users, badges, user_badges, user_lesson_progress,
-- quiz_results, guide_paths, career_paths, user_career_paths) — nothing
-- here duplicates the XP ledger, the badge system, or any completion
-- tracking those tables already do.
--
-- user_milestones is a COMPLETION RECORD ONLY (one row per user per
-- milestone, the moment every requirement is first satisfied) — it is
-- never updated with partial progress. Progress toward an incomplete
-- milestone is always computed live from the source tables above by
-- getMilestoneProgress() in includes/gamification.php, so a milestone
-- added after users already have qualifying history is recognized
-- correctly the first time it's evaluated for them — there's no
-- backfill step because there's nothing to backfill.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `milestones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `slug` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(20) DEFAULT NULL,
  `point_reward` int(11) NOT NULL DEFAULT 0,
  `badge_id` int(11) DEFAULT NULL COMMENT 'awarded via the existing user_badges table on completion, if set',
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_slug` (`slug`),
  KEY `fk_milestones_badge` (`badge_id`),
  CONSTRAINT `fk_milestones_badge` FOREIGN KEY (`badge_id`) REFERENCES `badges` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `milestone_requirements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `milestone_id` int(11) NOT NULL,
  `requirement_type` varchar(40) NOT NULL COMMENT 'lesson_complete|lesson_count|guide_complete|guide_count|quiz_pass|quiz_count|career_path_complete|points_earned|streak_achieved|badge_earned — VARCHAR (not ENUM) so new types can be composed without a schema change; a genuinely new TYPE still needs one evaluator case in evaluateMilestoneRequirement()',
  `target_id` int(11) DEFAULT NULL COMMENT 'specific lesson/guide/quiz/career_path/badge id — used by the *_complete/*_pass/badge_earned types',
  `target_count` int(11) DEFAULT NULL COMMENT 'threshold — used by the *_count/points_earned/streak_achieved types',
  `label` varchar(150) DEFAULT NULL COMMENT 'admin-facing override text for the progress UI, e.g. "Complete Module 1"; auto-generated from the type+target if left blank',
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_requirements_milestone` (`milestone_id`),
  CONSTRAINT `fk_requirements_milestone` FOREIGN KEY (`milestone_id`) REFERENCES `milestones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_milestones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `milestone_id` int(11) NOT NULL,
  `completed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_milestone` (`user_id`, `milestone_id`) COMMENT 'the idempotency guard — INSERT IGNORE here either wins exactly once or silently no-ops, even under concurrent/replayed calls',
  KEY `fk_user_milestones_milestone` (`milestone_id`),
  CONSTRAINT `fk_user_milestones_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_milestones_milestone` FOREIGN KEY (`milestone_id`) REFERENCES `milestones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
