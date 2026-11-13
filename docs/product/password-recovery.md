# Password recovery

An agent who forgets their password recovers it themselves.

Before this existed, recovery meant an operator with production shell access
running an artisan command against the database on the agent's behalf — the
routine privileged action that the break-glass and audit work exists to make
unnecessary.

## The flow

1. **Sign in → Forgotten your password?**
2. An email arrives with a single-use reset link that expires.
3. The link sets a new password, and signs that account out everywhere else.

Delivery uses this install's configured mail, so it honours the operator mail
settings and shows up in the same readiness checks.

## Keeping reset credentials out of request URLs

New recovery notifications use a fixed `/reset-password` page. The credential
and email are carried in its browser fragment, with this exact contract:

```text
/reset-password#v=1&token={64 lowercase hexadecimal characters}&email={RFC3986-encoded email}
```

Fields appear in that order. Duplicate or unknown fields, another version,
noncanonical encoding, malformed escapes, and query/path credential fallbacks
are refused. The decoded email must fit the reset form's email validation and
254-character limit. JavaScript is required for this flow; the form starts
disabled when the script is unavailable.

If a reset link is opened in an already-signed-in browser, this fixed landing
still renders so it can clear the fragment. It refuses submission and asks the
person to sign out, then reopen the original link. Authenticated visitors do
not receive password-reset authority.

The first-party script clears the fragment before decoding it, keeps the token
only in page memory, and submits it in a same-origin JSON **POST body** to the
existing `/reset-password` endpoint, with the normal session and CSRF protection.
It does not put the token in a hidden field, browser storage, history, console
output, redirect, or request URL. Refreshing the cleared page loses this memory;
reopen the original protected link if necessary. The landing and reset responses
use `no-store` and `no-referrer`. A fragment does not travel in the HTTP request
URL, but the original link remains a credential wherever it is delivered or
retained. Operators still need to exclude request bodies and other credentials
from their logging and monitoring.

An exact JSON success confirms completion and offers the normal sign-in link.
A known validation refusal lets the person correct the form deliberately, with
the same token still in memory. An expired CSRF form, redirect, malformed
response, timeout, or other ambiguous outcome disables submission without an
automatic retry. If completion is uncertain, try signing in or request a new
link. Passwords and reset tokens are never flashed into the session on a
validation error.

Legacy `/reset-password/{token}?email=…` links remain supported for compatibility;
they still carry their credential in the request URL. Custom notification URL
callbacks remain supported and own their destination's privacy behavior. The
fragment flow retains the existing broker's expiry and single-use behavior,
password rules, session revocation, and audit events.

## What it deliberately will not tell you

**Whether an address exists.** The request form answers identically for a real
agent and an unknown address, and the reset form gives one message for an expired
token, a wrong token, and an unknown address alike.

The link is **queued rather than sent inside the request**, which is part of the
same guarantee and not only a performance choice. Sending inline meant a known
address paid for an SMTP round trip while an unknown one returned immediately —
a measurable difference — and an SMTP failure turned a known address into a 500
while an unknown one still got the generic answer.

This matters more here than on a single-tenant product: the login page is public
and one install carries many accounts, so a form that confirms which addresses
are real hands over the agent roster of every site on it.

## Ending the access that prompted the reset

A reset that leaves existing sessions alive is not a recovery — it is a second
key cut for whoever already had one. Completing a reset:

- rotates the remember-me token, which invalidates remember-me cookies, and
- deletes that agent's server-side session rows.

Only that agent's sessions; other people stay signed in.

A password reset does **not** disable or satisfy two-factor authentication. An
enrolled agent still needs their authenticator or an unused recovery code on
the next sign-in.

> Session deletion applies to the **database** session driver, which is what
> `.env.example` ships and what the Compose stack runs. Other stores key sessions
> by id alone with nothing to select a user's rows by, so on those the token
> rotation is what applies.
>
> The delete runs on `session.connection`, not the default database connection.
> `SESSION_CONNECTION` can point elsewhere, and deleting from the default would
> succeed against an empty table while the real sessions stayed valid — the
> guarantee failing silently.

## Rate limiting

Two separate quotas, deliberately.

**Requesting a link** is throttled on both the address and the source. Keying on
the address alone would let an attacker deny an agent their own recovery; keying
on the source alone would let a distributed attacker farm one address.

**Submitting a reset** has its own quota, keyed on the source only. Sharing one
bucket meant an attacker could spend an agent's completion allowance simply by
requesting links for their address — the agent's valid token would be refused
before it was read, and the attack repeats every window. An address-keyed bucket
on this half *is* the denial, which is why it is not used here.

## Audit

Both halves are recorded against the account — `password_reset.requested` and
`password_reset.completed`.

Nobody is authenticated during a reset, so there is no actor to record. The
account is what matters: an admin has to be able to see that somebody asked.

Requests for addresses that do not exist write nothing, so the audit log cannot
be used to enumerate the roster either.

## Operator dependency

**Password reset only works if mail is configured.** An install that skipped mail
setup has no recovery path at all. Configure it at `/operator` → **Mail**, where
the test button confirms delivery before anybody needs it.

## Not covered here

- **SSO and federated identity recovery.** TOTP two-factor authentication has
  its own [enrollment and recovery-code flow](two-factor-authentication.md).
- **Email address verification on invite.**
- **Account lockout policy.**
