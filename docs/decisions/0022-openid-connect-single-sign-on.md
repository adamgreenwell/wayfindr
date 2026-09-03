# 0022: Account-owned OpenID Connect single sign-on

Date: 2026-09-03

Status: Accepted

## Context

Wayfindr has a strong account and platform-operator security boundary, but its
only primary authentication credential is a local password. The next
independently useful part of
[#761](https://github.com/adamgreenwell/wayfindr/issues/761) is OpenID Connect
(OIDC) federation for organizations that already operate an identity provider.

This slice must not silently include the much larger provisioning and
authorization project in the same login callback. It also cannot let an
account administrator turn Wayfindr into a network probe by supplying an
arbitrary issuer URL.

## Decision

### One account owns one OIDC connection

An account owner or administrator may configure one OIDC connection with a
display name, issuer URL, client ID, client secret, and enabled state. The
client secret is encrypted at rest, hidden from serialization, never rendered
back into the form, and replaced only when a new non-empty value is submitted.
Configuration changes are audited without credentials.

The callback URL is stable for the connection. The public login page asks for
an account slug before beginning federation; it never accepts an email address
as an account-discovery mechanism.

### Discovery is allowed only through a guarded HTTP client

Wayfindr uses `socialiteproviders/openidconnect` for discovery, authorization,
PKCE, state, nonce, token exchange, ID-token verification, and user claims. It
does not implement OIDC cryptography itself.

Every discovery, token, key, and user-info request uses Wayfindr's guarded HTTP
client. HTTPS is required. Literal and DNS-resolved loopback, private,
link-local, multicast, documentation, reserved, and otherwise non-public
addresses are refused for every outbound request, including endpoints learned
from discovery. Server-side redirects are refused rather than followed; the
browser authorization destination is validated separately. Self-hosters who
need a private identity provider must expose a
publicly resolvable HTTPS endpoint; there is no account-controlled bypass.

### Federation identifies; it does not provision or authorize

The first successful callback may link an identity only when all of these are
true:

- the provider returns a stable non-empty `sub` and a verified email claim;
- an active, already-existing user in that same account has the normalized
  email address;
- the user is not a platform operator; and
- neither that provider subject nor that account user is already linked to a
  different identity.

The binding is the OIDC connection plus subject, never the email address.
Later logins resolve only through that binding, so a changed provider email
cannot move an identity to another Wayfindr user. Database uniqueness and a
transaction make concurrent first links fail closed.

The callback never creates users, changes account roles, maps claims to roles,
or grants platform authority. Just-in-time provisioning, role mapping, custom
roles, SAML, SCIM, and LDAP remain separate decisions.

### Local security policy still owns the resulting session

A federated identity replaces only the password step. A linked user with TOTP
still enters the same short-lived second-factor challenge, and an account that
requires TOTP still restricts an unenrolled user to enrollment. Disabling or
changing the OIDC connection after authorization starts invalidates the
callback or pending second-factor challenge.

Platform operators cannot use account OIDC. Their instance authority remains
behind the local password, TOTP, and break-glass boundaries. Local password
login and password recovery remain available for every account, including one
with OIDC enabled; federation is not the only recovery key.

No OIDC access token, refresh token, ID token, authorization code, PKCE
verifier, or provider claims are stored in the database or audit log. The
short-lived protocol state lives only in the session and is removed after the
callback.

## Consequences

- Existing account members can use a standards-based identity provider without
  duplicating roles or weakening account TOTP.
- Accounts must create users locally before those users can link through OIDC.
- A provider must assert a verified email for the first link; providers without
  that claim need an explicit administrator-led linking design later.
- Platform operators always retain and use local credentials.
- Private-network-only identity-provider endpoints are not supported by this
  account-configured flow because preventing server-side request forgery takes
  precedence over that topology.
- SAML, provisioning, claim-to-role mapping, and custom roles remain open parts
  of issue #761.
