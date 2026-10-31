# Bounty Marketplace — Deliverables

A full bounty marketplace for Web3Edu — Official/Hosted/External bounties, a separate
Bounty Profile, host onboarding + dashboard, a Paystack + crypto listing-fee payment
flow, winner announcements with automatic certificates (reusing the certificate system
already in the project), and a full admin side. Built on the existing PHP architecture,
existing auth/notification/email/bookmark/XP/certificate systems, and the existing
purple v2 design tokens — nothing about the current site was rearchitected.

Tested end-to-end against a local MariaDB matching your InfinityFree engine: the full
migration chain (base schema → certificates-v2 → bounty-marketplace) was applied twice
in a row to confirm idempotency, and the entire bounty lifecycle (profile → host →
create → approve → pay → publish → submit → announce winners → certificate issuance)
was run through a PHP test harness before delivery.

---

## 1. Install

1. Run `database/bounty-marketplace-migration.sql` once via phpMyAdmin → SQL tab → Go.
   **Requires `certificates-v2-migration.sql` to already be applied** (it adds a
   `bounty_winner` certificate type and reuses that engine for winner certificates).
2. Upload the changed/new files below over your existing `public_html` (same paths).
3. Go to **Admin → Site Settings** and set your real Solana/Ethereum/TON wallet
   addresses and confirm the listing fee (seeded to ₦10,000 / 1,000,000 kobo by default).
4. Go to **Admin → Bounties → Categories** and adjust the seeded categories if needed.
5. (Optional) Point a cron job at `cron/bounty-cron.php?key=YOUR_CRON_SECRET` for
   expiry reminders — same InfinityFree caveat as your other cron scripts applies (see
   the comment at the top of the file); it also runs opportunistically like the rest.

---

## 2. Database migrations

**New tables** (`database/bounty-marketplace-migration.sql`):

| Table | Purpose |
|---|---|
| `bounty_categories` | Category catalog (Development, Design, Content, Research, Community, Other — seeded) |
| `bounty_profiles` | The separate Bounty Profile (display name, bio, country, socials, wallets, bank) |
| `bounty_hosts` | Auto-created on a user's first hosted bounty; carries the Verified/Community badge flag |
| `bounties` | The bounty itself — official / hosted / external, full lifecycle status |
| `bounty_submissions` | On-platform submissions for official/hosted bounties, including winner placement + linked certificate code |
| `bounty_comments` | Comments on a bounty detail page |
| `bounty_payments` | Listing-fee payments (Paystack or crypto), with admin review fields |

**Also**: widens `user_bookmarks.bookmarkable_type` to include `'bounty'`; seeds 3 XP
rules, 4 site settings (listing fee + 3 wallet placeholders), 1 certificate type
(`bounty_winner`), and 11 email templates covering the full notification lifecycle.

---

## 3. Every file created

**Core**
- `database/bounty-marketplace-migration.sql`
- `includes/bounties.php` — the bounty engine (see §5)
- `includes/countries.php` — shared country list for profile forms
- `includes/bounty_tabs.php` — shared Airdrops/Bounties tab strip
- `includes/bounty_profile_modal.php` — the blocking profile-completion modal
- `includes/partials/bounty_card.php` — reusable bounty card (homepage, marketplace, search)
- `assets/css/bounty.css` — all bounty-specific styling, built on the existing design tokens

**User-facing pages**
- `bounty.php` — Bounty Marketplace homepage (welcome, search, filters, categories, featured, recent winners, host/submit CTAs)
- `bounty-detail.php` — full bounty detail page (stats, countdown, about/requirements/skills, submit or "Continue on Platform", winners podium, comments)
- `bounty-submit.php` — "Host a Bounty" vs "Share an External Bounty" chooser + external submission form
- `bounty-host.php` — host-a-bounty form; auto-creates the Host Profile on first use
- `bounty-host-dashboard.php` — Overview / My Bounties / Submissions / Winners / Payments / Analytics (not in main nav — only reachable from inside the Bounty section, per spec)
- `bounty-payment.php` — listing-fee payment page (Paystack + crypto with wallet/QR/copy)
- `bounty-payment-callback.php` — Paystack verification callback
- `bounty-export.php` — CSV export of a bounty's participants (host or admin only)

**API endpoints**
- `api/save_bounty_profile.php` — saves the Bounty Profile from the modal
- `api/init-bounty-payment.php` — initializes a Paystack transaction for a listing fee

**Admin**
- `admin/bounties.php` — main hub: stats, create Official bounties, approve/reject/feature/archive/delete
- `admin/bounty-categories.php` — manage categories
- `admin/bounty-hosts.php` — verify hosts (Verified Host vs Community Host badge)
- `admin/bounty-payments.php` — review Paystack + crypto payments, approve/reject

