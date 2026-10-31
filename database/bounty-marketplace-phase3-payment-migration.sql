-- ============================================================
-- Bounty Marketplace — Phase 3: Listing Fee & Payment System
-- ============================================================
-- Run ONCE, after:
--   database/bounty-marketplace-migration.sql
--   database/bounty-marketplace-phase2-migration.sql
--
-- Like the Phase 1/2 migrations, the ALTER TABLE statements below are not
-- re-runnable (re-running will error on "duplicate column" — that's
-- expected and safe to ignore if you're just confirming it already
-- applied). The two seed INSERTs at the bottom (settings + email
-- templates) use ON DUPLICATE KEY UPDATE and ARE safe to run more than once.
--
-- This migration EXTENDS the existing `bounty_payments` table (created in
-- Phase 1) rather than introducing a parallel `listing_payments` table —
-- `bounty_payments` already is the dedicated listing-payment ledger the
-- spec asks for (one row per attempt, full history, host_id/bounty_id FKs,
-- Paystack + crypto fields, admin review fields). Renaming it or forking a
-- second table would fragment payment history across two tables for the
-- same concept, which the "no parallel implementations" instruction rules
-- out. Everything below is additive — no existing column is dropped or
-- renamed, no existing row is altered in a breaking way.
-- ============================================================

-- 1. New columns: currency + paid_at (distinct from reviewed_at, which is
--    the moment an admin *verifies* a payment; paid_at is the moment money
--    actually moved — set immediately for Paystack, left null for crypto
--    until an admin approves it).
ALTER TABLE `bounty_payments`
  ADD COLUMN `currency` VARCHAR(10) NOT NULL DEFAULT 'NGN' AFTER `amount_kobo`,
  ADD COLUMN `paid_at` TIMESTAMP NULL DEFAULT NULL AFTER `notes`;

-- 2. Widen the status enum. Existing values are untouched; `paid` and
--    `failed` are added so the workflow can distinguish "money confirmed,
--    awaiting nothing further" (paid — used briefly for Paystack before
--    publish) from "verification failed" (failed — e.g. Paystack callback
--    could not confirm the transaction) vs. an admin's manual `rejected`.
--    `under_review` is kept as-is (rather than renamed to
--    `awaiting_verification`) to avoid rewriting every existing reference
--    in code/queries for a label-only change; the UI-facing badge already
--    displays it as "Awaiting Verification" (see bountyPaymentStatusBadge()).
ALTER TABLE `bounty_payments`
  MODIFY COLUMN `status` ENUM('pending','paid','under_review','approved','rejected','failed') NOT NULL DEFAULT 'pending';

-- 3. A Paystack reference should never be reused across two payment rows.
--    Crypto rows have no reference (NULL), and MySQL/MariaDB unique
--    indexes allow unlimited NULLs, so this only guards the Paystack path.
ALTER TABLE `bounty_payments`
  ADD UNIQUE KEY `uniq_reference` (`reference`);

-- 4. `site_settings.setting_key` and `email_templates.template_key` already
--    carry unique keys in production, so the upserts below are safe as-is.

-- 5. Global Bounty Marketplace Settings — enable/disable switches and the
--    listing-fee currency. The fee amount + wallet settings already exist
--    (`bounty_listing_fee_kobo`, `bounty_solana_wallet`, `bounty_ethereum_wallet`,
--    `bounty_ton_wallet`) — only the missing ones are added here.
INSERT INTO `site_settings` (`setting_key`, `setting_val`, `label`, `description`)
VALUES
  ('bounty_listing_fee_currency', 'NGN', 'Hosted Listing Currency', 'Currency code shown next to the listing fee (e.g. NGN, USD).'),
  ('bounty_listing_fee_enabled', '1', 'Enable Listing Fee', 'If disabled, all Hosted bounties skip payment and go straight to Pending Admin Review after approval.'),
  ('bounty_crypto_enabled', '1', 'Enable Crypto Payments', 'If disabled, the "Pay with Crypto" option is hidden on the listing-fee payment page.'),
  ('bounty_paystack_enabled', '1', 'Enable Paystack', 'If disabled, the "Pay with Paystack" option is hidden on the listing-fee payment page.')
ON DUPLICATE KEY UPDATE setting_val = setting_val; -- no-op if already seeded (safe re-run)

-- 6. Two host-facing email templates that were missing: a receipt-style
--    confirmation for Paystack, and a confirmation that a crypto proof was
--    received and is awaiting verification. (bounty_payment_approved /
--    bounty_payment_rejected / bounty_payment_admin already existed.)
INSERT INTO `email_templates` (`template_key`, `subject`, `html_body`, `variables_hint`, `is_active`, `updated_at`)
VALUES
  ('bounty_payment_received_host',
   'Payment received — "{{title}}"',
   '<p>Hi {{username}},</p><p>We received your listing-fee payment of {{amount}} for <strong>{{title}}</strong> via {{method}}.</p><p><a href="{{site_url}}/bounty-host-dashboard.php?tab=payments" style="color:#7C3AED;font-weight:600;">View payment history →</a></p>',
   '{{username}}, {{title}}, {{amount}}, {{method}}, {{site_url}}', 1, NOW()),
  ('bounty_payment_awaiting_verification_host',
   'Payment submitted for review — "{{title}}"',
   '<p>Hi {{username}},</p><p>Thanks — we received your crypto payment proof for <strong>{{title}}</strong>. It\'s now awaiting verification by our team; you\'ll get an email as soon as it\'s reviewed.</p>',
   '{{username}}, {{title}}', 1, NOW())
ON DUPLICATE KEY UPDATE subject = subject; -- no-op if already seeded (safe re-run)
