# Headless owner bootstrap

`wayfindr:bootstrap-owner` initializes a **fresh, private installation** with an ordinary account Owner, an initial site, and a short-lived password-setting claim. The owner has no platform operator role. The existing `wayfindr:bootstrap` command and browser setup retain their normal self-hosting behavior.

Run migrations first. Keep public web, workers and other provisioning writers stopped during initialization. Create an operator-owned directory with mode `0700`, outside the application's storage, web root, backup inputs and runtime mounts. Use a new filename and a stable canonical UUID:

```sh
php artisan wayfindr:bootstrap-owner \
  --request-id=64b764b2-552c-49a0-bf5f-25277b51d590 \
  --email=owner@example.test \
  --claim-file=/private/operator-owned-directory/owner-claim.json \
  --account="Example Support" --name="Example Owner" --site="Example Site"
```

Configure `APP_URL` as the exact public HTTPS origin, with no trailing slash, path, credentials, query or fragment. HTTP loopback origins are accepted only in local/testing environments. This command supports the application's Eloquent User provider and database password broker on the **same database connection** as the account records, with an integer expiry of 1–60 minutes. It uses the existing password-reset routes, token store, password validation and single-use reset behavior. It sends no email or notification. Ordinary later password recovery remains the application's normal recovery flow.

The claim file contains the reset URL and must be treated as a credential. It is exclusively created with mode `0600` in the owned `0700` directory; existing files, symlinks, unexpected ownership, links and changed file identities are refused. The command requires POSIX ownership inspection and file `fsync`. It writes and flushes the claim before committing the database transaction and checks the open file, path and parent identities before and after. The directory must be private and controlled by the operator: these checks cannot exclude a hostile process running under that same OS identity.

Standard output contains one nonsecret JSON receipt with stable request/resource IDs and the original expiry, never a reset token, URL or password. The initial unknown random password is not delivered. Deliver the protected claim to the intended owner through your own secure handoff. Avoid printing it in deployment logs. Allow web access only after the initial readiness gate, then have the owner follow the link and choose a password before its expiry. Verify normal login and denial of `/operator`; issuance alone does not prove a completed claim or a working hosted service.

The version1 claim document and its original `password_reset_url` are unchanged for compatibility with existing receipt/custody integrations. That original legacy URL contains its token in the path. An integration that requires credentials to stay out of HTTP request URLs must validate the protected claim first, then present a derived URL on the same admitted origin using the [fragment reset contract](../product/password-recovery.md#keeping-reset-credentials-out-of-request-urls). Do not navigate to the original token-bearing URL as an intermediate redirect. Preserve the original claim bytes, receipt, hash, token and expiry; deriving a presentation URL neither reissues the claim nor proves it was consumed. The destination must run a release that supports the fragment landing. Ordinary new password-recovery notifications use that landing automatically.

## Retry and uncertain outcomes

All supported headless requests compete for one uniquely keyed, locked database row. A completed exact retry returns the original receipt only when its private claim file still matches. It never rotates or reissues a token, including after expiry or use. A changed request, different UUID, existing installation, missing/changed claim or an enclosing database transaction is refused.

A filesystem and database commit cannot be one atomic operation. Failure or ambiguity after exclusive file creation returns `owner_bootstrap_outcome_unknown` and preserves the file, even if it is empty. A lost database acknowledgement may leave committed records and a valid private claim; a rollback may leave only the file. Do not delete the file, overwrite records, change the UUID or blindly retry to clear this condition. Reconcile the protected file and durable database receipt using an operator procedure. This command deliberately provides no force, cleanup or token-reissue option. Fixed failure codes carry no underlying exception text; inspect the known resources carefully without disclosing the claim.

The singleton reservation serializes this command's callers. It does not coordinate the separate self-host browser installer or legacy bootstrap command: keep those writers unavailable until initialization is complete. Normal setup is then closed by the account-scoped owner record.

## Initial readiness status

```sh
php artisan wayfindr:bootstrap-owner --status \
  --request-id=64b764b2-552c-49a0-bf5f-25277b51d590
```

Status is read-only: it accesses neither claim files nor reset tokens and sends nothing. It separates the durable completed bootstrap receipt from the currently observed initial account/owner/site binding. Default exit zero means those initial bindings currently remain ready, including no operator grant; it does not mean the claim was consumed, the owner has signed in, or the caller has management authority. Missing tokens do not prove consumption. Use the default status as an **initial** activation gate, not a perpetual restriction on legitimate later ownership transfers or agent changes. Later changes do not erase the initial receipt.

After initial activation has been independently accepted, a subsequent runtime startup can use:

```sh
php artisan wayfindr:bootstrap-owner --status --completion-only \
  --request-id=64b764b2-552c-49a0-bf5f-25277b51d590
```

`--completion-only` still requires the exact request's valid durable completed receipt. It returns the current `initial_binding_ready` observation unchanged, including `false` after a legitimate ownership transfer, but its exit status does not require the original owner/site binding to remain in place. It does not grant management authority, prove a current login or claim, reissue credentials, or replace the strict first-activation check. The flag is refused without `--status`.
