-- ============================================================================
-- WEB3EDU — MIGRATION: Gamification Phase 2 (Comeback Bonuses + Inactivity Emails)
--
-- Run this ONCE against the existing web3edu_v2 database (after Phase 1's
-- gamification-phase1-migration.sql). Safe to run multiple times.
--
-- What this does:
--   1. Adds `users.last_login_at` — distinct from `last_activity_date`
--      (which only advances on a qualifying LEARNING activity, per
--      updateStreak()). Reminder rules choose which of the two they key
--      off via `comeback_rules.basis`, so "haven't logged in" and
--      "haven't learned" stay genuinely separate signals.
--   2. Creates `comeback_rules` — one admin-editable row per inactivity
--      threshold (3/7/14/30 days seeded). Doubles as the config for BOTH
--      the proactive inactivity email at that threshold (`template_key`,
--      nullable — no email if blank) AND the reward paid out if/when the
--      user actually returns after crossing it (`xp_reward`,
--      `freeze_reward`, both 0 for the 3/7-day "nudge only" tiers).
--      Deliberately one table instead of two — every threshold the admin
--      configures is naturally both "when to nudge" and "what a comeback
--      is worth", so this keeps them in sync by construction.
--   3. Creates `inactivity_reminder_log` — audit trail + idempotency guard
--      for outbound nudge emails. UNIQUE(user_id, rule_id, basis_date)
--      means the same threshold can never double-fire for the same
--      inactivity episode; once the user's basis date moves (i.e. they
--      come back), a future new episode can trigger it again.
--   4. Creates `comeback_reward_log` — same idea for the XP/freeze payout.
--      UNIQUE(user_id, anchor_date) — at most one comeback reward per
--      actual return, regardless of how many thresholds were crossed
--      during that gap (the highest one wins). Since anchor_date is the
--      user's *previous* last_activity_date (a server-computed value that
--      only ever advances via a real completed lesson/quiz/guide), a user
--      cannot re-trigger the same reward by leaving and returning
--      repeatedly — they would need to be genuinely active in between
--      (which advances last_activity_date, producing a new anchor) before
--      a new gap of the same length could ever be measured again.
--   5. Creates `user_notification_preferences` — per-user opt-out for
--      reminder emails (separate from the existing admin-wide event
--      toggles in site_settings via notificationEventCatalog(), which
--      still apply on top of this).
--
-- Safe to run multiple times (every step is guarded).
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. users.last_login_at
-- ---------------------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'last_login_at'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `last_login_at` TIMESTAMP NULL DEFAULT NULL COMMENT ''set on every setUserSession() call — distinct from last_activity_date, which only moves on a qualifying learning activity'' AFTER `last_activity_date`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. comeback_rules
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `comeback_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `days_threshold` int(11) NOT NULL COMMENT 'days inactive before this rule applies',
  `label` varchar(100) NOT NULL,
  `basis` enum('learning_activity','login') NOT NULL DEFAULT 'learning_activity' COMMENT 'which timestamp counts as "activity" for this rule — last_activity_date or last_login_at',
  `template_key` varchar(100) DEFAULT NULL COMMENT 'email_templates.template_key sent when a user crosses this threshold while still away; NULL = no email, reward only',
  `xp_reward` int(11) NOT NULL DEFAULT 0 COMMENT 'paid via the existing xp_rules/awardXp() ledger when the user returns after crossing this threshold',
  `freeze_reward` int(11) NOT NULL DEFAULT 0,
  `badge_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_days_threshold` (`days_threshold`),
  KEY `fk_comeback_rules_badge` (`badge_id`),
  CONSTRAINT `fk_comeback_rules_badge` FOREIGN KEY (`badge_id`) REFERENCES `badges` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `comeback_rules` (`days_threshold`, `label`, `basis`, `template_key`, `xp_reward`, `freeze_reward`, `is_active`) VALUES
  (3,  'Gentle re-engagement',  'learning_activity', 'inactivity_nudge_3day',      0,   0, 1),
  (7,  'Progress-focused nudge','learning_activity', 'inactivity_nudge_7day',      0,   0, 1),
  (14, 'Comeback bonus',        'learning_activity', 'inactivity_comeback_14day', 50,   1, 1),
  (30, 'Big comeback bonus',    'learning_activity', 'inactivity_comeback_30day', 100,  2, 1);

-- ---------------------------------------------------------------------------
-- 3. inactivity_reminder_log
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `inactivity_reminder_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `rule_id` int(11) NOT NULL,
  `basis_date` date NOT NULL COMMENT 'the last_activity_date/last_login_at value being measured against at send time',
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_rule_basis` (`user_id`, `rule_id`, `basis_date`),
  KEY `fk_inactivity_log_rule` (`rule_id`),
  CONSTRAINT `fk_inactivity_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inactivity_log_rule` FOREIGN KEY (`rule_id`) REFERENCES `comeback_rules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 4. comeback_reward_log
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `comeback_reward_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `rule_id` int(11) NOT NULL,
  `anchor_date` date NOT NULL COMMENT 'the user''s last_activity_date immediately before this return',
  `xp_awarded` int(11) NOT NULL DEFAULT 0,
  `freeze_awarded` int(11) NOT NULL DEFAULT 0,
  `awarded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_anchor` (`user_id`, `anchor_date`),
  KEY `fk_comeback_reward_rule` (`rule_id`),
  CONSTRAINT `fk_comeback_reward_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comeback_reward_rule` FOREIGN KEY (`rule_id`) REFERENCES `comeback_rules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 5. user_notification_preferences
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_notification_preferences` (
  `user_id` int(11) NOT NULL,
  `inactivity_reminders` tinyint(1) NOT NULL DEFAULT 1,
  `achievement_notifications` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_notification_prefs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 6. email_templates seed (light HTML, wrapped by renderEmailLayout() same as every other template)
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `email_templates` (`template_key`, `subject`, `html_body`, `variables_hint`, `is_active`) VALUES
(
  'inactivity_nudge_3day',
  'We miss you at Web3Edu, {{username}}',
  '<p>Hi {{username}},</p><p>It''s been a few days since your last lesson. Your {{total_xp}} XP and progress are right where you left them.</p><p><a href="{{site_url}}/dashboard.php" style="color:#7C3AED;font-weight:600;">Pick up where you left off &rarr;</a></p>',
  'username, total_xp, site_url',
  1
),
(
  'inactivity_nudge_7day',
  'Your Web3Edu progress is waiting, {{username}}',
  '<p>Hi {{username}},</p><p>You''re {{completed_lessons}} lessons in with {{total_xp}} XP so far. A week away is a good time to jump back in before momentum fades.</p><p><a href="{{site_url}}/dashboard.php" style="color:#7C3AED;font-weight:600;">Continue learning &rarr;</a></p>',
  'username, total_xp, completed_lessons, site_url',
  1
),
(
  'inactivity_comeback_14day',
  'A comeback bonus is waiting for you, {{username}}',
  '<p>Hi {{username}},</p><p>Come back and finish any lesson, quiz, or guide within the next few days and we''ll add <strong>+{{xp_reward}} XP</strong>{{freeze_line}} to your account as a welcome-back bonus.</p><p><a href="{{site_url}}/dashboard.php" style="color:#7C3AED;font-weight:600;">Claim your comeback bonus &rarr;</a></p>',
  'username, xp_reward, freeze_line, site_url',
  1
),
(
  'inactivity_comeback_30day',
  '{{username}}, your Web3Edu account still has {{total_xp}} XP waiting',
  '<p>Hi {{username}},</p><p>It''s been a month — your account, progress, and {{total_xp}} XP are still here. Come back and complete any lesson, quiz, or guide and we''ll add <strong>+{{xp_reward}} XP</strong>{{freeze_line}} to welcome you back.</p><p><a href="{{site_url}}/dashboard.php" style="color:#7C3AED;font-weight:600;">Start again &rarr;</a></p>',
  'username, xp_reward, freeze_line, total_xp, site_url',
  1
);
