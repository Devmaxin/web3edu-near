-- ============================================================
-- Bounty Marketplace — Phase 3b: Crypto Payment Configuration
-- ============================================================
-- Run ONCE, after database/bounty-marketplace-phase3-payment-migration.sql
--
-- Adds only the "suggested amount per chain" settings. The wallet-address
-- settings the admin already configures (`bounty_ton_wallet`,
-- `bounty_solana_wallet`, `bounty_ethereum_wallet`) already exist from
-- Phase 1 — this does not duplicate them under different key names, it
-- pairs a suggested amount with each one.
--
-- No new table. Uses the existing `site_settings` key/value system.
-- ============================================================

INSERT INTO `site_settings` (`setting_key`, `setting_val`, `label`, `description`)
VALUES
  ('bounty_ton_suggested_amount', '', 'TON — Suggested Amount', 'Suggested payment amount shown to hosts paying with TON, e.g. "2 TON". Purely informational — actual verification happens against the tx hash the host submits.'),
  ('bounty_solana_suggested_amount', '', 'Solana — Suggested Amount', 'Suggested payment amount shown to hosts paying with Solana, e.g. "3 SOL".'),
  ('bounty_ethereum_suggested_amount', '', 'Ethereum — Suggested Amount', 'Suggested payment amount shown to hosts paying with Ethereum, e.g. "0.015 ETH".')
ON DUPLICATE KEY UPDATE setting_val = setting_val; -- no-op if already seeded (safe to run more than once)
