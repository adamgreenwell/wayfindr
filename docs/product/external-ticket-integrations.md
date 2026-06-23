# External Ticket Integrations

Wayfindr's ticketing system should be useful on its own before it tries to
mirror another platform. GitHub, GitLab, Bitbucket, Jira, and similar tools may
be excellent destinations for engineering follow-up, but they should not become
the source of truth for Wayfindr's support workflow.

The product stance is:

- Wayfindr owns the support ticket, conversation, visitor context, consent
  history, and audit trail.
- External issue trackers receive explicit, scoped follow-up records when an
  agent chooses to send a ticket outward.
- Provider differences are hidden behind small adapter contracts and capability
  flags, not spread through controllers or views.
- Sensitive visitor data is summarized and redacted before leaving Wayfindr
  unless an operator has explicitly configured otherwise.

## Platform Shape

### GitHub

GitHub Issues are repository-scoped and share API surfaces with pull requests.
GitHub is a strong first integration candidate for open source and developer-led
support because repository issues, labels, assignees, milestones, comments, and
cross-links are familiar.

Planning notes:

- Treat GitHub as a repository issue destination, not a replacement ticket
  store.
- Account for the GitHub API caveat that pull requests can appear through issue
  endpoints.
- Keep repository mapping explicit per Wayfindr site or account.
- Outbound issue creation is the first concrete adapter. It sends a conservative
  issue body with ticket metadata and a Wayfindr link, then stores the created
  GitHub URL as a `ticket_external_links` record.
- Keep comments, inbound sync, labels, milestones, and assignee mapping behind
  later capability-specific slices.

### GitLab

GitLab issues may live across GitLab.com, self-managed GitLab, GitLab Dedicated,
and project or group contexts. GitLab is attractive for teams that already
connect support follow-up to project planning, milestones, merge requests, and
labels.

Planning notes:

- Store the GitLab base URL so self-hosted installs can target their own GitLab.
- Treat project ID/path and issue IID as provider-specific external identifiers.
- Outbound issue creation supports GitLab.com and self-managed GitLab base URLs.
  It sends the same conservative ticket summary as GitHub and records the
  resulting GitLab link locally.
- Model GitLab capabilities separately from GitHub because labels, milestones,
  epics, and work items may vary by installation and edition.
- Keep private project access failures non-leaky; a provider `404` can mean
  either "not found" or "not authorized."

### Atlassian, Jira, And Bitbucket

Jira should be Wayfindr's first Atlassian-family outbound issue destination
when real operator demand appears. Bitbucket Cloud, Bitbucket Data Center, and
Jira are related, but they should be planned as separate provider decisions
instead of one generic "Atlassian" adapter.

See [0006: Atlassian Issue Integration Direction](../decisions/0006-atlassian-issue-integration-direction.md).

Planning notes:

- Treat Jira outbound issue creation as the narrowest first Atlassian
  implementation path.
- Model Jira project keys, issue types, required fields, and cloud/data-center
  base URL differences explicitly instead of hiding them behind Bitbucket terms.
- Treat native Bitbucket Cloud issue creation as legacy support only. Atlassian
  documents that Bitbucket's issue tracker is not available in all workspaces
  and has announced a 2026 sunset for native Bitbucket Issues.
- Do not assume every Bitbucket repository has issue tracking enabled.
- Do not assume Bitbucket Cloud and Bitbucket Data Center share the same issue
  API shape.
- Keep the first Jira integration narrow: create an external record and link
  back to Wayfindr.

## Integration Boundary

Wayfindr should add an integration boundary before it adds any provider-specific
behavior to tickets.

Expected pieces:

- `ticket_external_links`: a local record connecting a Wayfindr ticket to a
  provider object.
- Provider connection records owned by an account, with encrypted credentials
  and visible capability flags. This is now the account-level connection
  baseline; live provider calls still belong behind adapters.
- Site-to-provider-project mappings so one Wayfindr account can route different
  sites to different repositories or projects. This is now the site-level
  routing baseline.
- A GitHub issue creator adapter for explicit outbound issue creation. It calls
  the GitHub Issues API only when a mapped provider connection has the
  `create_issue` capability.