**Cron**
- `cron/bounty-cron.php` — queues "closes soon" reminders to hosts, auto-archives long-overdue published bounties

## 4. Every file modified

- `api/toggle_bookmark.php` — added `'bounty'` to the valid bookmark types
- `admin/header.php` — added a **Bounties** nav item
- `airdrops.php` — added the `airdrops.css`+`bounty.css` includes and the shared Airdrops/Bounties tab strip
- `index.php` — added a **Featured Bounties** section (reusing the bounty card partial) right after Featured Airdrops

**Nothing else was touched** — auth, notifications, email delivery, bookmarks, XP, and the certificate system are all *used*, not modified (beyond the two additive tweaks above).

---

## 5. How it works

### Bounty types & badges
`bountyTypeBadge($bounty)` in `includes/bounties.php` returns the icon/label/class for
a bounty's card and detail page: 👑 Official, 🔗 External, or ✔ Verified Host / 🟢
Community Host for hosted bounties (driven by `bounty_hosts.is_verified`, toggled by
admins on the Hosts page).

### Bounty Profile
Separate from the normal Web3Edu profile (`bounty_profiles` table). The first time a
logged-in user hits any bounty-section page, `includes/bounty_profile_modal.php`
renders a non-dismissable modal over the page until Display Name, Country, and
Twitter/X are saved (wallets and everything else stay optional, per spec). Saved via
`api/save_bounty_profile.php`.

### Creating bounties
- **Official**: `createOfficialBounty()` — admin-only, publishes immediately.
- **Hosted**: `createHostedBounty()` — auto-creates the host's `bounty_hosts` row on
  first use (`createOrGetBountyHost()`), goes to `pending_review`.
- **External**: `createExternalBounty()` — blocks duplicate URLs with a unique index +
  an app-level check, goes to `pending_review`.

### Approval → payment → publish
`approveBounty()` branches by type: external bounties publish immediately; hosted
bounties move to `awaiting_payment` (or publish immediately if the listing fee is 0).
The host pays via `bounty-payment.php`:
- **Paystack** — `api/init-bounty-payment.php` reuses the existing `PaystackProvider`
  exactly like `upgrade.php` does for subscriptions; `bounty-payment-callback.php`
  verifies and calls `approvePaystackBountyPayment()`, which publishes the bounty.
- **Crypto** — shows your configured Solana/Ethereum/TON wallets (from Site Settings)
  with copy buttons and a QR code (same public QR API used by the certificate system —
  no new dependency); `submitCryptoBountyPayment()` records the proof as
  `under_review`; an admin approves/rejects from `admin/bounty-payments.php`, which
  calls `approveBountyPayment()` / `rejectBountyPayment()`.

### Submissions & winners
`submitBountyEntry()` enforces one submission per user per bounty. From the Host
Dashboard's Winners tab, `announceBountyWinners()`:
1. Marks each winning submission with its placement and prize
2. Awards XP (`bounty_won`)
3. **Issues a certificate via the certificate engine already in the project**
   (`issueManualCertificate()` against the seeded `bounty_winner` certificate type),
   and stores the code on the submission
4. Emails + notifies each winner
5. Sends every non-winning participant a polite thank-you notification/email
6. Sets the bounty to `winners_announced`, which swaps the detail page's submit panel
   for the 🥇🥈🥉 podium

### Notifications & email
Two small shared helpers in `includes/bounties.php` — `notifyUser()` (writes to the
existing `notifications` table) and `emailUser()` (calls the existing
`deliverEmailTemplate()`) — are used at every lifecycle event listed in the spec
(submitted, approved, rejected, payment requested/approved/rejected, published,
expiring, won, thanks-for-participating). `notifyAdmins()` emails every row in
`admins` for admin-facing events (new submission, payment needs review).

---

## 6. New routes

| Route | Purpose |
|---|---|
| `/bounty.php` | Bounty Marketplace homepage |
| `/bounty-detail.php?slug=...` | Bounty detail page |
| `/bounty-submit.php` / `?mode=external` | Submit-a-bounty chooser + external form |
| `/bounty-host.php` | Host-a-bounty form |
| `/bounty-host-dashboard.php?tab=...` | Host Dashboard (not in main nav) |
| `/bounty-payment.php?id=...` | Listing fee payment |
| `/bounty-payment-callback.php?bounty_id=...&reference=...` | Paystack callback |
| `/bounty-export.php?id=...` | CSV export |
| `/airdrops.php` | Now has an Airdrops/Bounties tab strip |
| `/admin/bounties.php`, `/admin/bounty-categories.php`, `/admin/bounty-hosts.php`, `/admin/bounty-payments.php` | Admin |
| `/api/save_bounty_profile.php`, `/api/init-bounty-payment.php` | AJAX endpoints |
| `/cron/bounty-cron.php?key=...` | Expiry reminders + auto-archive |

