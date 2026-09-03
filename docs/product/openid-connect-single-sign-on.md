# OpenID Connect single sign-on

Wayfindr accounts can connect one OpenID Connect (OIDC) identity provider for
their existing agents. OIDC is an additional sign-in path, not a replacement
for Wayfindr's account roles, two-factor policy, or local recovery.

## Configure a provider

An owner or administrator opens **Account → Account security** and supplies:

- a name agents will recognize;
- the provider's HTTPS issuer URL;
- the OIDC client ID and client secret; and
- whether the connection is enabled.

The page shows the exact callback URL to register with the provider. A saved
secret is never displayed again. Leaving the secret field blank during an
edit keeps the existing value.

OIDC discovery and every endpoint it names must resolve to public HTTPS
addresses. Wayfindr refuses local and private network destinations so an
account setting cannot be used to reach infrastructure inside the server's
network.

## Sign in and first link

From the login page, choose **Sign in with SSO** and enter the account slug.
Wayfindr redirects to that account's enabled identity provider and validates
the returned protocol state, nonce, token signature, issuer, audience, and
expiry.

Wayfindr does not create a user during this flow. On the first successful
callback, it links the provider subject only to an existing, active agent in
that account whose email exactly matches a provider-verified email claim. Once
linked, future sign-ins use the provider subject; a later email change at the
provider cannot move the link to another agent.

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

This first federation slice does not include:

- automatic user creation;
- provider-claim role mapping;
- custom roles;
- SAML, SCIM, or LDAP; or
- storing provider access or refresh tokens.

Those are separate product and authority decisions, not side effects of a
successful login.

The complete security contract is recorded in
[ADR 0022](../decisions/0022-openid-connect-single-sign-on.md).
