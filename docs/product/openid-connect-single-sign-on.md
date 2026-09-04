# OpenID Connect single sign-on

Wayfindr accounts can connect one OpenID Connect (OIDC) identity provider for
agent sign-in. OIDC is an additional sign-in path, not a replacement for
Wayfindr's account roles, site access, two-factor policy, or local recovery.

## Configure a provider

An owner opens **Account → Account security** and connects the first provider
by supplying:

- a name agents will recognize;
- the provider's HTTPS issuer URL;
- the OIDC client ID and client secret; and
- whether the connection is enabled.

The page shows the exact callback URL to register with the provider. A saved
secret is never displayed again. Leaving the secret field blank during an
edit keeps the existing value. After the authority exists, an owner or security
administrator may rename it, rotate its secret, enable it, or disable it. Only
an owner may replace the issuer URL or client ID because control of that
authority can assert the email of an existing higher-role account.

Changing the issuer URL or client ID clears existing agent links and role
mappings, and disables just-in-time provisioning. Those values identify a new
authorization authority, so its claim strings cannot inherit the old
provider's meaning. Renaming the provider or rotating only its client secret
keeps the links and mappings.

OIDC discovery and every endpoint it names must resolve to public HTTPS
addresses. Wayfindr refuses local and private network destinations so an
account setting cannot be used to reach infrastructure inside the server's
network.

## Sign in and first link

From the login page, choose **Sign in with SSO** and enter the account slug.
Wayfindr redirects to that account's enabled identity provider and validates
the returned protocol state, nonce, token signature, issuer, audience, and
expiry.

By default, Wayfindr links the provider subject only to an existing, active
agent in that account whose email exactly matches a provider-verified email
claim. Once linked, future sign-ins use the provider subject; a later email
change at the provider cannot move the link to another agent. Existing agents
remain locally role-managed even when provisioning is later enabled.

## Just-in-time provisioning and role mapping

An account owner can configure one top-level role claim and map exact values to
the built-in Agent or Admin role, or an account-owned custom role. Owner is
never available as a federated target. Both a single claim value and an array
such as `groups` are supported.

When provisioning is enabled and no existing agent matches, Wayfindr creates an
agent only when the provider supplies a verified, unused email and exactly one
role target matches. Conflicting role matches reject the sign-in. The new agent
receives no site assignments from the provider, and Wayfindr stores neither the
raw claim set nor provider tokens.

Agents created this way are role-mapped again on later OIDC sign-ins. Removing
a match blocks their next federated sign-in; changing it updates their role,
provided the change would not strand an explicitly assigned site without a
manager. Disabling JIT stops new creation but does not turn off role checks for
already provisioned identities.

Platform operators cannot use account SSO. They sign in with local credentials
so federation cannot become a shortcut into the operator or break-glass
boundary.

## Two-factor and recovery

OIDC replaces the password step only. An agent who has TOTP enabled still
completes the normal Wayfindr challenge. An account's required-TOTP policy also
applies after a federated sign-in.

Local password sign-in and **Forgotten your password?** remain available even
when OIDC is enabled. An identity-provider outage therefore does not remove the
account's explicit local recovery path.

## Deliberate limits

Federation still does not include:

- SAML, SCIM, or LDAP; or
- storing provider access or refresh tokens;
- provider-managed site assignments; or
- automatic deactivation and existing-session revocation when a claim is
  removed.

Those are separate product and authority decisions, not side effects of a
successful login.

The complete security contract is recorded in
[ADR 0022](../decisions/0022-openid-connect-single-sign-on.md) and
[ADR 0024](../decisions/0024-oidc-jit-provisioning-and-role-mapping.md).