- A GitLab issue creator adapter for explicit outbound issue creation. It calls
  GitLab's Issues API for GitLab.com or self-managed GitLab base URLs only when
  a mapped provider connection has the `create_issue` capability.
- A site-level external issue health readout that summarizes local link sync
  states and recent sanitized provider failures without calling external
  providers or exposing credentials.
- A ticket-level external handoff readiness readout that shows whether the
  ticket's site has an issue-creation-capable project mapping before an agent
  tries to send work to another tracker.
- Adapter interfaces for creating an external issue, adding comments, fetching
  status, and handling webhooks when supported.
- Audit events for external issue creation, sync attempts, sync failures, and
  manual unlinking.

The first adapter contract should be intentionally small:

- `createIssue(Ticket $ticket, ExternalProject $project): ExternalIssue`
- `addComment(Ticket $ticket, ExternalIssue $externalIssue, string $body): void`
- `capabilities(): ExternalIssueCapabilities`

Inbound sync, status mirroring, assignee mapping, and label mapping can come
later once local ticket lifecycle behavior is stable.

## Data Rules

External issue trackers are not guaranteed to have the same privacy posture as
Wayfindr. Operators may also use public repositories, contractor-visible
projects, or vendor-hosted systems outside their direct control.

Default behavior should be conservative:

- Send a short ticket summary, priority, category, support code, and Wayfindr
  link.
- Do not send raw visitor transcripts by default.
- Do not send cobrowse snapshots, mutation payloads, masked-field metadata, or
  visitor identifiers by default.
- Require an explicit operator setting before exporting transcripts or internal
  notes.
- Record outbound creation success and failure in the Wayfindr audit trail.

## Suggested Roadmap

1. Add local ticket categories and keep them provider-neutral. This is now the
   local classification baseline; external label mapping should remain
   opt-in.
2. Add a provider-neutral external link data model and audit events. This is
   now the local linking baseline for manual records and future provider
   adapters.
3. Add account-level provider connection placeholders, capability flags, and
   site-to-project mappings. This is now the routing baseline before outbound
   provider creation.
4. Add GitHub outbound issue creation as the first concrete adapter. This is now
   the outbound creation baseline.
5. Add GitLab outbound issue creation after the adapter boundary proves itself.
   This is now the second outbound creation adapter and the self-managed GitLab
   URL baseline.
6. Evaluate Bitbucket Cloud issues versus Jira based on real operator demand.
   This is now resolved in favor of Jira as the first Atlassian-family adapter,
   with Bitbucket native issues deferred to legacy demand.
7. Add external issue sync health once outbound links are dependable. This now
   starts with local, site-scoped health visibility; inbound webhooks and deeper
   sync behavior remain later work.

## Open Questions

- Should external issue creation be account-wide, site-scoped, or both?
- Should ticket categories map to provider labels by default, or only after an
  operator configures explicit mappings?
- Should external sync failures create agent alerts, dashboard warnings, or both
  after the calm site-level health readout proves useful?
- Should public repository destinations require an additional warning before
  transcripts or internal notes can be exported?

## Source Notes

- GitHub REST Issues API: <https://docs.github.com/en/rest/issues/issues>
- GitLab Issues API: <https://docs.gitlab.com/api/issues/>
- Jira Cloud Issues API:
  <https://developer.atlassian.com/cloud/jira/platform/rest/v3/api-group-issues/>
- Bitbucket Cloud Issue Tracker API:
  <https://developer.atlassian.com/cloud/bitbucket/rest/api-group-issue-tracker/>
- Bitbucket Cloud Issue Tracker availability:
  <https://support.atlassian.com/bitbucket-cloud/docs/use-the-issue-tracker/>
- Bitbucket Issues and Wikis sunset announcement:
  <https://community.atlassian.com/forums/Bitbucket-articles/Announcing-sunset-of-Bitbucket-Issues-and-Wikis/ba-p/3193882>
- Bitbucket Data Center Jira integration:
  <https://confluence.atlassian.com/bitbucketserver/jira-integration-776639874.html>
