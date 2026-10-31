# Waitlist Rewards (admin-managed)

Replaces the old automatic waitlist integration (private API, claim link, pre-launch gate). The waitlist site is a separate project and is **never contacted**.

## Workflow
1. A person joins the standalone waitlist.
2. In **Admin > Waitlist Rewards** an admin adds the member's email (they need not have an account yet) and the number of **qualifying referrals** (the waitlist's member page shows the counted referrals as a guide).
3. The member registers on Web3Edu (password or Google). `wlrOnRegistration()` runs once:
   - pays the **welcome bonus** through the existing `awardXp()` ledger (rule `registration_welcome`);
   - looks up an **active, uncredited** record for the account's normalized email and credits
     `waitlist_signup XP + (referral_count x waitlist_referral XP)` exactly once.
4. The record shows **Credited**, with the amount, date, account, a full audit trail and the XP-ledger rows.

## Rules (Admin > Waitlist Rewards > Rules & settings)
Stored in the existing `xp_rules` table. All three are seeded **inactive at 0 XP**; nothing is paid until you set a value and tick Active.

| Rule key | Meaning |
|---|---|
| `registration_welcome` | Welcome bonus, paid to **every** new account (not waitlist-specific). **The platform had no registration welcome bonus before** (only `onboarding_complete`, which is unchanged), so this rule is new. Leave it inactive if you do not want one. |
| `waitlist_signup` | One-time reward for a member with an eligible record. |
| `waitlist_referral` | XP **per** qualifying referral. |

A record is credited only when every rule that applies to it is Active (signup always; referral when the count is above 0). Otherwise nothing is paid, the record is **not consumed**, and an audit line explains why. Active with 0 XP is allowed and means "enabled, pays nothing".

A setting also controls password registrations (see Risks): **Credit password registrations immediately** (default on).

## Statuses
Waiting for registration, Eligible and ready to credit (account exists, not credited), Credited, Excluded, Registration email does not match (admin flag, see below). Search by email, filter by status, 25 per page.

* **Credit now**: for "ready" records (member registered before the record existed, or rules were off at registration). Audited, once only.
* **Email mismatch flag**: if a member says they registered with a different email, flag it, then correct the email on the record to the exact address on their account.
* **Exclude / re-include**: only before crediting. Excluding never claws back XP.
* **Correction after crediting**: the only way to change XP after crediting. Requires a reason, typing CORRECT, and the credited count the admin saw (stale or double submits are rejected). Delta = (new count - credited count) x the rate snapshotted at credit time; can be negative; the balance never goes below 0. Writes a ledger row and an audit row.

## Exactly-once guarantees
Crediting is one InnoDB transaction: lock the record (`SELECT ... FOR UPDATE`), re-check it is uncredited, insert ledger rows, update `users.total_xp`, stamp the record, write the audit row, commit. Any failure rolls everything back, so a retry is safe and XP, ledger, record and audit cannot disagree. Database guards back this up: unique `email_normalized`, unique `credited_user_id` (one account, one record), unique `credit_key`, unique audit `idem_key`. The code refuses to credit if `users`, `user_activity_log` or the reward tables are not InnoDB. Deadlocks are retried.

`awardXp()` is **unchanged**. It does check-then-insert with no unique key, so truly simultaneous identical calls can double-pay. That does not affect this feature (which does not use it for the waitlist credit) and does not affect the welcome bonus in practice (one registration event per account). No change to existing XP was needed; changing it would risk the course, quiz, certificate and referral flows. If you want it hardened later, it needs its own migration and test round.

## Files
**New (main):** `includes/waitlist_rewards.php`, `admin/waitlist-rewards.php`, `database/waitlist-rewards-001-schema.sql`, `database/waitlist-rewards-002-cleanup-old-integration.sql`, `tests/waitlist-rewards/` (`run.php`, `worker.php`, `TestKit.php`, `README.md`), this file.
**Changed (main):** `register.php` (shared hook; duplicate-email race now shows the normal message instead of a fatal error), `auth/google-callback.php` (hook for NEW accounts only), `includes/config.php` (old hooks removed), `admin/header.php` (nav link), `admin/xp-logs.php` (shows the sign of negative corrections).
**Deleted (main):** `includes/waitlist_integration.php`, `includes/prelaunch_gate.php`, `api/waitlist-claim.php`, `claim.php`, `assets/js/waitlist-claim.js`, `admin/prelaunch.php`, `sql/waitlist-claims-migration.sql` (and the empty `sql/` folder), `secrets.additions.txt`, `edits.json`, `apply_edits.py`, `EDITS.md`, `README.md` (all of these existed only for the old patch).

