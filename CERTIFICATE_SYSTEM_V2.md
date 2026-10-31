# Certificate Management System v2 — Deliverables

This upgrades Web3Edu's single-template certificate system into a full
certificate management platform: multiple certificate types, a visual
template builder, custom backgrounds (PNG/JPG/SVG), automatic award rules,
per-guide certificate settings, public verification, and a full admin
dashboard — while keeping every certificate already issued working exactly
as before.

Tested end-to-end against a local MariaDB 10.11 instance (same engine
family as InfinityFree): the migration was applied twice in a row to
confirm idempotency, and the core engine (issue → verify → revoke →
reissue → rules) was exercised with a PHP test harness before delivery.

---

## 1. Install

Run **`database/certificates-v2-migration.sql`** once via phpMyAdmin → your
database → SQL tab → paste → Go. It only adds tables/columns; nothing
existing is dropped, renamed, or has its data touched. It's also safe to
run more than once (every step checks first).

Then upload the changed/new PHP files below over your existing `public_html`
(same relative paths).

---

## 2. Every database change

**New tables**
| Table | Purpose |
|---|---|
| `certificate_types` | Catalog of certificate kinds (Course Completion, Event, Special Achievement, ...). Seeded with 8 starter types. Each has its own ID prefix. |
| `certificate_backgrounds` | Uploaded PNG/JPG/SVG background library, reusable across templates. SVGs are sanitized on upload. |
| `certificate_rules` | "Award this certificate when..." automation rules (quiz score, XP level, badge earned, premium purchased, custom event). |
| `certificate_verifications` | Append-only log of every verification-page hit, for the admin dashboard's verification count. |
| `certificate_downloads` | Append-only log of every PDF download. |
| `certificate_sequences` | Powers human-readable IDs like `WEB3-2026-000012` (one counter per type + year). |

**`certificate_templates` — new columns**
`certificate_type_id`, `render_mode` (`builder`/`custom_html`), `subtitle`,
`body_text`, `footer_text`, `signature1_name`, `signature1_title`,
`signature2_name`, `signature2_title`, `issue_date_format`, `show_qr`,
`verification_url_override`, `site_logo_override`, `background_id`,
`orientation`. `html_template` is now nullable (only required in Custom
HTML mode). Every existing template was auto-migrated to `render_mode =
custom_html` so it renders identically to before.

