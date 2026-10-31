# Bounty System — Phase 2 Deliverables

Scope: centralized authorization layer + the 10 remaining bounty features,
built on top of the existing `BOUNTY_MARKETPLACE.md` architecture. No
existing functionality was reverted or redesigned — only extended.

---

## 1. New files created

| File | Purpose |
|---|---|
| `includes/bounty_auth.php` | **Part 1 deliverable.** Centralized authorization layer: `canManageBounty`, `canEditBounty`, `canDeleteBounty`, `canViewSubmission`, `canExportSubmissions`, `canSelectWinners`, `canSubmitToBounty`, `canCommentOnBounty`, `canViewHostDashboard`, `canManageHostProfile`, `canViewBountyProfile`, `canEditBountyProfile`. |
| `database/bounty-marketplace-phase2-migration.sql` | Adds `bounty_submissions.host_feedback` + an index on `bounty_submissions.user_id`. Run once, after the original `bounty-marketplace-migration.sql`. |
| `bounty-profile.php` | **My Bounty Profile** — view/edit anytime (feature 1). |
| `bounty-submissions.php` | **My Submissions** — status, links, host/admin feedback (feature 3). |
| `bounty-host-edit.php` | Host-facing "Edit Bounty" page for their own Hosted bounty content (feature 7). |
| `admin/bounty-profiles.php` | **Admin — Bounty Profiles**: search every user's Bounty Profile, view/edit wallets/bank/socials (feature 2). |
| `admin/bounty-submissions.php` | **Admin — Submission Management**: filter by bounty/host/participant/status, search, inline status + feedback editing (feature 4). |
| `admin/bounty-winners.php` | **Admin — Select Winners** for Official (and, as an override, Hosted) bounties (feature 8). |

## 2. Files modified

| File | Change |
|---|---|
| `includes/bounties.php` | Added `getBountySubmissionById`, `getUserBountySubmissions`, `bountySubmissionStatusLabel`, `setBountySubmissionFeedback`, `listBountySubmissionsAdmin`, `listBountyProfilesAdmin`, `createExternalBountyAsAdmin`. Rewrote `exportBountyParticipantsCsv` to include display name, email, country, wallets, Nigerian bank details (feature 5). |
| `bounty-export.php` | Ownership check now goes through `canExportSubmissions()`. |
| `bounty-payment.php` | Ownership check now goes through `canManageBounty()`. |
| `bounty-detail.php` | Preview/submit/comment gates now go through `canManageBounty`/`canSubmitToBounty`/`canCommentOnBounty`. Also fixed the External-bounty "winners announced" messaging gap. |
| `bookmarks.php` | **Bug fix (feature 9)** — added the missing `case 'bounty':` in the bookmark-resolution `switch`. This was the entire root cause; no workaround was used. |
| `includes/header.php` | Added "My Bounty Profile" and "My Submissions" to the user dropdown nav. |
| `bounty-host-dashboard.php` | Routed through `bounty_auth.php`; added Active/Draft/Closed bounty counts, Total Views/Bookmarks/Submissions, Winners Selected; added Edit/Archive/Close-Bounty actions; added per-submission host feedback in the Submissions tab; winner selection now gated by `canSelectWinners()` (feature 7 & 8). |
| `admin/bounties.php` | Routed through `bounty_auth.php`; create panel now supports **Official, Hosted, or External** bounty creation (feature 6); delete gated by `canDeleteBounty()`; added Bounty Profiles/Submissions nav links and a per-row Winners link. |
| `admin/partials/bounty-form.php` | Bounty Type selector now shows in create mode too, with conditional Host-account and External-URL field groups. |
| `assets/css/bounty.css` | Responsive audit (feature 10) — expanded from 2 breakpoints (768/480) to 320/360/375/390/412/480/768, fixing wallet-row overflow, podium/stat-strip crowding on small screens, and chip sizing. |

## 3. Database changes required

Run, in order, on a database that already has `bounty-marketplace-migration.sql` and `bounty-premium-migration.sql` applied:

```
database/bounty-marketplace-phase2-migration.sql
```

- **New column:** `bounty_submissions.host_feedback` (VARCHAR 1000, nullable)
- **New index:** `bounty_submissions.idx_sub_user` on `user_id`
- No new tables. No existing columns altered or dropped.

## 4. New routes (pages)

