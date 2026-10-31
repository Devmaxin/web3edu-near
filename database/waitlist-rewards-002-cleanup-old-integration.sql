-- ============================================================================
-- Web3Edu MAIN database — remove the OLD waitlist claim/API integration   [2 of 2: DESTRUCTIVE]
-- ============================================================================
-- !!! DESTRUCTIVE. Not run automatically by anything. Run it yourself, in phpMyAdmin, ONLY after:
--       1. BACKUP: phpMyAdmin > Export > Custom > select the whole database > SQL > Go. Keep the file.
--       2. database/waitlist-rewards-001-schema.sql has been run (STEP B below needs waitlist_rewards).
--       3. The new code is deployed and the old code is gone (so nothing still reads these tables).
--
-- WHAT THIS AFFECTS (the complete list; every object was checked for other users before listing):
--   TABLE   waitlist_claims          one row per old waitlist claim (waitlist_id, user_id, claim_id, xp_amount, state).
--                                    DATA LOSS: the only record of WHICH accounts were paid under the old system.
--                                    STEP B copies those accounts into waitlist_rewards as EXCLUDED first so they can
--                                    never be paid a second time. The XP they received stays on users.total_xp.
--   TABLE   waitlist_checks          per-user "has the waitlist match run" state. Only used by the old auto-match.
--                                    DATA LOSS: none of value.
--   ROW     site_settings 'prelaunch_lock'   the old pre-launch gate switch (see PREFLIGHT 2 BEFORE dropping).
--
-- WHAT THIS DOES NOT TOUCH:
--   * user_activity_log rows with action_key = 'waitlist_claim'. They are the permanent XP history of what users
--     were already paid. Deleting them would make the ledger disagree with users.total_xp. They are kept.
--   * users, xp_rules, site_settings (other keys), maintenance mode, any other table.
--   Neither table has foreign keys pointing at it, and nothing else in the project references them (grep-verified).
--
-- If these tables never existed on your database (the old patch was never migrated): the DROP/DELETE statements are
-- harmless no-ops (IF EXISTS), and PREFLIGHT 3 / STEP B simply report "table doesn't exist". Nothing is changed by
-- those errors. In phpMyAdmin untick "Stop on error" or run the statements one by one.
-- ============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------- PREFLIGHT 1: what exists, how much data
SELECT TABLE_NAME, TABLE_ROWS
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('waitlist_claims', 'waitlist_checks');

-- ---------------------------------------------------------------- PREFLIGHT 2: is the old pre-launch lock ON?
-- The old gate code is deleted by this change. If the value below is '1', the site WAS locked to the public and
-- removing the gate OPENS it. If you still want to hide the site, turn on Admin > Maintenance Mode BEFORE deploying.
-- 0 rows or '0' = the lock was never on: nothing changes for visitors.
SELECT setting_key, setting_val FROM site_settings WHERE setting_key = 'prelaunch_lock';

-- ---------------------------------------------------------------- PREFLIGHT 3: who was already paid under the old system
SELECT u.id, u.email, c.xp_amount, c.state, c.created_at
  FROM waitlist_claims c JOIN users u ON u.id = c.user_id
 ORDER BY c.id;
-- (errors with "table doesn't exist" if waitlist_claims was never created: skip STEP B and continue.)

-- ---------------------------------------------------------------- STEP B: protect already-paid accounts
-- Creates an EXCLUDED waitlist_rewards record for each account that already claimed, so an admin cannot
-- accidentally credit them again. INSERT IGNORE: existing records are left alone. Safe to repeat.
INSERT IGNORE INTO waitlist_rewards (email_normalized, is_active, admin_notes)
SELECT LOWER(TRIM(u.email)), 0, 'Already credited under the retired claim system. Excluded to prevent a second payment.'
  FROM waitlist_claims c JOIN users u ON u.id = c.user_id;

-- ---------------------------------------------------------------- STEP C: drop the old objects  (POINT OF NO RETURN)
-- Uncomment these three lines only when PREFLIGHT 2 is satisfied and you hold a backup.
-- DROP TABLE IF EXISTS waitlist_checks;
-- DROP TABLE IF EXISTS waitlist_claims;
-- DELETE FROM site_settings WHERE setting_key = 'prelaunch_lock';

-- ---------------------------------------------------------------- ROLLBACK
-- Restore the backup you exported above (phpMyAdmin > Import). There is no other way to recover dropped data.
