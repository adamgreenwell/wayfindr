# 0023: Account-owned custom roles

Date: 2026-09-03

Status: Accepted

## Context

Wayfindr has three fixed account roles: Owner, Admin, and Agent. They provide a
safe starting hierarchy, but cannot express ordinary responsibilities such as
an auditor who may inspect reports without changing settings, or a knowledge
manager who may publish articles without administering authentication.

Issue [#761](https://github.com/adamgreenwell/wayfindr/issues/761) calls for
named permission sets while preserving those three roles as defaults. This is
also a prerequisite for mapping identity-provider claims: federation must not
invent an authorization model inside its callback.

## Decision

### Built-in roles remain stable defaults

`users.account_role` remains the lockout-safe Owner/Admin/Agent field. Existing
users keep the same authority after this migration. A user may instead point
to one account-owned custom role; assigning one resets the built-in fallback to
Agent, so deleting a role cannot promote anybody.

Owners retain the non-delegable `manage_roles` permission. Custom roles cannot
grant ownership, role administration, platform-operator authority, or a site
purge. Owners create, edit, assign, and delete custom roles, and may not delete
one while it is assigned.

A custom role with `manage_agents` may create teammates, but those accounts are
created in the issuer's same custom role. The credential handoff therefore
cannot be used to mint a built-in Agent login with broader support or site
creation authority. That manager may suspend or restore same-role teammates,
but cannot cross into another custom role.

### Permissions answer what; site access still answers where

Custom roles store a deny-by-default list of known permission identifiers.
Policies require both the relevant permission and the existing account/site
scope. A permission therefore never widens the sites, conversations, tickets,
visitors, alerts, or cobrowse sessions visible to its holder.

Owner receives every account permission. Admin receives every delegable
permission. Agent receives the existing support-work permissions. These maps
are code-owned defaults, not mutable database rows, so upgrades cannot strand
an account without an owner or silently rewrite its baseline roles.

One pre-RBAC onboarding behavior remains outside that map: a user on any of the
three built-in roles may create a new site and is attached to it immediately.
A custom role must hold `manage_sites` to create one. Editing, archiving, or
restoring an existing site always requires `manage_sites`.

### Changes are immediate and auditable

Permission checks read the currently assigned role. Editing a permission set
therefore affects its users immediately; no permission snapshot is copied onto
the user. Role creation, changes, deletion, and user assignments are audited
with reference-safe names and permission identifiers. Audit history does not
depend on the role row continuing to exist.

`manage_integrations` governs API-token lifecycle, not the support data a token
can use. The coarse `read` and `write` token abilities are available only when
the issuer holds every support permission bundled into the chosen ability.

## Consequences

- Accounts can delegate existing Wayfindr responsibilities without granting
  the entire Admin role.
- Existing Owner/Admin/Agent behavior remains compatible.
- Site assignments remain mandatory for support data, regardless of role.
- Role administration and destructive site purge remain owner-only.
- JIT provisioning and OIDC claim mapping can now target a stable role key, but
  remain a separate change with their own failure and deprovisioning rules.
