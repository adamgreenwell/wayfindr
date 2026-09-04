# 0024: OIDC just-in-time provisioning and role mapping

Date: 2026-09-03

Status: Accepted

## Context

ADR 0022 deliberately limited OpenID Connect to verified-email linking for an
existing Wayfindr agent. ADR 0023 then established account-owned custom roles
and a stable database identity for each permission set. Issue
[#761](https://github.com/adamgreenwell/wayfindr/issues/761) calls for the next
step: create eligible agents and select their role from an identity-provider
claim without maintaining the same membership twice.

Federated provisioning is an authorization mutation, not merely another OIDC
setting. A security administrator who can rotate a client secret must not be
able to mint a more powerful Wayfindr principal, and a shared realtime payload
must not carry private claims just to make mapping convenient.

The same reasoning applies one step earlier: whoever replaces the issuer or
client ID controls an authority that may assert a verified email for an
existing owner. Establishing or changing that authority is owner-only. An
ordinary security administrator may still rename, rotate the secret, enable,
or disable an existing connection.

## Decision

### Owners define exact, deny-by-default mappings

Only an account owner, through the non-delegable `manage_roles` permission, may
choose the top-level OIDC role claim or create and remove mappings. Each exact
scalar or array value may target the built-in Agent or Admin role, or one
account-owned custom role. Owner is never a federation target.

Mapping rows hold a foreign key to custom roles, so renaming a role does not
change its identity and a mapped role cannot be deleted accidentally. Mapping
and provisioning changes rotate the OIDC configuration version, invalidating
callbacks that began under an older authorization contract. Changing the
issuer or client ID clears identity links and mappings together and disables
provisioning; claim strings from a new authority cannot inherit the old
authority's meaning.

No match denies provisioning. Multiple matches are accepted only when every
matched value resolves to the same role; conflicting targets deny the sign-in.

### Existing local agents stay locally administered

A verified email may still link an existing same-account agent, exactly as ADR
0022 specifies. That identity is not marked as provisioned, and provider claims
never rewrite its local role.

When no local agent matches, enabled provisioning may create one only from a
provider-verified, globally unused email and one unambiguous role mapping. The
provider's display name is used when available. Wayfindr generates an unknown
local password; the ordinary password-reset path remains the explicit recovery
mechanism. No provider token or raw claim set is stored or logged.

### Provisioned identities stay mapped at federated sign-in

A user and identity created through JIT retain provisioning timestamps. Keeping
the user-level provenance means an issuer/client reset may discard the unsafe
provider-subject binding without silently turning that JIT-created account into
a locally role-managed one. Every later OIDC link and sign-in for that user must
still resolve one unambiguous mapping, even when creation of new users has since
been disabled. A changed mapping updates the role inside the account
serialization boundary, preserves explicit site-manager coverage, audits the
old and new roles, and requests durable realtime-session eviction.

Site assignments are never inferred from identity-provider claims. Permissions
still answer what an agent may do and site access still answers where support
data is visible.

### This is not deprovisioning

Disabling JIT stops new user creation. A removed or conflicting mapping blocks
the next federated sign-in for a JIT-managed identity but does not deactivate
the Wayfindr user, terminate every existing application session, or remove the
local recovery path. Immediate removal remains an explicit Wayfindr
deactivation. Automated lifecycle removal belongs to a future SCIM decision.

## Consequences

- Organizations can create and remap ordinary agents from existing IdP groups
  without giving the provider account ownership or site-assignment authority.
- Locally administered OIDC identities remain backward compatible.
- Claim outages and ambiguous group membership fail closed without silently
  deleting users or rewriting their last valid role.
- SAML, SCIM, nested claim expressions, and site mapping remain out of scope.
