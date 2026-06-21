# 0006: Atlassian Issue Integration Direction

Date: 2026-06-21

## Decision

Wayfindr will treat Jira as the first Atlassian-family outbound issue
destination when operator demand justifies the work.

Native Bitbucket Cloud issue creation will not be the next Atlassian adapter.
It may be considered later only for legacy workspaces that still have Bitbucket
Issues enabled, import/migration help, or read-only link preservation.

Bitbucket Cloud, Bitbucket Data Center, and Jira should remain separate
provider decisions. They share an Atlassian family, but they do not share one
stable issue-tracking surface.

## Rationale

Wayfindr tickets are support records first. External trackers should receive a
conservative follow-up record only when that tracker is the right place for the
team's downstream work.

Jira is Atlassian's durable issue-planning surface. It supports broader work
item workflows, project-specific fields, issue types, permissions, and the
Bitbucket development integration story.

Bitbucket Cloud's native issue tracker is no longer a strong first target.
Atlassian's current public documentation says Bitbucket Cloud Issues are not
available in all workspaces, and Atlassian has announced the sunset of native
Bitbucket Issues and Wikis in 2026.

Bitbucket Data Center is primarily documented around Jira integration rather
than a built-in issue tracker equivalent to Bitbucket Cloud Issues. Treating it
as "the same as Bitbucket Cloud" would hide important deployment, API, and
customer-environment differences.

## Consequences

- Jira outbound issue creation should be the next Atlassian implementation
  candidate, not Bitbucket Cloud Issues.
- A future Jira adapter should model project key, issue type, required fields,
  and cloud versus data-center base URL differences explicitly.
- Bitbucket Cloud native issue support should stay behind explicit legacy
  demand and should never be assumed available for every repository.
- Bitbucket Data Center support should begin as Jira-link or development-context
  support unless a real operator need proves otherwise.
- The conservative export rule remains unchanged: transcripts, cobrowse
  snapshots, and internal notes are not exported by default.

## Source Notes

- Bitbucket Cloud issue tracker API:
  <https://developer.atlassian.com/cloud/bitbucket/rest/api-group-issue-tracker/>
- Bitbucket Cloud issue tracker availability:
  <https://support.atlassian.com/bitbucket-cloud/docs/use-the-issue-tracker/>
- Bitbucket Issues and Wikis sunset announcement:
  <https://community.atlassian.com/forums/Bitbucket-articles/Announcing-sunset-of-Bitbucket-Issues-and-Wikis/ba-p/3193882>
- Jira Cloud issues API:
  <https://developer.atlassian.com/cloud/jira/platform/rest/v3/api-group-issues/>
- Bitbucket Data Center Jira integration:
  <https://confluence.atlassian.com/bitbucketserver/jira-integration-776639874.html>
