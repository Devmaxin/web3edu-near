-- ============================================================================
-- WEB3EDU — MIGRATION: Retire the Lifetime Premium plan
--
-- Web3Edu now only sells Monthly and Yearly Premium. This migration removes
-- the ability to purchase/select "Lifetime" going forward WITHOUT touching
-- any existing subscriber's access or historical purchase records.
--
-- Why not just DELETE the plan or ALTER the ENUM to drop 'lifetime'?
-- Because `purchases.plan_id` / `user_subscriptions.plan_id` may already
-- reference a Lifetime plan row for real, paying customers. Deleting the row
-- or narrowing the ENUM would either break referential integrity or silently
-- break their historical records. Soft-deleting the plan (exactly the same
-- mechanism admin/premium-plans.php already uses for its "Delete" button)
-- removes it from every user-facing and admin-facing list while leaving
-- those historical rows intact and their access untouched — a legacy
-- Lifetime subscriber keeps permanent access via their subscription's
-- NULL `expires_at`, which the app already treats as "never expires"
-- regardless of which plan granted it.
--
-- Safe to run more than once.
-- ============================================================================

UPDATE `premium_plans`
SET `is_active` = 0,
    `deleted_at` = COALESCE(`deleted_at`, NOW())
WHERE `billing_cycle` = 'lifetime';

-- ============================================================================
-- Nothing else changes. No subscriber's expires_at/status is modified, so
-- anyone who already has permanent access keeps it. New checkouts can only
-- ever select Monthly or Yearly, since getActivePlans() only returns rows
-- with is_active = 1 AND deleted_at IS NULL — which the Lifetime row no
-- longer satisfies.
-- ============================================================================
