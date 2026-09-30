# Data Responsibility

Wayfindr is self-hostable software. The open source project can provide safe
defaults, clear docs, and conservative product boundaries, but the person or
organization operating a Wayfindr installation is responsible for how visitor
data is collected, retained, secured, disclosed, exported, and deleted.

This document is not legal advice. It is the project baseline for how Wayfindr
talks about user-supplied data and the responsibility self-hosters take on when
they run the platform.

## Project Stance

- Collect only the support context needed to help a visitor.
- Mask sensitive values in the browser before cobrowse data leaves the visitor
  page.
- Require explicit visitor consent before cobrowse page state is shared.
- Make retention choices visible and deliberate.
- Keep AI optional, scoped, and bound by the same masking and retention rules as
  the normal product workflow.
- Document data behavior publicly so operators can make informed decisions.

## Operator Responsibility

When an organization self-hosts Wayfindr, that organization controls the
installation, database, logs, backups, environment variables, integrations,
agents, privacy notices, retention settings, and incident response. Depending
on local law and the operator's relationship with visitors, the operator may be
acting as a controller, processor, business, service provider, or another local
equivalent.

This operator responsibility is distinct from account roles inside the product.
An account owner or admin can manage tenant support settings, but platform or
instance authority should remain separate from normal support access. See
[Platform Operator Boundary](../product/platform-operator-boundary.md) for that
product boundary.

Wayfindr should remind operators that:

- visitor messages, cobrowse metadata, tickets, audit logs, and attachments can
  include personal data;
- retaining that data may create legal, security, and operational obligations;
- privacy notices should match the actual Wayfindr configuration;
- long retention windows should be justified, protected, and periodically
  reviewed;
- deletion, export, and access workflows need to be planned before a production
  support team depends on the installation.

## Product Reminder Copy

Use this copy, or a close variation, anywhere Wayfindr lets an administrator
configure data retention:

> Retaining visitor-supplied data may create privacy, security, and legal
> obligations. Keep only what you need, set a retention period you can justify,
> and make sure your privacy notice matches how this Wayfindr installation is
> used.

When an option keeps data indefinitely or for a long window, the UI should ask
for an explicit acknowledgement instead of hiding the impact behind a normal
save button.

## A Request About One Person

When a visitor asks the operator to delete what is held about them, the
operator can erase that contact from its profile in the dashboard, with the
`handle_data_requests` permission
([how it works](../product/visitor-contact-management.md#erasing-a-contact)).
The summary before confirmation lists what Wayfindr cannot reach. The operator
remains responsible for those places:
- backups taken before the erasure;
- linked external issue trackers;
- mail already sent;
- the AI provider;
- API integrations;
- logs.

The receipt reference shown afterwards identifies the erasure without naming
the person, for the operator's reply and records.

Restoring a backup taken before an erasure undoes it, until a later release
makes restore re-apply erasures. Keep receipt references outside Wayfindr,
and after such a restore erase those people again.

## Retention Defaults

Wayfindr is pre-alpha and does not yet ship complete retention controls. Until
those controls exist, production operators should assume records remain in the
application database, logs, and backups until they are manually removed or their
infrastructure lifecycle removes them.

Future retention controls should be scoped by data class, not one global
cleanup switch:

- conversations and messages,
- tickets,
- cobrowse sessions and metadata,
- audit events,
- visitor identity records,
- uploads and attachments,
- application logs,
- backups and database snapshots.

## Reference Points

Useful public references for the principles behind this stance:

- FTC: Protecting Personal Information, especially taking stock, keeping only
  what is needed, protecting retained data, and securely disposing of data that
  is no longer needed: <https://www.ftc.gov/business-guidance/resources/protecting-personal-information-guide-business>
- EU GDPR Article 5 principles including purpose limitation, data minimisation,
  storage limitation, integrity and confidentiality, and accountability:
  <https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32016R0679>
- ICO storage limitation guidance:
  <https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/data-protection-principles/a-guide-to-the-data-protection-principles/storage-limitation/>
