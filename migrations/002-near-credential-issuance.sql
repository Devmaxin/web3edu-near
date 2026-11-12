-- ============================================================================
-- MIGRATION: NEAR Credential Issuance Tracking
-- ============================================================================
-- Tracks the lifecycle of NEAR credential issuance requests between the
-- Web3Edu PHP application and the deployed NEAR smart contract.
--
-- States:
--   REQUESTED   - Learner has requested issuance; no transaction broadcast yet
--   UNCERTAIN   - Transaction hash submitted but outcome not yet confirmed
--   VERIFIED    - Transaction confirmed on-chain; credential exists
--   FAILED      - Transaction failed or validation rejected
--   EXPIRED     - REQUESTED expired without transaction hash submitted
--
-- No UNIQUE(local_cert_id): resolved attempts must be preserved for retry.
-- Unique constraints only on non-null transaction_hash and near_credential_id
-- to prevent duplicate recording of the same on-chain credential.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `near_credential_issuances` (
  `id`                INT NOT NULL AUTO_INCREMENT,
  `local_cert_id`     INT NOT NULL,
  `user_id`           INT NOT NULL,
  `near_account_id`   VARCHAR(128) NOT NULL,
  `course_id`         VARCHAR(255) NOT NULL,
  `metadata_hash`     VARCHAR(255) DEFAULT NULL,
  `transaction_hash`  VARCHAR(255) DEFAULT NULL,
  `near_credential_id` VARCHAR(128) DEFAULT NULL,
  `block_hash`        VARCHAR(255) DEFAULT NULL,
  `block_height`      BIGINT DEFAULT NULL,
  `status`            ENUM('REQUESTED','UNCERTAIN','VERIFIED','FAILED','EXPIRED') NOT NULL DEFAULT 'REQUESTED',
  `attempt_count`     INT NOT NULL DEFAULT 0,
  `error_message`     TEXT DEFAULT NULL,
  `submitted_at`      TIMESTAMP NULL DEFAULT NULL,
  `expires_at`        TIMESTAMP NULL DEFAULT NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_transaction_hash` (`transaction_hash`),
  UNIQUE KEY `uniq_near_credential_id` (`near_credential_id`),
  KEY `idx_issuances_local_cert` (`local_cert_id`),
  KEY `idx_issuances_user_id` (`user_id`),
  KEY `idx_issuances_status` (`status`),
  KEY `idx_issuances_expires` (`expires_at`),
  CONSTRAINT `fk_issuances_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_issuances_cert` FOREIGN KEY (`local_cert_id`) REFERENCES `certificates` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- End of migration
-- ============================================================================
