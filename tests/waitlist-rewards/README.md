# Waitlist Rewards tests

**Never run these against the live database.** They delete users, XP and reward rows. `run.php` refuses unless `DB_NAME` contains `test`.

1. Make a copy of the project (without `uploads/`) in a scratch directory.
2. Create an empty throw-away database with `test` in its name (utf8mb4). Load the project's migrations: `database/web3edu_v2.sql`, then `phase2-migration`, `phase2-notification-center-migration`, `phase3-migration`, `phase3-ai-mentor-migration`, `migrate-xp-levels`, `gamification-phase1/2/3-migration`, `certificates-v2-migration`, `career-paths-migration`, `login-throttle-migration`, `subscription-audit-migration`, `analytics-phase1-migration`, `premium-lifetime-removal-migration`, `migrate-remove-courses`, and finally `waitlist-rewards-001-schema.sql` (some of those report harmless "already exists" errors; use `--force`).
3. In the COPY, replace `secrets/secrets.php` with a file defining `DB_HOST`, `DB_NAME` (the test database), `DB_USER`, `DB_PASS`, `PAYSTACK_SECRET_KEY`, `RESEND_API_KEY`, `CRON_SECRET`, `GOOGLE_CLIENT_SECRET` (dummy values for the last four).
4. `php tests/waitlist-rewards/run.php`

Needs PHP 8.1+ with `pdo_mysql` and `curl`. It starts PHP's built-in web server on a random local port for the HTTP section, and spawns parallel PHP processes for the concurrency section. Google's real OAuth round-trip is not testable offline; the Google section exercises the real account-creation function and the shared hook.