**`certificates` — new columns**
`certificate_type_id`, `rule_id`, `reissued_from_id`, `achievement_title`,
`metadata` (JSON), `expires_at`, `revoke_reason`, `revoked_at`,
`revoked_by`, `download_count`, `last_downloaded_at`. `issuable_type`
widened from a 1-value `ENUM('guide_path')` to `VARCHAR(50)` so future
issuable kinds never need another migration. The old hard uniqueness
constraint on `(user_id, issuable_type, issuable_id)` was replaced with a
plain index — reissuing a certificate now keeps the old (revoked) row for
history *and* creates a new row for the same achievement, which the old
constraint would have blocked. New-issuance idempotency is enforced in app
code instead (`issueCertificateV2()` only treats a match as "already
issued" if it isn't revoked).

**`guide_paths` — new columns** (per-guide certificate settings)
`certificate_enabled`, `min_completion_percent`, `min_quiz_score`,
`require_final_quiz`, `require_premium`, `allow_redownload`,
`certificate_expires_days`.

---

## 3. Every new / modified file

**New**
- `database/certificates-v2-migration.sql` — the migration described above
- `includes/certificates.php` — the certificate engine (see §5)
- `admin/certificate-types.php` — manage certificate types
- `admin/certificate-rules.php` — manage automatic award rules
- `admin/certificates-issued.php` — issued-certificates dashboard (search, filter, revoke, reissue, regenerate, delete, manual issuance, stats)
- `admin/ajax-user-search.php` — small JSON endpoint powering the user picker on the manual-issuance form
- `certificate/verify.php` — public verification page (`/certificate/verify.php?id=WEB3-2026-000012`)
- `certificate/.htaccess` — lets the same URL work without the `.php` extension
- `api/log-certificate-download.php` — logs a PDF download before the client generates it

**Modified**
- `admin/certificate-templates.php` — full rewrite: template builder (structured fields) *and* custom-HTML mode, background picker/uploader, live preview, duplicate, per-type default
- `admin/guides.php` — adds the "Certificate Settings" panel to the guide editor and saves the new columns
- `includes/gamification.php` — guide-completion issuance now goes through the guide's own settings (`maybeIssueGuideCertificate()`); badge and level-up events now fire the rules engine; `issueCertificate()` kept as a backward-compatible shim
- `includes/subscriptions.php` — fires the `premium_active` rule trigger on first-time premium activation
- `api/submit_quiz.php` — fires the `quiz_score` rule trigger and re-checks the quiz's parent guide (covers `require_final_quiz` / `min_quiz_score`)
- `certificates.php` — user's "My Certificates" page now shows type/icon, expiry, and a verify link for every certificate type (not just guides)
- `certificate-view.php` — renders any certificate type via the new engine, adds a real **Download PDF** button (client-side, via html2pdf.js — no server PDF library needed on shared hosting), respects each certificate's re-download permission

---

## 4. How certificate generation now works

Every certificate — guide completion, quiz mastery, badge earned, XP
level, premium purchase, or a manual admin award — flows through one
function: **`issueCertificateV2()`** in `includes/certificates.php`.

1. Checks whether a non-revoked certificate already exists for this
   `(user, issuable_type, issuable_id)` — if so, returns the existing code
   (issuance is idempotent).
2. Resolves a template: the one explicitly passed in, else the
   type's default template.
3. Generates a certificate ID via `generateCertificateCode()` — an
   atomic, per-type-per-year counter (`certificate_sequences`) producing
   IDs like `WEB3-2026-000012`.
4. Inserts the `certificates` row (with a JSON `metadata` bag for anything
   extra — score, guide title, redownload permission, etc).
5. Sends the in-app notification and the `certificate` email (reusing the
   existing email-template system).

**Guide completions** go through `maybeIssueGuideCertificate()` instead of
calling `issueCertificateV2()` directly — it checks that guide's own
settings first (minimum completion %, minimum quiz score, require final
quiz, require premium) and only issues once every configured requirement
is met. It's called both when a lesson is completed *and* when a quiz is
passed, since a guide might be waiting on either one.

**Everything else** (quiz mastery, XP level, badge earned, premium
purchase, custom events) goes through **`evaluateCertificateRules()`** —
the automation engine. Existing trigger points in the codebase
(`recalculateLevel()`, `awardBadge()`, `evaluateAndAwardBadges()`,
`submit_quiz.php`, `activateSubscription()`) each got a one-line hook that
fires the matching event; the engine checks every active `certificate_rules`
row for that event and issues a certificate for any that match.

**Manual awards** (Special Achievement, Event, Community Contribution, ...)
go through `issueManualCertificate()`, used by the "Issue Manually" panel
on the admin Issued Certificates page.

---

## 5. How template rendering works

`renderCertificateBody($template, $vars)` in `includes/certificates.php`
has two modes:

- **`render_mode = custom_html`** — behaves exactly like the original
  system: `renderTemplateString($template['html_template'], $vars)`. Every
  certificate issued before this upgrade was auto-migrated to this mode,
  so nothing already issued changes.
- **`render_mode = builder`** (the new default for new templates) — a
  clean, print-ready layout built from the structured fields (subtitle,
  body text, footer, up to two signatures, QR code) with no HTML
  knowledge required.

Both modes share the same placeholder set, filled in by
`buildCertificatePlaceholders()`:

```
{{username}}  {{full_name}}  {{certificate_title}}  {{guide_name}}
{{course_name}}  {{learning_path}}  {{issue_date}}  {{completion_date}}
{{certificate_id}}  {{site_name}}  {{site_url}}  {{verification_url}}
```

Admins can preview a template — including unsaved changes — from the
"Preview" button on the template editor, which posts the current form
state to the page's own `preview_render` action and opens the real
rendered output in a new tab.

---

## 6. How verification works

Every certificate gets a permanent, public URL:

```
https://web3education.xyz/certificate/verify.php?id=WEB3-2026-000012
```

(`/certificate/verify?id=...` — no extension — also works via
`certificate/.htaccess`, matching the URL pattern from the spec exactly.)

`verifyCertificateByCode()` looks the certificate up, joins in its
template/type/user, computes whether it's expired, and — for the public
verify page specifically — logs the hit to `certificate_verifications`.
The page shows status (Valid / Revoked / Expired), the recipient, issue
date, certificate ID, template/type, and a QR code that encodes this same
verification URL (generated via a public QR image API — no server-side
dependency needed on shared hosting).

`certificate-view.php` (the owner's full-size view/download page) calls
the same lookup with logging turned **off**, so a user viewing their own
certificate doesn't inflate the verification counter — only third-party
verification-page visits count.

---

## 7. How uploaded certificate backgrounds are rendered

`handleCertificateBackgroundUpload()` accepts PNG, JPG, and SVG (raster
uploads reuse the existing 5MB limit). SVGs are run through
`sanitizeSvgBackground()` before being saved, which strips `<script>`
tags, `on*=` event-handler attributes, `javascript:` URLs, and
`<!DOCTYPE>`/`<!ENTITY>` declarations — SVG is XML with a scripting model,
so this is mandatory before letting admins upload arbitrary files.

The file is saved under `uploads/certificates/` (which already blocks PHP
execution via the existing `uploads/.htaccess`) and tracked in
`certificate_backgrounds`. A template references a background via
`background_id`; at render time the background's image is applied as a
CSS `background-image` behind the certificate content, so the uploaded
design becomes the certificate background and the text/signatures/QR
render on top of it — exactly as specified.

---

## 8. Where everything lives in the admin

- **Admin → Certificates → Templates** (`admin/certificate-templates.php`) — create/edit/duplicate/enable-disable/delete templates, builder or custom-HTML, background picker/uploader, live preview
- **Admin → Certificate Types** (`admin/certificate-types.php`) — manage the catalog of certificate kinds and their ID prefixes
- **Admin → Certificate Rules** (`admin/certificate-rules.php`) — automatic award rules for quiz scores, XP levels, badges, and premium purchases
- **Admin → Issued Certificates** (`admin/certificates-issued.php`) — search/filter all issued certificates, view stats, revoke/restore, reissue, regenerate, delete, and issue certificates manually
- **Admin → Guides → edit a guide** — per-guide "Certificate Settings" panel (minimum completion %, minimum quiz score, require final quiz, require premium, allow re-download, expiration)

---

## 9. Notes / things worth knowing

- PDF generation is client-side (html2pdf.js via CDN) rather than a
  server-side PHP PDF library, since InfinityFree shared hosting has no
  Composer/SSH access to install one. Print (`window.print()`) remains
  available as a fallback.
- QR codes are generated via a public QR image API
  (`api.qrserver.com`) rather than a bundled PHP QR library, for the same
  reason.
- "Allow re-download" is enforced at the app level (a flag stored per
  issued certificate) — after the first download, the Download button is
  hidden if a guide (or manual issuance) had re-download disabled. Print
  is always available as a fallback for the certificate owner.