## Pre-launch gate
It **was applied** (`config.php` loaded it) and existed only for the old integration, so its code is removed. It was inert unless `site_settings.prelaunch_lock = '1'`. **Before deploying run** `SELECT setting_val FROM site_settings WHERE setting_key='prelaunch_lock';` If it returns `1`, the site is currently locked to visitors and removing the gate **opens it**: switch on Admin > Maintenance first. If it returns nothing or `0`, nothing changes. The existing maintenance mode is untouched.

## Secrets
Open `secrets/secrets.php` on the server and delete the block of `WAITLIST_*` constants (`WAITLIST_API_BASE`, `WAITLIST_API_KEY_ID`, `WAITLIST_API_SECRET`, `WAITLIST_AUTO_MATCH`) and its comment. Nothing references them any more. This overlay does not contain `secrets.php`, so it will not overwrite yours.

## Deployment order (main)
1. **Back up** the database (phpMyAdmin > Export) and the files.
2. Run the pre-launch check above.
3. Run `database/waitlist-rewards-001-schema.sql` (additive, safe to repeat).
4. Upload the new/changed files and delete the deleted files.
5. Remove the `WAITLIST_*` block from `secrets/secrets.php`.
6. Open Admin > Waitlist Rewards. Keep rules inactive until you have decided values.
7. Later, once the old code is gone: run `waitlist-rewards-002-cleanup-old-integration.sql` (preflights, then STEP B, then uncomment STEP C). It protects already-paid accounts by creating **Excluded** records for them first.
Waitlist project: see its `docs/REMOVAL_OF_OLD_INTEGRATION.md`. The two projects deploy independently, in any order.

## Rollback
Restore the database and files from the backup. To disable only the feature, set the three rules inactive: registrations then behave exactly as before. The new tables can stay unused. The old integration cannot be reinstated without the old files.

## Staging test (manual, ~15 minutes)
1. Run 001. Sign in as admin. Rules tab: set welcome 25, signup 50, per-referral 10, all Active.
2. Add `Test.Member@example.com` with 3 referrals. Detail shows 50 + 3 x 10 = 80.
3. Register on the site with `test.member@example.com` (any case). Expect XP 105, one success flash, status Credited.
4. Reload/log in again: XP unchanged. Try Credit now: refused/no change.
5. Register a second account with an email that has no record: only the welcome 25.
6. Add a record for an email that is already registered: shows Ready; press Credit now once; press again: no change.
7. Apply a correction (3 -> 5): +20 XP, ledger row, audit row; resubmitting the old form is rejected.
8. As a normal user, open `/admin/waitlist-rewards.php`: redirected to admin login. POST without the CSRF token: 403.
9. `/claim.php`, `/api/waitlist-claim.php`, `/admin/prelaunch.php` all 404.
10. Google sign-up needs the live OAuth app; test it with a Google account whose email has a record.

## Risks and constraints
* **Password registration does not verify email ownership** (Google does). Anyone could register with another person's waitlist email and take that reward, and the real owner could not then register with it (pre-existing squatting problem). Mitigation built in: untick "Credit password registrations immediately"; then only Google sign-ups auto-credit and the rest wait for a deliberate **Credit now**. Real fix: add email verification.
* A member who registered with a different address than they joined with needs the record's email corrected by an admin. The waitlist treats `a.b+x@gmail.com` and `ab@gmail.com` as one person; the main platform does not. Enter the exact registration email.
* Existing accounts that sign in with Google for the first time are linked, not registered, so they are not auto-credited. Use Credit now.
* One admin level exists; every admin can manage rewards. All actions are audited with the admin's email.
* Needs MySQL/MariaDB with InnoDB (the project already requires it). Tested on MariaDB 10.11 / PHP 8.3; InfinityFree's versions were not available to test.
* Uploads and `secrets/secrets.php` are not part of the overlay.