---

## 7. Features implemented (mapped to your spec)

Tabs on Airdrops page · Featured Bounties homepage section · 3 bounty types with badges
· Bounty Profile modal (blocking, separate from main profile) · full Bounty homepage
(welcome/search/filters/categories/featured/newest/ending soon/highest rewards/recent
winners/host+submit CTAs) · Submit-a-Bounty chooser (host vs external, with duplicate-URL
blocking + XP reward on approval) · Host-a-Bounty auto-creating a Host Profile · Host
Dashboard with Overview/My Bounties/Payments/Submissions/Winners/Analytics, not in main
nav · full listing payment flow (Paystack auto-approve + crypto manual review with
wallet/QR/copy/"I Have Paid") · Admin Payment Review · Admin + Host notifications for
every listed event · Bounty Detail page matching your reference screenshots' information
density, reskinned in Web3Edu purple · Winners Announced podium + prize distribution +
certificates + emails + in-app notifications + thank-you for non-winners · certificates
via the existing certificate system · Admin panel (create official, approve
hosted/external, approve payments, manage hosts/categories, feature, CSV export, issue
certificates via winner announcement) · mobile responsive 320–768px (see `bounty.css`'s
media queries) · premium UI (soft shadows, rounded cards, gradient welcome banner,
purple accents, `data-reveal` scroll animations reusing your existing site-wide pattern).

---

## 8. Simplified or deferred (documented, not hidden)

Being upfront about what's thinner than a full production build, in priority order for
a Phase 2:

- **Draft status**: the `draft` status exists in the schema and CSS, but there's no
  "Save as Draft" button yet — hosted bounties go straight to `pending_review` on
  submit. Adding a Draft save is a small addition (a second submit button on
  `bounty-host.php` that inserts with `status='draft'` instead of `pending_review`).
- **Judging status**: hosts can manually flip a published bounty to `judging` from the
  dashboard (a lightweight action I added), but there's no dedicated judging UI beyond
  that — winner selection works the same whether a bounty is `published` or `judging`.
- **Analytics tab**: intentionally basic (views, submissions, submissions/bounty). A
  real analytics view (funnel by bounty, time-series, top-performing categories) is a
  natural v2 addition once there's real usage data to chart.
- **Comment moderation**: comments can be posted but there's no admin delete/moderate
  UI yet — only the DB soft-delete column (`bounty_comments.deleted_at`) is there,
  ready for one.
- **"Highest Rewards" sort**: reward amounts are free text (e.g. "5,000 USDC" or
  "₦300,000") so true numeric sorting isn't reliable across currencies; the current
  sort approximates it. A future `reward_amount_normalized DECIMAL` column (admin-set)
  would make this exact.
- **Not-selected vs rejected**: non-winning submissions reuse the existing `rejected`
  enum value (there was no dedicated "not selected" state in the schema) — participants
  themselves only ever see the polite thank-you messaging, so this is an internal
  label choice, not user-facing harshness. A 5th enum value (`not_selected`) is a clean
  follow-up if you want the host-dashboard table wording to match exactly.

---

## 9. Architectural notes / recommendations for scale

- **Reward breakdown as JSON** (`bounties.reward_breakdown`) keeps prize structures
  flexible (1st/2nd/3rd/bonus tiers of any shape) without schema churn — same pattern
  used by `certificate_templates` and `bounty_payments` metadata elsewhere in the app.
- **`issuable_type`/generic engine reuse**: bounty winner certificates flow through the
  *exact same* `issueManualCertificate()` used for Special Achievement / Event
  certificates — no bounty-specific certificate code exists, so future certificate
  types (e.g. "Bounty Participant") are a one-line admin action (new certificate type +
  template), not a code change.
- **Payment provider abstraction already pays off here**: `PaystackProvider` was reused
  as-is for listing fees; if you ever add a second provider (Flutterwave, etc.) for
  subscriptions, the same provider would work for bounty payments with no changes to
  `includes/bounties.php`.
- **Next scale-sensitive spot**: `listBounties()` currently does a `LIKE '%...%'` search
  across title/project_name/skills with no index — fine at hundreds of bounties, but
  once the marketplace grows, a `FULLTEXT` index on `(title, project_name, skills)` (or
  moving search to a dedicated service) is the next lever, not a rewrite.
- **Recommendation**: once real bounty volume exists, promote `reward_display` parsing
  into a normalized `reward_amount` + `reward_currency` pair (keeping `reward_display`
  as the human-facing string) so sorting/filtering/analytics by real dollar value
  becomes possible without breaking the flexible free-text display.
