# Password recovery

An agent who forgets their password recovers it themselves.

Before this existed, recovery meant an operator with production shell access
running an artisan command against the database on the agent's behalf — the
routine privileged action that the break-glass and audit work exists to make
unnecessary.

## The flow

1. **Sign in → Forgotten your password?**
2. An email arrives with a signed link that expires.
3. The link sets a new password, and signs that account out everywhere else.

Delivery uses this install's configured mail, so it honours the operator mail
settings and shows up in the same readiness checks.

## What it deliberately will not tell you

**Whether an address exists.** The request form answers identically for a real
agent and an unknown address, and the reset form gives one message for an expired
token, a wrong token, and an unknown address alike.

This matters more here than on a single-tenant product: the login page is public
and one install carries many accounts, so a form that confirms which addresses
are real hands over the agent roster of every site on it.

## Ending the access that prompted the reset

A reset that leaves existing sessions alive is not a recovery — it is a second
key cut for whoever already had one. Completing a reset:

- rotates the remember-me token, which invalidates remember-me cookies, and
- deletes that agent's server-side session rows.

Only that agent's sessions; other people stay signed in.

> Session deletion applies to the **database** session driver, which is what
> `.env.example` ships and what the Compose stack runs. Other stores key sessions
> by id alone with nothing to select a user's rows by, so on those the token
> rotation is what applies.

## Rate limiting

Throttled on **both** the address and the source. Keying on the address alone
would let an attacker deny an agent their own recovery; keying on the source
alone would let a distributed attacker farm one address.

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

- **Two-factor authentication and SSO** — both real, both Tier 2, and neither
  should hold recovery up.
- **Email address verification on invite.**
- **Account lockout policy.**
