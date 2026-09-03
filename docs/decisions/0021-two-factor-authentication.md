# 0021: TOTP two-factor authentication

Date: 2026-09-03

Status: Accepted

## Context

Wayfindr's dashboard login accepts only an email address and password. That is
out of step with the audited, scoped operator-access controls behind it and is
the smallest independently useful part of
[#761](https://github.com/adamgreenwell/wayfindr/issues/761). OIDC, just-in-time
provisioning and custom roles remain later slices; they should not hold up a
second factor that works for every hosted or self-hosted install.

This decision fixes the enrollment, challenge, recovery, enforcement and
storage boundaries before credentials are added to the schema.

## Decision

### TOTP is the first second factor

Wayfindr uses RFC 6238 TOTP with the conventional SHA-1, six-digit, 30-second
profile supported by common authenticator applications. Verification accepts
one adjacent timestep for modest clock drift. The application uses the audited
`pragmarx/google2fa` implementation instead of maintaining OTP cryptography.
Provisioning QR codes are rendered locally with `bacon/bacon-qr-code`; setup
never sends a secret to a remote chart or QR service.

The TOTP secret is generated at 160 bits, retained only after a valid code
confirms enrollment, and encrypted with the application key at rest. A pending
secret is encrypted before it enters the session store. The profile shows a QR
code and the Base32 value so enrollment still works with a camera-less or
air-gapped authenticator.

### Password acceptance is not dashboard authentication

For a TOTP-enabled user, a valid password creates a short-lived pending-login
record in the session and then logs the guard back out. It does not create an
authenticated dashboard session. The second-factor challenge is rate-limited,
expires after five minutes, rechecks that the user is active, and authenticates
the guard only after a valid TOTP or unused recovery code.

The intended dashboard URL and remember-browser choice survive the challenge.
The session identifier is regenerated at both the password and completed
challenge boundaries. Errors do not reveal whether a submitted value was a
TOTP or recovery code.

Successful TOTP verification stores the accepted timestep under a row lock and
requires every later code to be newer. Two simultaneous challenges therefore
cannot reuse the same code, even while that code remains inside the accepted
clock window.

### Recovery codes are one-time credentials

Enrollment creates eight random recovery codes and displays them once. Only
slow one-way hashes are stored. Using a recovery code removes its hash in the
same locked transaction that accepts the challenge, so concurrent reuse cannot
succeed. An authenticated user can regenerate the entire set after proving
their password and current second factor; the previous set stops working
immediately.

Disabling TOTP also requires the password and current second factor. It is
refused while the account policy requires two-factor authentication. Enrollment,
recovery-code use, regeneration and disablement are audited without recording a
secret, code, provisioning URI or QR payload.

### Account enforcement is immediate and fail closed

An account administrator may require two-factor authentication only after
enrolling their own factor. Once required, every active member without a
confirmed factor is restricted to their profile's enrollment flow and logout.
This applies to existing sessions on their next request as well as new logins;
there is no password-only grace path that silently weakens the policy.

The policy does not grant, widen or satisfy platform break-glass access.
Platform operator authority remains a separate instance-level role governed by
ADR 0008. An operator who is also a member of an account with required TOTP
must still satisfy that account's normal session policy before using either the
dashboard or operator surface. A future federated login must enter the same
post-authentication policy boundary rather than treating an identity-provider
claim as break-glass authority.

### Session and diagnostic storage do not retain submitted codes

TOTP and recovery-code fields join passwords and infrastructure secrets on the
framework's do-not-flash list. Pending secrets and the once-only recovery-code
display are encrypted before database-backed session storage. Audit metadata,
logs and validation errors contain lifecycle facts only.

## Consequences

- Every install gains a second factor without depending on an external identity
  provider.
- Authenticator setup works offline, but servers must keep reasonably accurate
  time.
- Losing both the authenticator and all recovery codes requires an explicit
  operator-assisted recovery procedure; no hidden bypass is added here.
- OIDC can later replace the password step for federated users, but it cannot
  bypass account enforcement or platform break-glass rules.
- Self-hosted PHP images now require GD for local QR rendering.
