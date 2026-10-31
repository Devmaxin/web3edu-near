# Web3Edu — Listing Fee & Payment System (Phase 3) — Deliverables

## Starting point (important)
Your Phase 1 was more than host approval — the codebase already had a working
`bounty_payments` table, Paystack + crypto flows, an admin review page, and a
Host Dashboard payment history tab. So this phase is a **gap-fill and
hardening pass** on top of that, not a from-scratch build — and per your
"no parallel implementations" instruction, I extended the existing
`bounty_payments` ledger rather than creating a second `listing_payments`
table (that would split one concept's history across two tables).

Everything below was verified by actually spinning up MariaDB locally,
loading a reconstruction of your real schema, and running the migration and
the touched PHP files through `php -l`.

## Root architecture
```
Host applies → Admin approves (sets fee_policy: standard/exempt)
   → Host creates Hosted Bounty → bountyListingFeeForHost() computes fee
      (0 if host is exempt OR listing fee disabled globally)
   → fee > 0 ?
        NO  → status='pending_review' → admin approves → published
        YES → status='awaiting_payment' → bounty-payment.php
                → Paystack (auto-approve on verified callback)
                → Crypto (host submits tx hash → status='under_review' → admin approves/rejects)
   → admin approves payment → publishBounty() → status='published'
   → admin rejects payment → bounty returns to 'draft', host notified why
```

## Database
No new table. Extended `bounty_payments` (see
`database/bounty-marketplace-phase3-payment-migration.sql`):
- `currency` (varchar) — was hardcoded to Naira before
- `paid_at` (timestamp) — distinct from `reviewed_at` (admin verification time)
- `status` enum widened: `pending, paid, under_review, approved, rejected, failed`
  (added `paid` and `failed`; kept `under_review` rather than renaming to
  `awaiting_verification` to avoid touching every existing query for a
  label-only change — the UI shows it as "Awaiting Verification")
- `UNIQUE KEY` on `reference` — a Paystack reference can never be double-spent
  across two payment rows
- New `site_settings`: `bounty_listing_fee_currency`, `bounty_listing_fee_enabled`,
  `bounty_crypto_enabled`, `bounty_paystack_enabled` (fee amount + wallets
  already existed)
- New `email_templates`: `bounty_payment_received_host`,
  `bounty_payment_awaiting_verification_host`

**Migration file:** `database/bounty-marketplace-phase3-payment-migration.sql`
— tested end-to-end against a reconstruction of your real schema. Run once;
re-running the `ALTER TABLE` statements will error on "duplicate column"
(expected, same as your existing migrations) but the two seed `INSERT`s are
safe to run more than once.

## Bugs found and fixed
1. **Fee-exempt hosts were being charged anyway.** `createHostedBounty()`
   always pulled the default listing fee regardless of the host's
   `fee_policy`. New `bountyListingFeeForHost()` fixes this, and
   `approveBounty()` now double-checks fee-exemption at approval time too
   (covers hosts whose policy changed after their bounty was created).
2. **No global enable/disable switches existed** for the listing fee,
   Paystack, or crypto, despite being in scope — added and wired through
   `bounty-payment.php`, `api/init-bounty-payment.php`, and admin settings.
3. **Rejected payments never returned the bounty to draft** — now they do,
   with the host emailed the reason (per spec item 7).
4. **Race conditions**: Paystack callback approval and admin payment
   approval now run inside a transaction with `SELECT ... FOR UPDATE` and
   an idempotency check, so a page refresh, a retried Paystack redirect, or
   an admin double-click can't double-publish or double-email.
5. **Verification failure vs. manual rejection were indistinguishable** —
   a failed Paystack verification now marks the payment `failed`, separate
   from an admin's manual `rejected`.
6. Two host-facing confirmation emails were missing (payment received,
   payment awaiting verification) — added.

## Files modified
- `includes/bounties.php` — fee-policy helpers, `bountyPaymentStatusBadge()`,
  hardened payment functions (transactions, idempotency, duplicate-submission guard)
- `bounty-payment.php` — respects the new enable/disable settings, uses the
  configurable currency, small Review → Listing Fee step indicator
- `bounty-payment-callback.php` — transaction-safe, distinguishes `failed` from `rejected`
- `api/init-bounty-payment.php` — respects the Paystack on/off switch
- `admin/bounty-payments.php` — Date + Organization columns, currency-aware
  amounts, Add Notes action, Approve/Reject moved to POST forms (was a raw
  GET link — vulnerable to accidental double-fire from prefetch/crawlers)
- `admin/settings.php` — new **Bounty Marketplace Settings** card (fee
  amount + currency, three enable/disable toggles, the three crypto
  wallets — previously these wallets had no admin UI at all)
- `bounty-host-dashboard.php` — Overview tab gains Fee Policy, Listing Fee
  Amount, Outstanding Payments; Payments tab gains reference/tx-hash/notes/receipt
- `host-profile.php` — read-only Fee Policy + Verification Status badges
- `assets/css/bounty.css` — badge classes for the new/expanded statuses

## New admin pages
None — `admin/bounty-payments.php` already existed; it was enhanced in place.
The Bounty Marketplace Settings card lives inside the existing `admin/settings.php`.

## New host pages
None — the Payment History tab already existed inside
`bounty-host-dashboard.php`; it was enriched in place.

## Email templates added
- `bounty_payment_received_host` — Paystack receipt confirmation
- `bounty_payment_awaiting_verification_host` — crypto proof received, pending review

(`bounty_payment_requested`, `bounty_payment_admin`, `bounty_payment_approved`,
`bounty_payment_rejected` already existed and are unchanged.)

## Deployment steps
1. Back up the database.
2. Run `database/bounty-marketplace-phase3-payment-migration.sql` once via phpMyAdmin/CLI.
3. Deploy the changed files listed above.
4. Visit **Admin → Settings** and confirm the Bounty Marketplace Settings
   card shows the right fee amount, currency, and wallets (they carry over
   from existing settings — nothing to re-enter unless you want to change them).
5. Spot-check: create a test Hosted Bounty as a `standard`-policy host (should
   hit the payment page) and as an `exempt`-policy host (should skip straight
   to admin review).

## What I did not change
No redesign of the UI, naming conventions, Lucide icons, or existing pages'
layout — every edit above is additive within the existing structure. Official
and External bounties were not touched; they remain free with no payment step.
