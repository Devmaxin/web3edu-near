-- ============================================================================
-- WEB3EDU — MIGRATION: Premium Subscription System Audit
--
-- Adds what the subscription audit needed that wasn't there yet:
--   1. subscription_history  — full audit trail per subscription (item 11)
--   2. admin_notifications   — admin-facing alert feed (item 10)
--   3. subscription_reminder_log.reminder_type widened to fit the new
--      per-cycle reminder schedule (30/7/1 day for yearly, 7/1 day for monthly)
--   4. A hidden "Admin Granted" plan so admin/users.php's Grant/Remove
--      Premium action creates a real user_subscriptions row instead of just
--      flipping the users.membership_type flag with nothing behind it.
--
-- Purely additive / idempotent. Safe to run more than once, and safe to run
-- on a database that already has data in it.
-- ============================================================================

-- 1. subscription_history — one row per lifecycle event.
CREATE TABLE IF NOT EXISTS `subscription_history` (
  `id`              INT NOT NULL AUTO_INCREMENT,
  `subscription_id` INT NOT NULL,
  `user_id`         INT NOT NULL,
  `event`           VARCHAR(30) NOT NULL COMMENT 'created, renewed, extended, reduced, expired, cancelled, reactivated',
  `plan_name`       VARCHAR(100) DEFAULT NULL,
  `reference`       VARCHAR(100) DEFAULT NULL COMMENT 'Paystack transaction reference, when this event was payment-driven',
  `amount`          DECIMAL(10,2) DEFAULT NULL COMMENT 'Amount paid, when this event was payment-driven',
  `note`            VARCHAR(255) DEFAULT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_subhist_subscription` (`subscription_id`),
  KEY `idx_subhist_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. admin_notifications — surfaced on admin/subscriptions.php.
CREATE TABLE IF NOT EXISTS `admin_notifications` (
  `id`         INT NOT NULL AUTO_INCREMENT,
  `type`       VARCHAR(50) NOT NULL COMMENT 'subscription_purchased, subscription_renewed, subscription_expired, etc',
  `title`      VARCHAR(255) NOT NULL,
  `body`       TEXT DEFAULT NULL,
  `link`       VARCHAR(255) DEFAULT NULL,
  `is_read`    TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_notif_read` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Widen subscription_reminder_log.reminder_type from the old
--    ENUM('7_day','3_day','same_day','expired') to a plain VARCHAR so it can
--    hold the new '30_day' / '7_day' / '1_day' / 'expired' values without a
--    migration every time the reminder schedule changes.
ALTER TABLE `subscription_reminder_log`
  MODIFY COLUMN `reminder_type` VARCHAR(20) NOT NULL;

-- 4. Hidden "Admin Granted" plan — is_active = 0 keeps it off the public
--    pricing page (getActivePlans()/getPlanBySlug() both filter is_active=1),
--    but admin code can still reach it by slug via getAdminGrantPlan().
INSERT INTO `premium_plans` (`name`, `slug`, `billing_cycle`, `price_kobo`, `description`, `is_active`, `sort_order`)
SELECT 'Admin Granted', 'admin-granted', 'yearly', 0, 'Manually granted by an administrator — never expires unless revoked.', 0, 999
WHERE NOT EXISTS (SELECT 1 FROM `premium_plans` WHERE `slug` = 'admin-granted');

-- ============================================================================
-- Done. Nothing here touches existing subscriber data — subscription_history
-- simply starts recording from whenever this migration runs onward.
-- ============================================================================
