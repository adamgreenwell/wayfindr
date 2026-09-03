# Two-factor authentication

Wayfindr supports time-based one-time passwords (TOTP) for every dashboard
agent. It works with standard authenticator apps and does not depend on an
external identity provider or a remote QR-code service.

## Enrolling

Open **Agent profile → Two-factor authentication**, confirm the current
password, and scan the QR code. Enter the app's current six-digit code to
finish. The QR image is generated inside Wayfindr; the TOTP secret is not sent
to another service.

Enrollment creates eight recovery codes. They are shown once and should be
stored away from the device that holds the authenticator. Wayfindr retains only
slow one-way hashes of those codes, so an administrator cannot reveal them
later.

## Signing in

A TOTP-enabled agent signs in in two steps:

1. Wayfindr accepts the email address and password, but does not create an
   authenticated dashboard session or remember-browser cookie.
2. The agent enters a current TOTP or one unused recovery code. Only then does
   Wayfindr authenticate the session and restore the intended dashboard page
   and remember-browser choice.

The challenge expires after five minutes, is rate-limited, and rechecks that
the agent remains active. Each accepted TOTP timestep is recorded under a row
lock, so the same code cannot be replayed in a second request. A recovery code
is removed atomically when it succeeds.

## Recovery and changes

An enrolled agent can replace all recovery codes from their profile after
providing both the current password and a current TOTP or unused recovery code.
The old set stops working immediately. Disabling two-factor authentication
requires the same two proofs.

Password reset does not remove or bypass a second factor. Losing both the
authenticator and every recovery code therefore needs an explicit
operator-assisted recovery procedure; Wayfindr does not add a hidden fallback
credential.

## Requiring it for an account

An owner or administrator can open **Account → Account security** to see how
many active agents are enrolled and require two-factor authentication for the
whole account. The administrator must enroll their own authenticator before
turning the requirement on.

Once enabled, an unenrolled agent's existing and new sessions can reach only
their profile enrollment flow and logout. The restriction applies to the
operator console too when a platform operator is also a member of that
account. It does not grant or satisfy break-glass authority.

## Storage and audit boundary

- TOTP secrets are encrypted with the application key at rest.
- Pending enrollment secrets and the one-time recovery-code display are
  encrypted before entering the session store.
- Submitted authentication and recovery codes are excluded from validation
  input flash.
- Enrollment, recovery-code use, recovery-code replacement, disablement, and
  account-policy changes are audited without secret or code material.

Self-hosted PHP runtimes need `ext-gd` for local QR rendering. The supported
Docker image includes it, and Composer refuses an install where it is missing.
Servers should also keep accurate system time because TOTP depends on it.

The complete security contract and future federation boundary are recorded in
[ADR 0021](../decisions/0021-two-factor-authentication.md).
