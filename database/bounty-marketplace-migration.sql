-- ============================================================================
-- WEB3EDU — MIGRATION: Bounty Marketplace
--
-- REQUIRES database/certificates-v2-migration.sql to already be applied —
-- this migration adds a certificate_types row and reuses the certificate
-- engine already in the project for bounty-winner certificates.
--
-- Run this ONCE via phpMyAdmin -> your database -> SQL tab -> paste -> Go.
-- Purely additive (new tables + a widened bookmark ENUM) — nothing existing
-- is dropped, renamed, or has its data touched. Safe to run more than once.
--
-- New tables:
--   bounty_categories        catalog of bounty categories (Design, Dev, Content, ...)
--   bounty_profiles          the separate "Bounty Profile" (display name, socials, wallets)
--   bounty_hosts             auto-created the first time a user hosts a bounty
--   bounties                 the bounty itself — official / hosted / external
--   bounty_submissions       on-platform submissions for official/hosted bounties
--   bounty_comments          comments on a bounty detail page
--   bounty_payments          listing-fee payments (Paystack or crypto) for hosted bounties
--
-- Widened: user_bookmarks.bookmarkable_type ENUM gains 'bounty'.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. bounty_categories
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bounty_categories` (
  `id`         INT NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100) NOT NULL,
  `slug`       VARCHAR(100) NOT NULL,
  `icon`       VARCHAR(20) DEFAULT '💼',
  `sort_order` INT DEFAULT 0,
  `is_active`  TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_bounty_cat_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `bounty_categories` (`name`, `slug`, `icon`, `sort_order`) VALUES
('Development', 'development', '🛠️', 10),
('Design',      'design',      '🎨', 20),
('Content',     'content',     '✍️', 30),
('Research',    'research',    '🔎', 40),
('Community',   'community',   '📣', 50),
('Other',       'other',       '💼', 60);

-- ----------------------------------------------------------------------------
-- 2. bounty_profiles — separate from the normal Web3Edu profile
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bounty_profiles` (
  `id`               INT NOT NULL AUTO_INCREMENT,
  `user_id`          INT NOT NULL,
  `display_name`     VARCHAR(100) NOT NULL,
  `bio`              VARCHAR(500) DEFAULT NULL,
  `country`          VARCHAR(100) NOT NULL,
  `twitter`          VARCHAR(150) DEFAULT NULL,
  `discord`          VARCHAR(150) DEFAULT NULL,
  `telegram`         VARCHAR(150) DEFAULT NULL,
  `github`           VARCHAR(150) DEFAULT NULL,
  `portfolio_url`    VARCHAR(255) DEFAULT NULL,
  `solana_wallet`    VARCHAR(120) DEFAULT NULL,
  `ethereum_wallet`  VARCHAR(120) DEFAULT NULL,
  `ton_wallet`       VARCHAR(120) DEFAULT NULL,
  `bank_name`        VARCHAR(150) DEFAULT NULL,
  `account_number`   VARCHAR(30) DEFAULT NULL,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_bounty_profile_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. bounty_hosts — auto-created on a user's first hosted bounty
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bounty_hosts` (
  `id`                INT NOT NULL AUTO_INCREMENT,
  `user_id`           INT NOT NULL,
  `organization_name` VARCHAR(150) NOT NULL,
  `logo_path`         VARCHAR(255) DEFAULT NULL,
  `website`           VARCHAR(255) DEFAULT NULL,
  `contact_email`     VARCHAR(255) NOT NULL,
  `description`       TEXT DEFAULT NULL,
  `is_verified`       TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Verified Host badge, 0 = Community Host badge',
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_bounty_host_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. bounties
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bounties` (
  `id`                     INT NOT NULL AUTO_INCREMENT,
  `type`                   ENUM('official','hosted','external') NOT NULL,
  `host_id`                INT DEFAULT NULL COMMENT 'FK bounty_hosts.id — NULL for official (admin-run) bounties',
  `category_id`            INT DEFAULT NULL,
  `title`                  VARCHAR(200) NOT NULL,
  `slug`                   VARCHAR(220) NOT NULL,
  `project_name`           VARCHAR(150) DEFAULT NULL,
  `project_logo`           VARCHAR(255) DEFAULT NULL,
  `description`            LONGTEXT DEFAULT NULL,
  `requirements`           TEXT DEFAULT NULL,
  `skills`                 VARCHAR(500) DEFAULT NULL COMMENT 'comma-separated tags',
  `reward_display`         VARCHAR(100) DEFAULT NULL COMMENT 'e.g. "5,000 USDC"',
  `reward_breakdown`       JSON DEFAULT NULL COMMENT '[{"place":"1st","amount":"1,800 USDC"}, ...]',
  `num_winners`            INT NOT NULL DEFAULT 1,
  `difficulty`             ENUM('Beginner','Intermediate','Advanced') NOT NULL DEFAULT 'Intermediate',
  `country`                VARCHAR(100) DEFAULT NULL COMMENT 'optional geo restriction, NULL = global',
  `deadline`               DATETIME DEFAULT NULL,
  `winner_announcement_date` DATETIME DEFAULT NULL,

  -- external-bounty-only fields
  `external_platform`     VARCHAR(100) DEFAULT NULL COMMENT 'Superteam Earn, Gitcoin, DoraHacks, Galxe, Layer3, Zealy, Other',
  `external_url`          VARCHAR(500) DEFAULT NULL,

  `status`                 ENUM('draft','pending_review','awaiting_payment','published','judging','winners_announced','archived','rejected') NOT NULL DEFAULT 'draft',
  `rejection_reason`       VARCHAR(255) DEFAULT NULL,
  `is_featured`            TINYINT(1) NOT NULL DEFAULT 0,
  `listing_fee_kobo`       INT NOT NULL DEFAULT 0,
  `view_count`             INT NOT NULL DEFAULT 0,
  `expiry_reminder_sent`   TINYINT(1) NOT NULL DEFAULT 0,

  `submitted_by`           INT DEFAULT NULL COMMENT 'user who submitted/created this row (host or external submitter)',
  `approved_by`            INT DEFAULT NULL COMMENT 'FK admins.id',
  `approved_at`            TIMESTAMP NULL DEFAULT NULL,
  `published_at`           TIMESTAMP NULL DEFAULT NULL,

  `created_at`             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`             TIMESTAMP NULL DEFAULT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_bounty_slug` (`slug`),
  UNIQUE KEY `uniq_external_url` (`external_url`),
  KEY `idx_bounty_status` (`status`),
  KEY `idx_bounty_type` (`type`),
  KEY `idx_bounty_category` (`category_id`),
  KEY `idx_bounty_host` (`host_id`),
  KEY `idx_bounty_deadline` (`deadline`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. bounty_submissions — on-platform submissions (official + hosted bounties)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bounty_submissions` (
  `id`             INT NOT NULL AUTO_INCREMENT,
  `bounty_id`      INT NOT NULL,
  `user_id`        INT NOT NULL,
  `submission_url` VARCHAR(500) DEFAULT NULL,
  `description`    TEXT DEFAULT NULL,
  `status`         ENUM('submitted','under_review','winner','rejected') NOT NULL DEFAULT 'submitted',
  `placement`      INT DEFAULT NULL COMMENT '1 = 1st place, 2 = 2nd, etc',
  `prize_awarded`  VARCHAR(100) DEFAULT NULL,
  `certificate_code` VARCHAR(30) DEFAULT NULL COMMENT 'FK certificates.certificate_code, set when a winner certificate is issued',
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_bounty_user_submission` (`bounty_id`,`user_id`),
  KEY `idx_sub_bounty` (`bounty_id`),
  KEY `idx_sub_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. bounty_comments
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bounty_comments` (
  `id`         INT NOT NULL AUTO_INCREMENT,
  `bounty_id`  INT NOT NULL,
  `user_id`    INT NOT NULL,
  `body`       VARCHAR(1000) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_comment_bounty` (`bounty_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7. bounty_payments — listing-fee payments for hosted bounties
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bounty_payments` (
  `id`               INT NOT NULL AUTO_INCREMENT,
  `bounty_id`        INT NOT NULL,
  `host_id`          INT NOT NULL,
  `method`           ENUM('paystack','crypto') NOT NULL,
  `amount_kobo`      INT DEFAULT NULL,
  `reference`        VARCHAR(100) DEFAULT NULL COMMENT 'Paystack transaction reference',
  `network`          VARCHAR(50) DEFAULT NULL COMMENT 'crypto: Solana, Ethereum, TON',
  `tx_hash`          VARCHAR(150) DEFAULT NULL,
  `amount_crypto`    VARCHAR(50) DEFAULT NULL,
  `wallet_used`      VARCHAR(150) DEFAULT NULL,
  `screenshot_path`  VARCHAR(255) DEFAULT NULL,
  `notes`            VARCHAR(500) DEFAULT NULL,
  `status`           ENUM('pending','under_review','approved','rejected') NOT NULL DEFAULT 'pending',
  `rejection_reason` VARCHAR(255) DEFAULT NULL,
  `reviewed_by`      INT DEFAULT NULL COMMENT 'FK admins.id',
  `reviewed_at`      TIMESTAMP NULL DEFAULT NULL,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payment_bounty` (`bounty_id`),
  KEY `idx_payment_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 8. Widen user_bookmarks.bookmarkable_type to include 'bounty'
-- ----------------------------------------------------------------------------
SET @bm_type = (
  SELECT COLUMN_TYPE FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'user_bookmarks' AND column_name = 'bookmarkable_type'
);
SET @sql = IF(@bm_type IS NOT NULL AND @bm_type NOT LIKE '%''bounty''%',
  "ALTER TABLE `user_bookmarks` MODIFY COLUMN `bookmarkable_type` ENUM('lesson','guide_path','airdrop','blog_post','bounty') NOT NULL",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 9. XP rules for bounty actions (admin-tunable via Admin -> Gamification)
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `xp_rules` (`action_key`, `label`, `xp_value`, `is_active`) VALUES
('bounty_external_shared_approved', 'External bounty shared & approved', 25, 1),
('bounty_won', 'Won a hosted/official bounty', 100, 1),
('bounty_submitted', 'Submitted an entry to a bounty', 10, 1);

-- ----------------------------------------------------------------------------
-- 9b. Bounty Winner certificate type (uses the certificate system already
--     in the project — requires certificates-v2-migration.sql to have been
--     run first, since certificate_types only exists after that migration).
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `certificate_types` (`type_key`, `label`, `description`, `icon`, `id_prefix`, `sort_order`) VALUES
('bounty_winner', 'Bounty Winner', 'Awarded to winners of a hosted or official Web3Edu bounty.', '🏆', 'WEB3', 90);

-- ----------------------------------------------------------------------------
-- 10. Site settings used by the bounty payment flow (safe defaults —
--     change the wallet addresses in Admin -> Site Settings before going live)
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `site_settings` (`setting_key`, `setting_val`, `label`, `description`) VALUES
('bounty_listing_fee_kobo', '1000000', 'Bounty Listing Fee (kobo)', 'Default listing fee charged to hosts, in kobo. ₦10,000 = 1000000.'),
('bounty_solana_wallet',   '', 'Solana Wallet (receiving)', 'Wallet address shown to hosts paying by crypto.'),
('bounty_ethereum_wallet', '', 'Ethereum Wallet (receiving)', 'Wallet address shown to hosts paying by crypto.'),
('bounty_ton_wallet',      '', 'TON Wallet (receiving)', 'Wallet address shown to hosts paying by crypto.');

-- ----------------------------------------------------------------------------
-- 11. Email templates for the bounty marketplace
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `email_templates` (`template_key`, `subject`, `html_body`, `variables_hint`, `is_active`) VALUES

('bounty_submitted_admin', 'New bounty submitted for review: {{title}}',
 '<p>Hi Admin,</p><p><strong>{{username}}</strong> submitted a new bounty for review: <strong>{{title}}</strong> ({{type}}).</p><p><a href="{{site_url}}/admin/bounties.php" style="color:#7C3AED;font-weight:600;">Review it →</a></p>',
 '{{username}}, {{title}}, {{type}}, {{site_url}}', 1),

('bounty_approved_host', 'Your bounty "{{title}}" was approved 🎉',
 '<p>Hi {{username}},</p><p>Good news — <strong>{{title}}</strong> was approved!</p><p>{{next_step}}</p><p><a href="{{site_url}}/bounty-host-dashboard.php" style="color:#7C3AED;font-weight:600;">Go to your Host Dashboard →</a></p>',
 '{{username}}, {{title}}, {{next_step}}, {{site_url}}', 1),

('bounty_rejected_host', 'Your bounty "{{title}}" needs changes',
 '<p>Hi {{username}},</p><p><strong>{{title}}</strong> wasn''t approved as submitted.</p><p>Reason: {{reason}}</p><p>You''re welcome to revise and resubmit.</p>',
 '{{username}}, {{title}}, {{reason}}', 1),

('bounty_payment_requested', 'Action needed: pay the listing fee for "{{title}}"',
 '<p>Hi {{username}},</p><p><strong>{{title}}</strong> is approved and ready to go live once the listing fee is paid.</p><p><a href="{{payment_url}}" style="color:#7C3AED;font-weight:600;">Pay now →</a></p>',
 '{{username}}, {{title}}, {{payment_url}}', 1),

('bounty_payment_admin', 'Payment received for review: {{title}}',
 '<p>Hi Admin,</p><p>A payment for <strong>{{title}}</strong> ({{method}}) needs review.</p><p><a href="{{site_url}}/admin/bounty-payments.php" style="color:#7C3AED;font-weight:600;">Review payment →</a></p>',
 '{{title}}, {{method}}, {{site_url}}', 1),

('bounty_payment_approved', 'Payment approved — "{{title}}" is now live! 🚀',
 '<p>Hi {{username}},</p><p>Your payment for <strong>{{title}}</strong> was approved and the bounty is now published.</p><p><a href="{{bounty_url}}" style="color:#7C3AED;font-weight:600;">View your live bounty →</a></p>',
 '{{username}}, {{title}}, {{bounty_url}}', 1),

('bounty_payment_rejected', 'Payment for "{{title}}" needs attention',
 '<p>Hi {{username}},</p><p>We couldn''t verify your payment for <strong>{{title}}</strong>.</p><p>Reason: {{reason}}</p><p>Please try again or contact support.</p>',
 '{{username}}, {{title}}, {{reason}}', 1),

('bounty_published', '"{{title}}" is live on Web3Edu!',
 '<p>Hi {{username}},</p><p><strong>{{title}}</strong> is now live and accepting submissions.</p><p><a href="{{bounty_url}}" style="color:#7C3AED;font-weight:600;">View it →</a></p>',
 '{{username}}, {{title}}, {{bounty_url}}', 1),

('bounty_expiring_host', '"{{title}}" closes soon', 
 '<p>Hi {{username}},</p><p>Your bounty <strong>{{title}}</strong> closes soon. Time to review submissions and pick winners!</p>',
 '{{username}}, {{title}}', 1),

('bounty_winner', 'You won "{{title}}"! 🏆',
 '<p>Hi {{username}},</p><p>Congratulations — you placed <strong>{{placement}}</strong> in <strong>{{title}}</strong> and won <strong>{{prize}}</strong>!</p><p>Your certificate is ready.</p><p><a href="{{site_url}}/certificates.php" style="color:#7C3AED;font-weight:600;">View your certificate →</a></p>',
 '{{username}}, {{title}}, {{placement}}, {{prize}}, {{site_url}}', 1),

('bounty_participant_thanks', 'Thanks for participating in "{{title}}"',
 '<p>Hi {{username}},</p><p>Thank you for submitting to <strong>{{title}}</strong>! You weren''t selected as a winner this time, but we hope you''ll try again on the next bounty.</p><p><a href="{{site_url}}/bounty.php" style="color:#7C3AED;font-weight:600;">Browse more bounties →</a></p>',
 '{{username}}, {{title}}, {{site_url}}', 1);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- Done. Next steps:
--   1. Admin -> Site Settings: set your real Solana/Ethereum/TON receiving
--      wallets and confirm the listing fee before accepting real hosts.
--   2. Admin -> Bounties -> Categories: adjust the seeded categories if needed.
--   3. Try creating an Official bounty from Admin -> Bounties -> New Bounty.
-- ============================================================================
