-- ============================================================================
-- PHASE 3 (cont'd) — AI Mentor extension
-- Reuses the existing AIService/AIServiceFactory/PromptManager/ai_usage_log
-- architecture as-is. Adds only what genuinely doesn't exist yet: a place to
-- store the AI's preliminary project feedback, and a small per-day cache so
-- Study Plan / Daily Recommendation / Motivation don't call the AI provider
-- on every single dashboard load.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- Preliminary AI feedback on a project submission — supplementary to, and
-- stored separately from, project_feedback (which stays 100% human review
-- history via reviewProjectSubmission()). Nullable, additive.
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='project_submissions' AND column_name='ai_review');
SET @sql = IF(@col_exists=0, 'ALTER TABLE `project_submissions` ADD COLUMN `ai_review` TEXT DEFAULT NULL AFTER `notes`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='project_submissions' AND column_name='ai_reviewed_at');
SET @sql = IF(@col_exists=0, 'ALTER TABLE `project_submissions` ADD COLUMN `ai_reviewed_at` TIMESTAMP NULL DEFAULT NULL AFTER `ai_review`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Daily-cached AI insights (Study Plan / Daily Recommendation / Motivation) —
-- regenerated at most once per calendar day per user per type, so the
-- Dashboard never triggers a live AI call on ordinary page loads.
CREATE TABLE IF NOT EXISTS `ai_daily_insights` (
  `id`             INT NOT NULL AUTO_INCREMENT,
  `user_id`        INT NOT NULL,
  `insight_type`   ENUM('study_plan','daily_recommendation','motivation') NOT NULL,
  `content`        TEXT NOT NULL,
  `generated_date` DATE NOT NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_insight_user_type_date` (`user_id`,`insight_type`,`generated_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- New editable prompts — same ai_prompts table + admin Prompt Management UI
-- every existing prompt already uses (PromptManager::all() lists these
-- automatically, no admin UI changes needed).
INSERT IGNORE INTO `ai_prompts` (`prompt_key`, `label`, `content`) VALUES
('ai_mentor_study_plan', 'AI Mentor — Study Plan',
 'You are the Web3Edu AI Mentor. Using the learner profile provided, generate a short, personalized study plan for the next few days. Reference their actual current career path, guide, and progress by name. Be specific and encouraging, not generic. Keep it under 150 words, formatted as a short numbered list of 3-5 concrete next actions.'),
('ai_mentor_daily_recommendation', 'AI Mentor — Daily Recommendation',
 'You are the Web3Edu AI Mentor. Using the learner profile provided, suggest exactly 3 short, specific actions for today (e.g. "Continue [lesson name]", "Retry [quiz name]", "Finish your [project name] submission"). One line each, no preamble, no explanation — just the 3 recommendations.'),
('ai_mentor_motivation', 'AI Mentor — Motivational Message',
 'You are the Web3Edu AI Mentor. Using the learner profile provided, write ONE short, genuine, specific motivational sentence (not generic platitudes) referencing their actual progress, streak, or recent achievement. Under 30 words. No emoji spam — at most one.'),
('ai_mentor_quiz_review', 'AI Mentor — Quiz Mistake Review',
 'You are the Web3Edu AI Mentor. The learner just failed a quiz. For each question they got wrong, briefly explain why their answer was incorrect and what the right concept is, in plain simple language. Then suggest ONE specific lesson or topic to review. Keep the whole response under 180 words. Be encouraging, not discouraging.'),
('ai_mentor_project_review', 'AI Mentor — Project Submission Review',
 'You are the Web3Edu AI Mentor providing PRELIMINARY, SUPPLEMENTARY feedback on a project submission before a human reviewer looks at it. Be constructive and specific. Comment on what seems strong and what could be improved, based on the submission notes/links/technologies provided. Make clear this is AI feedback, not a final decision — a human will still review it. Keep it under 150 words.');