- `/bounty-profile.php` — My Bounty Profile
- `/bounty-submissions.php` — My Submissions
- `/bounty-host-edit.php?id=` — Host edits their own bounty
- `/admin/bounty-profiles.php` — Admin Bounty Profiles (list + `?id=` detail/edit)
- `/admin/bounty-submissions.php` — Admin Submission Management
- `/admin/bounty-winners.php?id=` — Admin Select Winners

## 5. Permission helpers created (Part 1)

All in `includes/bounty_auth.php`, described above. Every retrofitted page
now calls these instead of re-deriving ownership/admin/status logic inline.
Two private primitives (`bountyAuthIsHostOwner`, `bountyAuthIsSubmitter`)
back all of them so the ownership rule only lives in one place.

## 6. Feature summary

1. **My Bounty Profile** — done. View/edit anytime, reuses `saveBountyProfile()`.
2. **Admin Bounty Profile access** — done. Search, view, edit any profile including wallets/bank.
3. **My Submissions** — done. Status, submission link, view-bounty link, host/admin feedback, certificate link when a winner.
4. **Submission Management (admin)** — done. Filter by bounty/host/participant/status, search, inline status + feedback edit.
5. **CSV export** — done. Now includes display name, email, country, wallets, Nigerian bank details (when applicable).
6. **Admin Hosted/External bounty creation** — done. Admin can create Official (unchanged), Hosted (attached to a real user account via username/email lookup — behaves identically to a host-created bounty from then on), or External (publishes immediately, admin-approved by construction).
7. **Host Dashboard improvements** — done. Real Active/Draft/Closed counts, Total Views/Bookmarks/Submissions, Winners Selected; Edit/Archive/Close-Bounty actions; per-submission feedback.
8. **Winner Management** — done. Official → admin only (`admin/bounty-winners.php`). Hosted → host, or admin override. External → "Winner(s) announced on the external platform" (this exact message was missing for the `winners_announced` status and has been added).
9. **Bookmark bug** — **found and fixed at the root**: `bookmarks.php`'s bookmark-resolution `switch` never had a `case 'bounty':`, even though the DB enum and `toggle_bookmark.php` already supported it. Bounty bookmarks were saved correctly but silently dropped when the page tried to resolve/display them. No workaround was used — the missing case was added.
10. **Responsive audit** — done via CSS-based review across 320–768px for cards, stat strips, podium, wallet rows, chips, and tabs. **Caveat:** this was reviewed by code inspection, not live browser rendering at each breakpoint — worth a quick visual pass before shipping.

## 8. Regression audit addendum (post-delivery)

A follow-up full-project regression audit (PHP CLI `php -l` across every file,
tokenizer-based undefined-function-call check, include-path resolution check,
schema cross-reference, and include-graph blast-radius analysis) found and
fixed **one real bug**:

- **`bounty_submissions.status` ENUM was missing `'cancelled'`.** The original
  migration only defined `ENUM('submitted','under_review','winner','rejected')`,
  but Phase 2's Admin Submission Management page offers "Cancelled" as a
  settable status (per the My Submissions spec, which explicitly lists
  Cancelled as an example status). Selecting it would have thrown a SQL error.
  **Fixed** by widening the ENUM in `bounty-marketplace-phase2-migration.sql`
  (now run this migration — it was updated after initial delivery).

Everything else checked out clean: no PHP syntax errors project-wide, no
undefined function calls in any touched file, no duplicate function
declarations colliding with the new code, no broken includes introduced by
this work (one broken include was found — in `google-callback.php` at project
root — but it's a pre-existing duplicate of `/auth/google-callback.php`
unrelated to any bounty change, left untouched as out of scope), all new
page links resolve, all new CSS selectors target real markup, and the
include graph confirms no non-bounty system (auth, premium, learning, AI
tutor, notifications, email queue, admin dashboard) loads any file this work
touched beyond the two purely-additive edits to `bookmarks.php` and
`includes/header.php`.

## 9. Known limitations / judgment calls (documented, not hidden)

- **"Closed" bounties** are not a new DB status — "Close Bounty" maps to the existing `judging` transition (stops new submissions) and "Archive Bounty" maps to `archived` (final/hidden). Both were already valid statuses; this avoids a schema change for what is fundamentally the same lifecycle the app already models.
- **Admin viewing a specific host's Host Dashboard** (as opposed to managing via the admin panel) isn't wired up — admins already have full equivalent control via `admin/bounties.php`, `admin/bounty-hosts.php`, and the new `admin/bounty-submissions.php`/`admin/bounty-winners.php`, so this wasn't duplicated.
- The responsive audit (feature 10) was verified by code/schema inspection, not live browser rendering at each breakpoint — worth a quick visual pass before shipping.
