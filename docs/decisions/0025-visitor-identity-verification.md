# 0025: Visitor identity verification

Date: 2026-09-21

Status: Accepted

## Context

A host page may tell Wayfindr who its visitor is, by passing
`visitorExternalId` to the widget. That value reaches the server through the
widget's public endpoints, which authenticate nobody: the site's public key is
published by design and the anonymous id is displayed in the dashboard. So an
external id has always been a **claim by whoever is calling**, and the widget
README says as much — "it is never checked, so its unguessability is yours to
guarantee".

The product nonetheless treated it as identity. `VisitorLabel::forVisitor()`
ranks `external_id` third in the naming chain, **above** `anonymous_id`, so
wherever a visitor has no name or email the external id *is* the name an agent
reads, across every surface that names a visitor. An agent looking at
`customer-4821` could not tell a claim from a fact.

Two consequences followed from first-come-first-served exclusivity over an
unauthenticated channel, both reproduced before this work:

- **Claiming denied the real customer.** A caller with only the public site key
  could present any unheld identifier; it was recorded against their visitor,
  and the real customer's browser was refused it from then on.
- **The response was an oracle.** `identified` answered whether the id had been
  free, so the same caller could enumerate which customer ids exist.

Relaxing the exclusivity looks like the fix and is not.
`unique(['site_id', 'external_id'])` is live and has been since the table was
created, so duplicates raise an integrity violation on the endpoint every widget
calls at page load. And because the id is the displayed name, permitting
duplicates would let an attacker be *shown to an agent as* the customer. It
trades a denial of identity for an easier impersonation.

An unauthenticated claim cannot be made safe by choosing a different tie-break.
Any exclusivity rule is claimable by whoever asks first; no exclusivity rule
lets everyone claim everything. The claim has to be proved.

## Decision

### The host signs the identifier with a per-site secret

Wayfindr issues each site a secret. The host computes
`hash_hmac('sha256', $externalId, $secret)` **on its own server** and sends it
beside the id. The server recomputes and records the id only when it verifies.
This is the mechanism Intercom, Crisp and Chatwoot all ship.

The secret is encrypted at rest, revealed once, and never sent to a browser.
Computing the hash in page JavaScript would require the secret in page
JavaScript, which is the one way to get this wrong that still appears to work,
so the documentation says so in bold rather than in passing.

### New sites verify; sites that already existed do not

This asymmetry is the decision most likely to read as an inconsistency later,
and it is deliberate.

A site that already exists may have pages in production sending an unsigned
identifier today. Turning verification on for it would stop identifying every
one of that host's customers — silently, on upgrade, with the symptom appearing
in the dashboard rather than in any log. A site being created now has no such
pages, so the secure default costs nobody anything.

**Do not "fix" this by making the default uniform.** Making existing sites
verify breaks live installs on upgrade. Making new sites permissive gives up the
only opportunity to be secure by default.

A new site is created with the mode set and **no secret**, which fails closed:
identifiers are ignored until an operator issues one. A secret minted at
creation and never shown would be dead, because the plaintext exists for a
single response and nobody is reading that one.

### An unverified identifier is ignored, not refused

When a site requires verification, an id arriving without a valid hash is
dropped and the visitor is simply anonymous. It is not an error the caller can
read.

Refusing loudly would rebuild the oracle: any response that varies according to
what the server knows about *other* visitors answers "does this customer exist"
for a caller who cannot otherwise find out. The answer must be the same either
way for anybody without the secret, and it is.

### A secret that cannot be read is another kind of "no"

The column is encrypted, so reading it decrypts, and a ciphertext this install
has no key for throws rather than returning. That state is reachable: it is what
a restore under a rotated `APP_KEY` leaves behind. Uncaught it reached the
public widget endpoint as a 500, so the panel failed to draw for every
identified visitor while anonymous ones were served normally. An unreadable
secret now verifies nothing, exactly as a missing one does.

The key-loss runbook clears the verification **mode** alongside the ciphertext
for the same reason: clearing only the secret leaves a site refusing every
identifier, which is a quiet failure where the loud one was already understood.

### Verification checks what the host signed, not what we store

`VisitorContextSanitizer` trims and truncates to 160 characters, while
validation admits 255. Verifying the sanitised form would fail against a hash
the host had computed correctly, and fail silently — a correct integration
identifying nobody. The presented value is verified; the sanitised value is
stored.

The one exception is whitespace, which Laravel's global `TrimStrings` removes
from request input before any of this runs. It cannot be verified because it
never arrives, and the documentation tells hosts to hash without it.

## Consequences

Verification proves **who is claiming**, not which browsers are the same person.
A second browser presenting a verified id another visitor already holds is still
left without it, because Wayfindr resolves a visitor by `anonymous_id` and
`(site_id, external_id)` is unique. Cross-device recognition still means minting
the same `anonymous_id` from the host's server.

Rotation has no overlap window: issuing a new secret invalidates the old one
immediately, so pages still holding it identify nobody until they are
redeployed. Two live secrets would need somewhere to put the second, and that is
worth building deliberately rather than implying.

Identifiers claimed before a site turned verification on are unverified by
definition and are left as they are. Nothing records, on the visitor row,
whether a stored identifier was verified — so nothing downstream can tell the
two apart after the fact.

That last point is what blocks the open question this ADR does not answer: with
verification **off**, an unverified identifier is still displayed to agents as
the visitor's name with no qualification. Marking it as a claim would touch
every naming surface and needs the verified-ness recorded first.
