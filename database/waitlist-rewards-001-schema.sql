-- ============================================================================
-- Web3Edu MAIN database — Waitlist Rewards (admin-managed)   [1 of 2: ADDITIVE]
-- ============================================================================
-- NON-DESTRUCTIVE. Creates two new tables, seeds three INACTIVE xp_rules rows and
-- one site_settings row. Nothing existing is altered, dropped or overwritten.
-- Safe to run more than once (CREATE IF NOT EXISTS / INSERT IGNORE).
--
-- RUN AGAINST THE MAIN WEB3EDU DATABASE ONLY (never the waitlist database).
-- BACK UP FIRST (phpMyAdmin > Export > Quick > SQL).
--
-- Verified against this project's real schema (database/web3edu_v2.sql and its migrations):
--   users.id                    INT (signed)                -> credited_user_id is INT (signed)
--   users.email                 VARCHAR(255), UNIQUE, utf8mb4_unicode_ci (case-insensitive)
--   user_activity_log           the XP ledger awardXp() writes to (metadata is JSON)
--   xp_rules(action_key UNIQUE, label, xp_value, is_active)
--   site_settings(setting_key UNIQUE, setting_val, label, description)
--   All tables are InnoDB. The crediting transaction REQUIRES InnoDB; the app refuses to credit
--   if it detects a non-InnoDB users / user_activity_log / waitlist_rewards table.
--
-- Quick pre-check (optional): every row returned must say InnoDB.
--   SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('users','user_activity_log','xp_rules','site_settings');
-- ============================================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- 1. waitlist_rewards — one admin-approved reward record per normalized email
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `waitlist_rewards` (
  `id`                      INT NOT NULL AUTO_INCREMENT,
  -- Matching identifier: trimmed + lower-cased by the app (wlrNormalizeEmail). utf8mb4_bin makes the
  -- UNIQUE key an exact comparison of the already-normalized value (no accent/case folding surprises).
  `email_normalized`        VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `referred_someone`        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'informational only; referral_count is authoritative',
  `referral_count`          INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'qualifying referrals, entered by an admin',
  `is_active`               TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 = eligible, 0 = excluded from rewards',
  `email_mismatch_flag`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'admin note: member says they registered with a different email',
  `admin_notes`             VARCHAR(1000) DEFAULT NULL,

  -- Crediting state. credited_at IS NOT NULL == credited. All credited_* values are SNAPSHOTS taken inside the
  -- crediting transaction (rates applied then, not today''s rules).
  `credited_at`             DATETIME     DEFAULT NULL,
  `credited_user_id`        INT          DEFAULT NULL,
  `credit_key`              VARCHAR(64)  DEFAULT NULL COMMENT 'durable idempotency key, wlr:<id>:credit',
  `credited_via`            VARCHAR(20)  DEFAULT NULL COMMENT 'password | google | admin',
  `credited_signup_xp`      INT          NOT NULL DEFAULT 0,
  `credited_referral_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `credited_referral_rate`  INT          NOT NULL DEFAULT 0 COMMENT 'XP per referral applied at credit time',
  `credited_referral_xp`    INT          NOT NULL DEFAULT 0,
  `credited_total_xp`       INT          NOT NULL DEFAULT 0 COMMENT 'net XP credited so far (includes audited corrections)',
  `correction_count`        INT UNSIGNED NOT NULL DEFAULT 0,

  `created_by`              INT          DEFAULT NULL COMMENT 'admins.id (no FK: audit survives admin removal)',
  `updated_by`              INT          DEFAULT NULL,
  `created_at`              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wlr_email`        (`email_normalized`),   -- no duplicate records for one email
  UNIQUE KEY `uq_wlr_credited_user` (`credited_user_id`),  -- one account can hold at most one credited record (NULLs allowed)
  UNIQUE KEY `uq_wlr_credit_key`    (`credit_key`),        -- a record is credited at most once
  KEY `idx_wlr_state` (`is_active`, `credited_at`),
  KEY `idx_wlr_created` (`created_at`),
  CONSTRAINT `fk_wlr_credited_user` FOREIGN KEY (`credited_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. waitlist_reward_audit — append-only history (never UPDATE or DELETE rows)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `waitlist_reward_audit` (
  `id`          BIGINT NOT NULL AUTO_INCREMENT,
  `reward_id`   INT DEFAULT NULL COMMENT 'NULL for configuration changes (rules/settings)',
  `action`      VARCHAR(40)  NOT NULL COMMENT 'created, updated, excluded, reincluded, mismatch_flag, credited, credit_blocked, correction, rules_changed, setting_changed',
  `admin_id`    INT DEFAULT NULL,
  `admin_label` VARCHAR(255) DEFAULT NULL COMMENT 'admin email at the time (survives admin removal)',
  `user_id`     INT DEFAULT NULL COMMENT 'main-platform account involved, if any',
  `xp_delta`    INT NOT NULL DEFAULT 0,
  `details`     JSON DEFAULT NULL,
  `idem_key`    VARCHAR(80) DEFAULT NULL COMMENT 'unique for crediting/correction rows',
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wlra_idem` (`idem_key`),
  KEY `idx_wlra_reward` (`reward_id`, `id`),
  KEY `idx_wlra_created` (`created_at`),
  CONSTRAINT `fk_wlra_reward` FOREIGN KEY (`reward_id`) REFERENCES `waitlist_rewards`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. XP rules — REUSES the existing xp_rules table. All three start INACTIVE at 0 XP.
--    Nothing is awarded until an administrator sets a value AND ticks Active
--    (Admin > Waitlist Rewards > Rules).  INSERT IGNORE never overwrites values you already set.
--      registration_welcome : welcome bonus for registering on Web3Edu (paid to EVERY new account via awardXp)
--      waitlist_signup      : waitlist signup reward (paid only with an eligible waitlist record)
--      waitlist_referral    : XP PER qualifying referral (record's referral_count x this value)
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `xp_rules` (`action_key`, `label`, `xp_value`, `is_active`) VALUES
  ('registration_welcome', 'Registration welcome bonus (every new account)',      0, 0),
  ('waitlist_signup',      'Waitlist signup reward (eligible waitlist members)',  0, 0),
  ('waitlist_referral',    'Waitlist reward per qualifying referral',             0, 0);

-- ----------------------------------------------------------------------------
-- 4. Setting — credit immediately on password registration?  Default 1 (as designed).
--    Password sign-ups do NOT verify email ownership in the current platform; Google sign-ups do.
--    Set to 0 in Admin > Waitlist Rewards > Rules to auto-credit only Google (verified) sign-ups;
--    other eligible members then show "ready to credit" for a manual, audited "Credit now".
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `site_settings` (`setting_key`, `setting_val`, `label`, `description`) VALUES
  ('waitlist_credit_unverified_email', '1', 'Waitlist: credit password registrations immediately',
   '1 = credit when someone registers with email+password (email not verified). 0 = only auto-credit Google sign-ups; credit others manually.');
