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

## Current Capability Baseline

GitHub, GitLab, and Jira now share the same implemented baseline:

- explicit, capability-gated outbound issue creation with a conservative
  ticket summary;
- authenticated inbound webhook receivers that reflect external issue state
  without changing the Wayfindr ticket lifecycle;
- opt-in outbound internal-note-to-comment relay;
- inbound comment-to-internal-note relay with a bounded echo/retry ledger; and
- local retry, audit, and sync-health visibility without exposing provider
  credentials or raw failure details to agents.

This baseline is locally verified with mocked provider HTTP boundaries and has
completed a full live GitHub dogfood round trip: issue creation, reflected state,
two-way comment relay, and echo suppression. GitLab and Jira should receive the
same live-provider proof when an operator actually configures them; that is not
a blocker for the GitHub-backed dogfood install. Richer field mapping remains
deliberately demand-gated until dogfood traffic establishes which metadata is
useful.

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
- Inbound state reflection and comment relay arrive through authenticated
  per-connection webhooks. An enabled connection and valid webhook secret—not
  the outbound capability flags—authorize those deliveries. Labels, milestones,
  and assignee mapping remain later product decisions.

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
- Authenticated inbound state reflection and bidirectional comment relay use the
  same provider-neutral sync paths as GitHub and Jira.
- Model GitLab capabilities separately from GitHub because labels, milestones,
  epics, and work items may vary by installation and edition.
- Keep private project access failures non-leaky; a provider `404` can mean
  either "not found" or "not authorized."

### Atlassian, Jira, And Bitbucket

Jira is Wayfindr's first Atlassian-family issue destination. Bitbucket Cloud,
Bitbucket Data Center, and Jira are related, but they remain separate provider
decisions instead of one generic "Atlassian" adapter.

See [0006: Atlassian Issue Integration Direction](../decisions/0006-atlassian-issue-integration-direction.md).

Planning notes:

- Jira outbound issue creation supports Cloud and Server/Data Center auth and
  payload differences while keeping one conservative Wayfindr export boundary.
- Model Jira project keys, issue types, required fields, and cloud/data-center
  base URL differences explicitly instead of hiding them behind Bitbucket terms.
- Treat native Bitbucket Cloud issue creation as legacy support only. Atlassian
  documents that Bitbucket's issue tracker is not available in all workspaces
  and has announced a 2026 sunset for native Bitbucket Issues.
- Do not assume every Bitbucket repository has issue tracking enabled.
- Do not assume Bitbucket Cloud and Bitbucket Data Center share the same issue
  API shape.
- Jira also participates in authenticated inbound state reflection and
  bidirectional comment relay; Wayfindr ticket state remains canonical.

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
- A Jira issue creator adapter for Cloud and Server/Data Center projects, gated
  by the same `create_issue` capability.
- A site-level external issue health readout that summarizes local link sync
  states and recent sanitized provider failures without calling external
  providers or exposing credentials.
- A ticket-level external handoff readiness readout that shows whether the
  ticket's site has an issue-creation-capable project mapping before an agent
  tries to send work to another tracker.
- Provider-specific issue creators, a shared `IssueCommenter` contract, and
  authenticated webhook controllers that feed provider-neutral inbound state
  and comment synchronization services.
- Audit events for external issue creation, sync attempts, sync failures, and
  manual unlinking.

The implemented seams should stay intentionally small: provider-specific issue
creators, `IssueCommenter` implementations, `InboundIssueStateSync`, and
`InboundCommentSync`. Assignee, priority, and label mapping can come later once
live use establishes direction and conflict-handling requirements.

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

1. Add local provider-neutral ticket categories. **Shipped.**
2. Add provider-neutral external links and audit events. **Shipped.**
3. Add account connections, capability flags, and site mappings. **Shipped.**
4. Add GitHub, GitLab, and Jira outbound issue creation. **Shipped.**
5. Add retry handling and account/site/ticket sync-health visibility.
   **Shipped.**
6. Add authenticated inbound issue-state reflection for all three providers.
   **Shipped.**
7. Add opt-in outbound and authenticated inbound comment relay with echo-loop
   protection. **Shipped.**
8. Validate the full round trip against a live dogfood provider connection.
   **Shipped for GitHub; GitLab and Jira remain provider-specific follow-up when
   a real install configures them.**
9. Decide whether labels, assignee, or priority mapping is justified by real
   traffic, including direction and conflict rules.

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
