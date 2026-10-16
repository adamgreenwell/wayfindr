# AI Support Assist Behavior and Data Contract

Date: 2026-10-15

This is the implementation contract for [issue 1166](https://github.com/adamgreenwell/wayfindr/issues/1166)
and the selected [Option 1 mock](../design/ai-support-assist/2026-10-15/conversation-rail-v5.png).
It defines required behavior for the downstream Assist layer. This documentation
does not activate that behavior: the [current copilot](../self-hosting/agent-copilot-providers.md)
remains on demand and uses an installation-owned connection. The
[feature plan](ai-support-assist-feature-plan.md#backlog-and-delivery-order) maps
these requirements to the implementation issues.

## Scope and authority

Assist prepares private suggestions for an authorized agent. Private means
hidden from the customer; permitted text still reaches the disclosed provider.
Agents insert or apply suggestions deliberately and separately submit a reply
or ticket. Generation cannot insert, overwrite, send, create a ticket, change a
support record, or execute an external business action.

The installation operator controls the installation connection, global disable,
network policy and installation usage limits. A dedicated account permission
allows owners/admins and deliberately delegated custom roles to manage their
account connection and Assist policy. Account management does not confer
installation authority or broader support visibility. Using any support capability
requires current account membership, site access and the permission for the
underlying operation: reply authority for reply/knowledge/refinement, ticket
authority for ticket details, and conversation visibility for a summary.

Changing a site's behavior, tasks, permitted context or enablement also requires
current access to that specific site, in addition to the account management
permission. Recheck every referenced site when accepting a policy change and
when executing delayed policy work; bulk changes cannot include inaccessible or
foreign-account sites. Account connection management does not grant site access.

Activating, replacing or re-enabling a shared account connection also requires
current access to every enabled site affected by that processing revision.
Reject the change if any affected site is inaccessible; proposing edits and
synthetic testing may proceed without changing the active processor. Recheck
the affected-site set and access under the account lock at activation, including
sites enabled since the test. A successful probe does not approve rerouting
another site's support data. Disabling/disconnecting remains available.

Account and site policy may disable capabilities or narrow permitted context.
The effective policy is the intersection of installation, account, site and
viewer authority. A configured provider alone never grants permission to call
it. Global disable or revoked authority prevents further external calls and
Assist result delivery/use under that authority. Disconnect blocks support work
under the disconnected connection revision. Synthetic connection probes have
their separate management authority below; none of these operations erases the
agent's existing composer text.

Autonomous visitor answers remain separately deferred by
[ADR 0004](../decisions/0004-ai-as-assistive-product-and-development-layer.md#reassessment-on-2026-09-08).
This contract preserves that decision and excludes transcript-corpus analysis,
training/export pipelines, attachments and cobrowse interpretation.

## Selected surfaces

Option 1 keeps the transcript and ordinary composer dominant. A persistent,
collapsible right Assist rail vertically stacks **Catch up**, **Suggested
reply**, and **Sources**. **Insert into reply** and secondary **Refresh** sit
beneath the suggested draft; included-message freshness sits at the rail foot.
**Send reply** belongs to the ordinary composer. Source links inspect evidence;
they do not send or insert anything. Narrow layouts use an accessible drawer or
disclosure while keeping the composer reachable. Collapse/expand never creates
another generation request.

**Account → AI Assist → AI connection** uses one grouped form, with compact
two-column field rows on desktop. Choose **Installation connection** where
permitted or **Our connection**; then show name/provider, model/conditional
endpoint or upstream, write-only credential status, test result, and **Test
connection** / **Save connection**. Behavior/tasks/sites/limits follow below a
divider. **Off** is an enablement/policy control, not a third connection source.
Saved-key masking is status only, with no retrieval or reveal of the saved key.

## Trigger and generation lifecycle

1. Mounting an actual visible, authenticated, enabled conversation workspace
   asynchronously invokes **ensure suggestions**. It prepares summary, reply and
   relevant knowledge independently where eligible. Rendering a page, GET,
   previews, prefetch, background tabs and queue-list views never generate.
2. Opening **Create ticket** invokes the same ensure semantics for ticket
   details only. It does not prepare ticket details on every conversation open.
3. Ensure returns matching current output, an existing pending/running generation,
   or a cached insufficient-context or terminal failure outcome. Otherwise it atomically reserves
   usage and one generation before enqueueing. Equivalent concurrent opens share
   work. Reopening unchanged input creates no new provider charge.
4. The worker rechecks authority, enabled policy, connection revision, sources,
   target eligibility, erasure state and allowance immediately before the
   external attempt. Ticket
   details require that the conversation still has no linked ticket and that
   the requester can still create one; a competing conversion cancels the work
   before transmission. The worker selects coherent bounded input at execution.
   The complete work identity,
   including subject, message, knowledge and effective context/policy revisions,
   must match the reservation. If it changed,
   cancel or atomically supersede/rekey and reserve the new identity before any
   transmission; never cache changed input under the old identity. A superseded
   request is never silently sent through a different connection.
5. On completion, recheck the same boundaries before publishing. Fence writes by
   generation and connection revision; a late result cannot replace newer work
   or resurrect deleted data. Reauthorize every result read and insertion/apply.
6. New messages invalidate affected results without continuously regenerating
   in the background. Reopening or explicit **Refresh** ensures current input.
   A deliberate Refresh may replace an otherwise current result; concurrent
   refreshes still coalesce into one active generation for equivalent input.

In **Manual** mode, opening the workspace or conversion form never invokes
ensure. An empty Assist rail offers **Prepare suggestions**, which deliberately
ensures the enabled, eligible summary/reply/knowledge tasks independently. The
conversion form offers **Suggest ticket details** for that task. These initial
actions use the same authorization, cache, shared-work, usage and worker checks
as automatic ensure; they cannot retry a cached failure or bypass its cooldown.
Existing output instead offers Refresh or eligible Try again. **Off** offers no
generation action. The composer and normal ticket form remain usable throughout.

Each generation makes at most one external attempt. Failure is terminal for its
automatic ensure fingerprint: reopening never retries it automatically. **Try
again** explicitly creates a replacement after a server-enforced cooldown of at
least 60 seconds, or longer provider Retry-After advice. A genuinely changed
fingerprint may prepare automatically, subject to connection-level concurrency,
failure controls and usage limits. No model or processor fallback is implicit.

Each task has its own result. A failed knowledge lookup must not hide a usable
summary. Header status must not imply every task succeeded when a section failed.
All states preserve normal support and existing agent edits.

## Work identity and freshness

Work is shared only when all of the following match:

- Account, site, conversation and capability.
- Capability-specific target eligibility, including the still-unlinked
  conversation state for ticket details. Recheck it at completion and before use;
  a competing conversion withdraws the ticket suggestion without changing the
  already-created ticket.
- Permitted-context/source partition and effective authorization/policy revision.
- Subject and selected message content revisions, including edits/deletions and
  activity that makes the context stale; newest message ID alone is insufficient.
- Prompt/schema policy version and the exact resolved connection revision,
  provider route and model.
- Knowledge catalogue/retrieval revision and selected article revisions for
  knowledge-dependent output.

A requester ID is retained server-side for authorization and audit; it is not a
reason to duplicate equivalent shared work. A different allowed input partition
must not reuse a broader viewer's answer. Personalized draft refinement uses the
agent's current draft revision, is partitioned by the owning agent and draft,
and never overwrites shared conversation output.

Freshness is checked before display and use, not just when generating. New
conversation activity may leave prior authorized output readable with insertion
disabled. If a source becomes unpublished, deleted or inaccessible, withdraw
the dependent generated text as well as its citation; hiding the link alone
still exposes the source. Independently valid sections can remain. Revoked
conversation authority withdraws the entire conversation result. Refresh offers
only currently authorized input. Already transmitted provider data is subject to
the provider's separate retention boundary.

## States and human actions

| State | Meaning and presentation | Allowed action |
| --- | --- | --- |
| Preparing | Reserved/queued/running task. Preparing private suggestions… You can keep replying. | Keep replying; prevent duplicate work; no insertable placeholder answer. |
| Ready | Current, authorized, schema-validated result. Show source links and included conversation position. It is a suggestion, not an accuracy guarantee. | Insert eligible draft; inspect sources; explicit Refresh. |
| Stale | Input changed. New messages arrived or Sources changed. | Disable insertion/apply; Refresh. Keep text readable only while its content remains authorized. |
| Unavailable | Provider, budget, queue, invalid/unsafe output or other execution failure. | Sanitized reason; bounded Try again when eligible; admin connection link only for authorized managers. |
| Disabled | Capability or effective account/site/installation policy is off, or access is absent. | No external request; compact resting state; never expose withdrawn output. |
| Insufficient context | No useful text or no permitted relevant knowledge. This is a terminal cached outcome for the current fingerprint. | Manual response or clearly limited transcript-based acknowledgment/clarifying draft; no invented guidance. |

An empty composer accepts **Insert into reply** after a current authority and
source check. Insert only the reviewed reply text, never silently append
internal source references. If there is existing text, offer a preview with
Apply/Cancel and original preservation. Apply must compare the draft revision;
edits made while generation runs cannot be lost. Refinement supports clarify,
shorten, tone and translate through this same reviewed preview and never submits.

Ticket details contain validated title/priority and locally resolved labels.
Applying current details fills only an untouched conversion form; a changed or
stale form is preserved. **Create ticket** remains a separate human submission.
Task permission, current source position and form/draft revision checks must also
hold in the server-authorized path; disabled UI alone is insufficient.

Announce meaningful state changes in a polite live region without stealing
focus. Move focus to the composer only after intentional insertion. Provide text
status labels, visible focus, disabled-action explanations, reduced motion,
light/dark themes and the supported dashboard locales.

## Provider context and grounding

Send only bounded subject/message text needed by the capability, serialized in
chronological order with generic sender roles. When the agent explicitly invokes
refinement, its permitted task input additionally includes the current unsent
composer draft and validated refinement options. Bound and scrub that draft at
the final provider boundary under the same effective character ceiling. Automatic
preparation and shared suggestion tasks never receive unsent composer text. The
agent-facing **How Assist uses this conversation** disclosure must explain that
choosing refinement sends the current unsent draft to the configured processor.
Exclude visitor/agent profiles,
identity fields, message metadata and timestamps, attachment data, page/cobrowse
state and private contact notes. The common deterministic scrubber and effective
character ceiling apply at the final provider boundary to the entire input.
Account policy may narrow the installation ceiling, never expand it. Scrubbing
is defense in depth, not proof arbitrary prose contains no sensitive information.

Grounded drafts may additionally send selected bounded relevant passages from
allowed published articles belonging to the same account. This is an explicit
expansion from today's knowledge flow: the provider receives bounded transcript
text and returns validated search phrases, which Wayfindr matches locally without
sending the article catalogue/content. Account task/context enablement must disclose
the expansion. Site enablement does not create site-owned articles or knowledge
isolation; this contract makes no same-site knowledge claim.

The **Relevant articles** control governs both the knowledge task and article
context for every other task. Off forbids article retrieval and article-passage
transmission for reply drafts, ticket suggestions and refinements as well as the
knowledge task; transcript-only assistance remains available. On permits only
the bounded accessible published passages described above. Disclose this shared
scope beside the control before enablement. Turning it off changes the effective
policy revision: cancel queued article work, withhold obsolete article-derived
results, and reject late completion or insertion under the previous policy. It
cannot recall passages already dispatched to a provider.

Treat transcript, articles and provider output as untrusted data. Grounding
output identifies only supplied local source IDs/revisions. Validate these
against selected passages and resolve accessible local links; a model-generated
URL or citation is not evidence. Recheck publication and access before display
or use. Unsupported/conflicting knowledge produces a limitation or a clarifying
draft, never fabricated policy, completed action, citation or promised outcome.
All generated text/fields must satisfy their existing target validation limits.

## Connection revisions and enablement

Connection processing is **proposed → tested → active**. Proposed edits and
synthetic tests leave the active connection unchanged. Test evidence binds the
exact provider, model, endpoint/upstream, credential revision and applicable
processing/network policy. Changing any of these invalidates the test; changing
only the display name does not. A successful probe proves one synthetic request
worked at the recorded time, not ongoing health, answer quality or customer
success.

Synthetic probes require current account connection-management authority and
operator outbound/network policy plus usage allowance. They can run before a
site exists and independently of support **Prepare when opened**, **Manual** or
**Off** settings. Installation-wide disable of external AI calls still blocks a
probe. Testing a proposed connection does not authorize support processing.

**Save connection** activates only a matching successfully tested revision. An
untested draft can remain configured but inactive. Saving never enables
automatic preparation by itself; enablement is a separate deliberate account/
site action. Existing manually enabled installation copilot behavior survives
upgrade without silently starting automatic calls. New enabled sites default to
**Prepare when opened**, with **Manual** and **Off** available. Disable/disconnect
always remains available regardless of probe state.

The implemented administration policy keeps untouched accounts at legacy Manual.
The first explicit policy save initializes existing sites as Manual; sites added
later start Off until deliberately enabled. An explicit enabled policy requires
a usable saved tested connection. A separate policy revision invalidates work
without invalidating the connection's successful synthetic test. Prepare remains
configuration until the conversation ensure integration in #1170. The first
[preparation foundation](../architecture/ai-assist-preparation-lifecycle.md) defines
read-only work identities and terminal-cache classification; it adds no automatic
trigger, reservation or provider call.

Native account/site policy forms bind both the reviewed policy revisions and the
active processor snapshot. Changing the saved processor requires a fresh review;
unrelated proposed edits or synthetic tests leave active policy-save authority
unchanged. A bulk save validates at most 1,000 selected sites and their tokens,
not every site in the account; unselected tokens do not block that selection.
Native forms send only checked-site tokens and refuse truncated input with
smaller-batch guidance; the API/service bound remains 1,000 selected sites.

For **Our connection**, **Clear key** edits only the proposed credential; the
active saved connection remains in use until a valid tested proposal is saved.
A required-key provider cannot activate a keyless proposal. **Disable** stops
support processing while retaining configuration for deliberate re-enablement.
**Disconnect** immediately disables support processing and removes both proposed
and active credentials/revisions; it never selects the installation connection
as a fallback. Reconnection requires an explicit source choice, successful test
and activation. Account controls cannot clear an installation operator's key.

Account-owned credentials are encrypted, write-only and inaccessible to other
accounts or ordinary agents. Using an allowed installation connection references
its revision without copying its secret. Resolve the account connection
explicitly for each request/job; never swap shared global config in a
long-running worker. A connection change supersedes old work rather than
rerouting it under the same generation identity.

Hosted tenant destinations cannot reach internal/metadata services or inherit
the operator's private-network authority. Local/private model access requires
explicit installation-operator network policy. Endpoint validation applies at
execution and through DNS/redirect handling, including IPv4/IPv6. Default to
rejecting redirects unless the operator policy explicitly permits the entire
destination path; never forward credentials to an unapproved destination or
accept credentials in a URL. Preserve explicit OpenRouter upstream attribution,
zero-retention requirement and disabled fallback routing.

## Data records and retention

These logical records define ownership and content classes; they are not a
migration specification or a claim that new tables already exist.

| Record | Required information | Boundary |
| --- | --- | --- |
| Account connection revision | Account/source ownership, provider/model/route/endpoint, opaque credential revision, encrypted secret and policy revision. | Server-only secret; account manager may replace/clear, never read back. |
| Probe receipt | Actor/account, exact proposed revision, time, sanitized outcome and reported usage. | Synthetic input only; no prompt, output, key or raw provider error in audit. |
| Preparation/generation | Opaque work/generation IDs, local scope, requester, capability, fingerprint/revisions, state, usage reservation and bounded timing. | Queue carries IDs/revision references, not transcript, source passages or credentials. |
| Suggestion | Bounded text or validated fields, local source IDs/revisions, included source position and provenance. | Support content, visible only within current authority; not public widget data or content-free metadata. |
| Refinement input/preview | Owning agent, parent conversation, draft revision, bounded scrubbed input/candidate text and source references. | Private transient storage; encrypted input if queued, queue IDs only, Apply/Cancel, never a shared result or draft archive. |
| Audit and feedback | Account/site/actor and work IDs, event/reason code, revisions, route/model, timing and reported tokens; viewed/used/rejected outcome. | No prompts, output bodies, original/edited draft bodies, credentials, raw transcripts or free-text feedback. |

Keep only the latest suggestion per capability and equivalent-authority
partition, plus at most one preceding authorized output during refresh/failure.
Discard superseded output when replacement succeeds; do not build an output or
prompt history. Keep the original unsent draft in the owning composer. Queued
refinement may retain only the bounded scrubbed task input in encrypted,
agent/account/conversation-bound transient storage for at most 15 minutes; delete
it on completion, failure, cancel or expiry. Never put its body in the queue,
audit, shared cache or durable browser storage. Preview candidates expire on
apply, cancel, workspace end or the same 15-minute maximum. Dispatch, read and
Apply recheck ownership, parent authority, current policy and draft revision;
expired work cannot call or apply. These input and preview stores join the
export/erasure/retention inventory below and must not become a draft history.
Cached insufficient-context and failure receipts hold reason/revision metadata, not rejected provider text.

Suggestion, refinement-input and preview stores participate in conversation, site
and account deletion and applicable visitor export/erasure and retention inventories. Preserve
[ADR 0026](../decisions/0026-erasing-and-exporting-a-visitor.md) in-flight erasure
admission and fencing. Every external attempt carrying visitor-derived text,
including grounded generation and agent-owned refinement, must register an
erasure-visible in-flight claim with bounded start/terminal timing before
transmission. New call paths/stores must join erasure admission, not merely the
late-write check: the existing eraser knows only the current four copilot stores.
Queued work cannot run after erasure, and late completion cannot recreate
content. This contract introduces no longer retention window
than the parent support record. Content-free audits follow their documented
account visibility and retention. Backups and provider retention are separate;
local erasure does not promise deletion from either.

## Usage and failure bounds

Every external attempt, including probes and refinement, requires an atomic
request allowance and conservative token reservation before dispatch. Apply the
tighter effective installation/account limits and per-connection concurrency
cap. Bound provider input/output and queue/provider timeouts. Reconcile reported
usage; missing usage or an uncertain delivery cannot release allowance as though
no call happened. Retain a conservative debit until reconciliation or the
documented budget-window reset, so the reservation cannot remain unbounded or
become a free retry. Expose request/token usage; money is an estimate unless
actual provider billing is available.

Daily request/token allowances use fixed UTC calendar days, from 00:00 UTC
inclusive to the next 00:00 UTC exclusive, determined by database time. Browser,
agent/account/installation timezones and daylight-saving changes cannot move the
boundary. Installation and account counters use explicit period keys; connection
rotation never resets them. Atomically reserve all applicable counters in one
transaction with a consistent lock order and unique scope/period identities.
Concurrent workers must not independently zero or recreate the same allowance.

Queued work crossing midnight must atomically release its known-unsent old hold
and reserve in the current period before transmission, or stop without a call.
An already-dispatched attempt stays charged to its dispatch period; late or
duplicate reconciliation may adjust only that period, never credit a newer one.
Unknown usage remains conservatively debited through that period's close. Keep
prior-period receipts for reconciliation without carrying their allowance into
the new day. Concurrency counts span periods and include still-running attempts.
An unset account limit inherits installation policy; zero permits no external
attempt, and a positive limit may only narrow the effective installation ceiling.

Failures use stable sanitized reason codes and task states. No raw provider
response, credential, customer text or private endpoint detail leaks into an
agent error. Probe success, validated task readiness, useful assistance and
customer-confirmed outcomes remain distinct evidence. Use fake providers and
synthetic fixtures for qualification; this contract authorizes no deployment or
live-provider experiment.

## Required downstream qualification

The [delivery issues](ai-support-assist-feature-plan.md#backlog-and-delivery-order)
must prove concurrent-open reuse; terminal no-result/failure reuse; edits,
deletions and configuration changes during jobs; source withdrawal and revoked
access at read/use; account A then B in one worker; endpoint-policy and credential
isolation; budget exhaustion/unknown usage; stale draft/form preservation; and
export/erasure races. Qualify both Option 1 screens and every state with keyboard,
screen-reader, responsive, theme and locale checks. Passing a probe, fixture or
mock review does not establish deployed model quality.
