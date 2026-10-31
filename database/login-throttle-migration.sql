-- ============================================================================
-- WEB3EDU — MIGRATION: Login Attempt Throttling
--
-- Run this ONCE against the existing web3edu_v2 database (phpMyAdmin ->
-- your database -> SQL tab -> paste -> Go). It only ADDS one new table —
-- nothing existing is touched, so login, registration, Google OAuth, and
-- every other existing flow keeps working exactly as before.
--
-- What this does:
--   Creates `login_attempts` — a small append-only log of failed login
--   attempts, used by includes/login_throttle.php to rate-limit repeated
--   password guessing on both login.php and admin/login.php. Each failed
--   attempt writes two rows: one keyed by the submitted email (so the
--   same account can't be hammered from many IPs) and one keyed by the
--   client IP (so many accounts can't be sprayed from one source). Rows
--   are never updated in place — enforcement is a COUNT of rows within a
--   short sliding time window, and old rows are pruned opportunistically
--   on write (see login_throttle.php) rather than needing a separate
--   cron job. Nothing here stores a password or any other sensitive
--   authentication data — only the throttle key string and a timestamp.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`            BIGINT NOT NULL AUTO_INCREMENT,
  `throttle_key`  VARCHAR(191) NOT NULL COMMENT 'e.g. "email:<lowercased submitted email>" or "ip:<client ip>" — never a password or session token',
  `attempted_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_key_time` (`throttle_key`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
