<?php
/**
 * Web3Edu — CSRF Protection (shared helper)
 *
 * Generalizes the one-token-per-session pattern that already existed,
 * duplicated inline, only in admin/maintenance.php ($_SESSION['_maint_csrf']
 * + a local verifyCsrf()). This is the same approach — a random per-session
 * token, compared with hash_equals() — pulled out so every state-changing
 * form can reuse it instead of re-implementing it.
 *
 * Added as part of the 2026-07 Bounty Marketplace production audit, where
 * every bounty form (host applications, bounty submissions, payments,
 * admin approvals) was found to have no CSRF protection at all. Scope for
 * now is the Bounty Marketplace; admin/maintenance.php's own inline version
 * is left as-is (out of scope, and already working correctly).
 *
 * Usage — regular <form method="POST"> pages:
 *   <form method="POST">
 *     <?= csrfField() ?>
 *     ...
 *   </form>
 *   // at the top of the POST handler, before acting on any $_POST value:
 *   requireCsrf();
 *
 * Usage — AJAX/JSON endpoints (api/*.php):
 *   requireCsrfAjax(); // reads $_POST['csrf_token'] same as requireCsrf();
 *                      // the form/FormData just needs csrfField() too.
 *
 * Usage — plain <a href="?action=..."> links that trigger a state change
 * (several admin bounty list actions — approve/feature/archive/delete —
 * were found wired this way, which is GET-based CSRF on top of not being
 * idempotent). Append the token with csrfLink() and check it with
 * requireCsrfGet() before acting:
 *   <a href="?action=archive&id=<?= $id ?>&<?= csrfLink() ?>">Archive</a>
 *   if ($action === 'archive') { requireCsrfGet(); ... }
 */

/** Returns the current session's CSRF token, generating one on first use. */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden <input>, ready to drop inside any <form method="POST">. */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

/** "csrf_token=..." query-string fragment for a plain state-changing <a href> link. */
function csrfLink(): string
{
    return 'csrf_token=' . urlencode(csrfToken());
}

/**
 * Call at the very top of a POST handler, before reading any other
 * $_POST value. Stops the request with a 403 if the token is missing or
 * doesn't match — the same reject-and-stop behavior
 * admin/maintenance.php's verifyCsrf() already used.
 */
function requireCsrf(): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submitted)) {
        http_response_code(403);
        exit('Security check failed — your form session may have expired. Please go back, refresh the page, and try again.');
    }
}

/** Same check for a state-changing GET link built with csrfLink(). */
function requireCsrfGet(): void
{
    $submitted = $_GET['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submitted)) {
        http_response_code(403);
        exit('Security check failed — your session may have expired. Please go back, refresh the page, and try again.');
    }
}

/** Same check for JSON/AJAX endpoints — returns a JSON body instead of an HTML error page, matching those endpoints' existing response shape. */
function requireCsrfAjax(): void
{
    $submitted = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submitted)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Your session token expired. Please refresh the page and try again.']);
        exit;
    }
}
