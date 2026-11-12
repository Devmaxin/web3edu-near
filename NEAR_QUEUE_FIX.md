# NEAR issuance queue fix

## Root cause
Nothing ever created a pending `near_credential_issuances` row when a certificate was issued.
`createIssuanceRequest()` was only called from `api/near-request-issuance.php`, i.e. only when a learner
clicked "Request NEAR Issuance" on `certificates.php`. `issueCertificateV2()` / `maybeIssueGuideCertificate()`
never queued anything, so the admin dashboard stayed empty. The manual button was also broken: the page posts a
normal HTML form but the endpoint required a JSON body and answered `400 Invalid JSON body`.

## Changed files (no SQL migration needed)
- includes/certificates.php        – queue hook on every certificate insert (issue + reissue); never breaks issuing
- includes/near-issuance.php       – eligibility + idempotent queue + metadata hash + backfill helpers; VERIFIED blocks re-queue;
                                     errors logged; RPC outage/unseen tx is retryable (no false FAILED); optional NEAR_RPC_URL
- api/near-request-issuance.php    – accepts the form POST (and JSON), shared eligibility rules, flash message
- api/near-issuer-submit.php       – CSRF enforced; same-hash retry allowed; hash can't satisfy two requests
- admin/near-issuance.php          – admin backfill (select + CSRF + server-side recheck); tx-hash form for REQUESTED rows;
                                     args JSON shown for the file-args file
- certificates.php                 – shows the result of the request
- verify-near.php                  – compares on-chain metadata_hash with the local certificate; mismatch is not "Valid"
- tests/near/*                     – dev-only tests (do NOT upload tests/ to production)

## Deploy to InfinityFree
Upload the 7 PHP files above (overwrite). No database change. Do not upload tests/.

## Queue states you will see
- Learner has a linked NEAR wallet -> certificate AND pending NEAR row are created together.
- Learner has NO wallet (email/Google signup) -> certificate is issued normally, NO NEAR row yet (needs a wallet).
  After they link a wallet, use "Existing Certificates Not Yet Queued" on /admin/near-issuance.php.

## Issue a real testnet credential (manual, not tested live here)
1. Log in as the admin whose id equals NEAR_ISSUER_ADMIN_ID, open /admin/near-issuance.php.
2. Click "Show CLI Command" on the row; save the shown JSON to the file-args path in the command.
3. Run the command locally (near-cli-rs, issuer key in your keychain).
4. Paste the resulting transaction hash into the row and Submit. Status becomes VERIFIED only after the PHP
   server re-reads the transaction and the on-chain credential and everything matches.
   "RPC unavailable / not yet visible" leaves it UNCERTAIN - submit the same hash again shortly.
5. Verify publicly: /verify-near.php?id=<near_credential_id>

## Local tests (throwaway DB only)
    . tests/near/bootstrap-env.sh
    php tests/near/queue-test.php
    bash tests/near/http-test.sh      # uses tests/near/mock-rpc.php, NOT the real network
