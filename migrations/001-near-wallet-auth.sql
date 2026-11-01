-- ============================================================================
-- MIGRATION 001: NEAR Wallet Authentication
-- ============================================================================
-- Adds support for NEAR wallet-based authentication and account linking.
--
-- Changes:
-- 1. Make `users.email` nullable to allow wallet-only accounts (no fake email).
-- 2. Create `user_wallets` table to support multiple linked NEAR wallets per user.
-- 3. Unique constraint on `near_account_id` prevents one NEAR account from being
--    linked to multiple Web3Edu users.
-- 4. Create `near_auth_challenges` table for server-side NEP-413 challenge storage.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. Make users.email nullable (wallet-only accounts have no email)
-- ----------------------------------------------------------------------------
-- MySQL UNIQUE on a nullable column allows multiple NULLs, so wallet-only
-- users (email = NULL) never collide with each other.
ALTER TABLE `users`
  MODIFY COLUMN `email` VARCHAR(255) DEFAULT NULL COMMENT 'nullable for wallet-only accounts';

-- ----------------------------------------------------------------------------
-- 2. Create user_wallets table
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_wallets` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `user_id`       INT NOT NULL COMMENT 'FK -> users.id',
  `near_account_id` VARCHAR(128) NOT NULL COMMENT 'NEAR account ID (e.g. alice.testnet)',
  `public_key`    VARCHAR(128) DEFAULT NULL COMMENT 'Ed25519 public key, e.g. ed25519:XXXX',
  `is_primary`    TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'primary wallet for the user',
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_near_account_id` (`near_account_id`),
  KEY `idx_user_wallets_user_id` (`user_id`),
  KEY `idx_user_wallets_near_account` (`near_account_id`),
  CONSTRAINT `fk_user_wallets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. Create near_auth_challenges table (NEP-413 challenge storage)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `near_auth_challenges` (
  `id`           INT NOT NULL AUTO_INCREMENT,
  `challenge_id` VARCHAR(64) NOT NULL COMMENT 'unique challenge identifier',
  `nonce`        VARCHAR(88) NOT NULL COMMENT 'base64-encoded 32-byte nonce',
  `action`       ENUM('login', 'register', 'link') NOT NULL COMMENT 'intended action',
  `recipient`    VARCHAR(255) NOT NULL COMMENT 'expected recipient / application domain',
  `expires_at`   TIMESTAMP NOT NULL COMMENT 'challenge expiration time',
  `used`         TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'consumed flag',
  `user_id`      INT DEFAULT NULL COMMENT 'FK -> users.id (for linking, nullable)',
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_challenge_id` (`challenge_id`),
  KEY `idx_near_auth_challenges_nonce` (`nonce`),
  KEY `idx_near_auth_challenges_expires` (`expires_at`),
  KEY `idx_near_auth_challenges_used` (`used`),
  CONSTRAINT `fk_near_auth_challenges_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- End of migration 001
-- ============================================================================
