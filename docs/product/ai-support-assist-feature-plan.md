# AI Support Assist Feature Plan

Date: 2026-10-15

Status: Option 1 selected on 2026-10-15; downstream runtime implementation is
pending. The [behavior and data contract](ai-support-assist-contract.md) defines
the requirements for this private, human-reviewed Assist layer. This feature
plan maps that contract and the selected screens to the delivery backlog.

## Product outcome

An agent opening a conversation should have useful context and a private reply
suggestion prepared without asking for each AI task. Account admins should choose
the model connection, allowed tasks, sites, and usage limits. Agents remain in
control of their draft, customer-facing sends, and support-record changes.

The two primary surfaces are the conversation **Assist** panel and **Account →
AI Assist → AI connection**. Both follow Wayfindr's existing
[design system](../decisions/0014-design-system-and-visual-identity.md): IBM Plex,
compact layouts, square controls, restrained color, and site color as orientation.

## Agreed behavior

- Prepare private suggestions automatically when an enabled conversation
  workspace opens. Prepare a summary, reply draft, and relevant knowledge where
  permissions and context permit. Prepare ticket details when conversion opens.
- Reuse current output and share pending work across eligible viewers. Opening
  an unchanged conversation must not create another model charge.
- New messages mark output stale. Reopening or an explicit **Refresh** prepares
  an update. Continuous background regeneration is outside this first slice.
- **Insert into reply** fills an empty composer after the agent chooses it. The
  normal composer owns **Send reply**. Generation never inserts or submits text,
  overwrites agent edits, or changes support records automatically.
- Allow account admins to connect supported hosted models, routing gateways,
  and compatible local or self-hosted model servers.
- Preserve the ordinary support workflow when AI is disabled, unavailable,
  unconfigured, or out of budget.
- In Manual mode, opening never generates. Offer **Prepare suggestions** in an
  empty rail for the enabled conversation tasks and **Suggest ticket details**
  in conversion. Both use the same ensure/cache/authority/usage contract; Off
  offers no generation action.

"Private" means hidden from the customer. Allowed, bounded support text still
reaches the configured model provider. **How Assist uses this conversation**
explains the selected processor and permitted context without showing secrets.

## Existing foundations and new scope

The delivered [agent copilot](https://github.com/adamgreenwell/wayfindr/issues/763)
already supplies queued, explicitly requested summaries, transcript-only reply
drafts, locally matched article snippets, and suggested ticket details. The
[provider boundary](../self-hosting/agent-copilot-providers.md) supports Anthropic,
Gemini, OpenAI, OpenRouter, Ollama, and OpenAI-compatible APIs. Credentials are
encrypted and write-only. Configuration currently belongs to the installation
operator and applies installation-wide.

This feature adds account-owned connections, automatic preparation, a unified
Assist surface, grounded answer drafts, draft refinement, account/site controls,
usage limits, and adoption measurement. Current article snippets are search
assistance; generating a grounded answer needs relevant passages and validated
source references.

Articles currently belong to an account, with no site relationship. Initial
grounding uses approved published articles from the same account that the agent
may access. Per-site capability switches do not imply per-site article ownership
or knowledge isolation. Site-specific knowledge collections require a separate
model and policy slice.

Autonomous visitor replies remain under the separate
[ADR 0004 deferral](../decisions/0004-ai-as-assistive-product-and-development-layer.md#reassessment-on-2026-09-08)
and [issue 762](https://github.com/adamgreenwell/wayfindr/issues/762). This feature
does not grant autonomous customer replies or external business actions.

## Conversation Assist surface

### Selected visual direction

[Option 1 — Conversation rail](../design/ai-support-assist/2026-10-15/conversation-rail-v5.png)
is the selected visual reference for both screens and their Preparing, Ready,
Stale, and Unavailable states. The selection prerequisite is satisfied.

Revision 5 visibly discloses beside **Relevant articles** that selected, bounded
published article passages reach the configured provider for grounded drafts.
The agent rail includes **How Assist uses this conversation** as its data-use
disclosure entry point. Earlier selected-board revisions remain provenance
archives; revision 5 is the current implementation reference.

The transcript and normal reply composer occupy the main column. A persistent,
collapsible right rail stacks **Catch up**, **Suggested reply**, and **Sources**.
**Insert into reply** and secondary **Refresh** sit beneath the suggested reply;
included-message freshness sits at the rail's foot. **Send reply** stays in the
normal composer. Preserve this hierarchy in every state and adapt the rail to
an accessible drawer or disclosure on smaller screens.

Keep related next steps in the existing reviewed ticket conversion flow. The
agent sees the included conversation position, source links, and meaningful
limitations. Draft refinement uses an action and preview within this direction;
it does not introduce a separate task-tab interface or a composer workbench.

The [prompt set](../design/ai-support-assist/2026-10-15/prompts.json) records the
built-in generator inputs, displayed order, and selection. The unselected
[Concept 2](../design/ai-support-assist/2026-10-15/reply-workbench.png) and
[Concept 3](../design/ai-support-assist/2026-10-15/task-tabs.png) remain exploration
archives, rather than implementation targets.

The behavior and credential contracts below govern generated control details:
masked saved-key fields indicate credential status, never retrieval or reveal
of a saved secret; duplicate connection tests stay disabled while testing; stale
proposed settings must be tested before first activation.

Opening **Create ticket** automatically ensures suggested title, priority and
labels when that task is enabled and the agent has ticket authority. Reuse the
current ticket suggestion rather than generating on every form open. Applying
details fills only an untouched form using a current suggestion; stale output
cannot populate it. The agent separately submits ticket creation. Preparing
these details is an account/site task control distinct from the three tasks
that run on conversation open.
Recheck that conversion remains eligible before dispatch, at completion and
before use. If another agent has created a linked ticket, cancel or withdraw the
suggestion without sending obsolete work or changing that ticket.

An empty composer offers **Insert into reply**. When the composer has text,
Assist offers a preview rather than silently replacing it. **Improve my draft**
supports clarify, shorten, tone, and translate through an Apply/Cancel preview
that preserves the original and checks whether the draft changed during work.
Refinement belongs to that agent's draft; it does not replace shared suggestions.

| State | Visible content | Available behavior |
| --- | --- | --- |
| Preparing | Preparing private suggestions… You can keep replying. | Composer remains usable; no invented placeholder answer; no duplicate generation. |
| Ready | Suggestions ready, source-linked draft, summary, included message position. | Insert into reply where eligible; Refresh; inspect sources. |
| Stale | New messages arrived or Sources changed. Previous output remains readable only while its content is authorized. | Disable insertion; withdraw text derived from unpublished/deleted/inaccessible sources; allow Refresh; preserve composer text. |
| Unavailable | Assist is temporarily unavailable. Keep replying normally. | Try again after cooldown; authorized admins may manage connection. |
| Disabled | Assist isn't enabled for this account or site. | Compact resting state; no warning treatment or provider call. |
| Insufficient context | No relevant articles found, or not enough conversation text yet. Cache this terminal outcome for the current fingerprint. | Limited transcript-based acknowledgment/clarifying draft or manual reply; no invented policy and no new call on unchanged opens. |

Use one polite live region for meaningful updates; do not move keyboard focus
when generation completes. Announce insertion and focus the composer only after
the agent activates the action. Honor reduced motion and provide visible focus,
text status labels, and explanations for disabled actions. On narrower screens,
Assist becomes an accessible drawer or disclosure while the composer remains
reachable. Verify light and dark themes and the extracted dashboard locales.

## Account AI connection surface

Use Option 1's companion admin screen: the existing Account contextual
navigation selects **AI Assist**, and one grouped connection form uses compact
two-column field rows on desktop, stacking naturally on smaller screens.
Connection source leads the form, followed by name/provider, model/conditional
endpoint or route, and write-only credential status/replacement. Place the
connection-test result and **Test connection** / **Save connection** actions
together. Automatic preparation, enabled tasks, site scope, and usage controls
follow below a divider on the same page. A connection list or master-detail
editor is outside this selected direction.

The heading **AI connection — for this account** establishes ownership. A new
dedicated account permission controls management; owners and admins receive the
default permission, with deliberate delegation through custom roles. Using
Assist also requires the relevant site and support-operation authority.
Changing a site's behavior, tasks, context or enablement requires current access
to each affected site as well as the management permission. Enforce this for
individual and bulk changes and recheck delayed policy work; an account manager
cannot configure an inaccessible or foreign-account site.
Activation/replacement/re-enabling of the shared account connection requires
current access to every affected enabled site. Check the current site set under
the account lock at activation; a scoped manager may propose/test settings but
cannot reroute an inaccessible site's data. Disable/disconnect remains available.

| Setting | Contract |
| --- | --- |
| Connection source | Installation connection where permitted, or our connection. Present this as a single choice, not connection-source tabs. Never copy the operator's secret into the account. |
| Connection name | Human-readable account label. |
| Provider | Existing supported drivers, plus the documented adapter extension contract for other APIs. |
| Model | Exact model identifier; model discovery where available, with manual entry. |
| Credential | Write-only encrypted key; saved/replacement/clear states; no browser readback. |
| Endpoint or route | Conditional on provider; preserve OpenRouter's explicit upstream and retention/no-fallback policy. |
| Test connection | Synthetic sample only; distinguish configuration validity, connectivity, and output quality. |
| Behavior | Enabled sites default to Prepare when opened after deliberate admin enablement; Manual and Off remain available in the behavior/site policy controls, separately from connection source. |
| Tasks and context | Summary, reply draft and relevant articles on conversation open; ticket details on conversion open. Published same-account sources; no attachments or cobrowse snapshots. |
| Usage | Request/token allowance, concurrency limits, observed usage and sanitized failures. Dollar amounts are estimates unless actual provider billing is known. |

Draft form edits and tests do not change the active connection. A test is tied to
the exact proposed processing revision. Changing provider/model/endpoint/upstream,
credential revision or applicable processing/network policy invalidates that
test; changing only its display name does not. **Save connection** activates the
matching successfully tested revision;
automatic preparation is a separate explicit setting. Disabling always remains
available. No arbitrary fallback to a different vendor or data processor.

| State | Admin presentation | Action |
| --- | --- | --- |
| Preparing | Checking connection… Using sample text. | Show provider/model; prevent duplicate probes. |
| Ready | Connected · tested at the displayed time. | Save the tested revision; show automatic preparation separately. |
| Stale | Settings changed. Test these settings again. Saved connection remains active until you save. | Test proposed values; discard changes if needed. |
| Unavailable | Actionable, sanitized cause such as API key rejected, model unavailable, or connection timed out. | Correct the relevant field and test again; retain manual support. |
| Configured but untested | Configured · not tested. | Test before first enablement; don't represent field validation as connectivity. |

The installation operator continues to own installation defaults, global disable,
and network access policy. Account admins can use only permitted destinations.
Hosted tenants must not gain access to internal/metadata services by supplying an
endpoint. Self-hosted local model access requires operator-authorized network
policy. Apply destination policy through redirects and credential forwarding.

## Automatic preparation contract

An authenticated asynchronous **ensure suggestions** operation starts when the
actual conversation workspace opens. Page rendering, GET requests, link previews,
and browser prefetch must not start billable generation.

Work identity includes account, site, conversation, capability, permitted context,
source/content revision, prompt and policy version, and connection route/model/
configuration revision. Knowledge-dependent output also binds its knowledge
revision. Share work only where input and authority are equivalent; personalized
refinement remains separate.

Reserve work atomically before enqueueing. Recheck the requesting agent, account,
site, connection, and budget at execution. Resolve the account connection
explicitly for each request; do not swap installation-global config between jobs
in a long-running worker. Fence completion against superseded generations and
configuration revisions. Reauthorize execution, result reads and insertion/apply.
New source activity invalidates output before use; withdraw dependent text when
source publication or access is lost rather than merely hiding citation links.

Keep authorized previous output during refresh. Unchanged opens reuse terminal
no-result/failure receipts. Failed generations make no automatic retry; manual
retry has at least a 60-second server cooldown or longer provider advice.
Disconnects, exhausted limits,
queue failure, timeouts, and missing usage information produce bounded,
account-visible outcomes without blocking manual replies.

## Grounding and data policy

Retrieve bounded relevant passages from allowed published same-account articles.
Send only selected excerpts and minimum transcript context through the existing
scrubber. Validate structured citation identifiers against the supplied sources
and re-resolve publication/access/freshness before display or insertion. Clearly
distinguish conversation facts, article-backed guidance, and missing information.

Conflicting or insufficient knowledge produces a limitation or clarifying draft.
Never fabricate citations, policy, an action already taken, or a promised result.
Article and transcript text remain untrusted data, not instructions to tools.

Content-free account audits record actor, capability, source/configuration
revision, state, provider/model route, timings and reported tokens. Measure
viewed/used/rejected suggestions without retaining full agent edits or prompt
copies. Include outputs in the applicable export, deletion, and retention paths.

## Backlog and delivery order

Track [AI Support Assist epic 1165](https://github.com/adamgreenwell/wayfindr/issues/1165)
with these eleven child issues. Keep all new cards Todo; the plan does not start
implementation. Foundation and execution can be reviewed independently, but
release qualification covers the whole flow. Option 1 is the selected reference
for the contract, both UI slices, and full-flow qualification.

| Slice | Priority | Depends on | Scope and acceptance boundary |
| --- | --- | --- | --- |
| [1. Behavior and data contract](https://github.com/adamgreenwell/wayfindr/issues/1166) | P1 | None; Option 1 selected | [Contract](ai-support-assist-contract.md) records the selected surfaces, trigger, states, human control, context, authority, revision/usage and retention requirements. Principles/provider docs distinguish current runtime from this downstream contract and preserve autonomous deferral. |
| [2. Account connections and permissions](https://github.com/adamgreenwell/wayfindr/issues/1167) | P1 | 1 | Explicit account connection resolution, encrypted credentials and revision lifecycle. Prove account A then B in one worker cannot leak configuration or keys; reject cross-account references and revoked authority. |
| [3. Provider adapters endpoint policy and probes](https://github.com/adamgreenwell/wayfindr/issues/1168) | P1 | 2 | Reuse current adapters, document extensions and enforce deployment-aware destinations through redirects. Synthetic probes test the proposed revision and never send customer text; errors exclude secrets. |
| [4. Account and site administration](https://github.com/adamgreenwell/wayfindr/issues/1169) | P2 | 2, 3, 9 | Option 1's single grouped account connection form with two-column desktop rows and behavior/task/site/usage controls below. Cover all connection states; separate draft/test/save/enable; preserve active config during edits and existing manual behavior on upgrade. |
| [5. Automatic preparation lifecycle](https://github.com/adamgreenwell/wayfindr/issues/1170) | P1 | 2, 3, 9 | Idempotent ensure on conversation open plus ticket-details ensure on conversion open, equivalent-viewer deduplication, revision-aware cache, worker fencing and retry cooldown. Prove concurrent opens create one generation; changed messages/configuration invalidate results; GET/prefetch never generate. |
| [6. Conversation Assist panel](https://github.com/adamgreenwell/wayfindr/issues/1171) | P2 | 5; Option 1 selected | Persistent collapsible right rail with stacked Catch up, Suggested reply and Sources; insertion/refresh beside the suggestion, separate composer send, and all six contract states. Manual mode provides initial preparation actions; Off never generates. Ticket conversion prepares eligible enabled details but applies only to an untouched form before separate creation. Preserve edits; qualify keyboard, small viewport, themes and locales. |
| [7. Published knowledge grounded drafts](https://github.com/adamgreenwell/wayfindr/issues/1172) | P1 | 5, 6 | Relevant passage retrieval and validated citations with access/publication rechecks. Synthetic cases cover conflicting/deleted sources, injection, fabricated citations, unsupported claims and cross-account sources. |
| [8. Refine the agent draft](https://github.com/adamgreenwell/wayfindr/issues/1173) | P2 | 6, 7 | Clarify/shorten/tone/translate with preview, cancel, original preservation and draft-revision checks. No shared-output overwrite, hidden insertion or send. |
| [9. Budgets concurrency and failures](https://github.com/adamgreenwell/wayfindr/issues/1174) | P1 | 2 | Atomic request/token reservations and fixed UTC daily periods using database time; consistent scope/period locking, queued rollover and original-period reconciliation. Concurrency spans midnight; rotation never resets allowance. Concurrent requests cannot overspend; timeout/unknown usage cannot create free retries or unbounded reservations. |
| [10. Audits feedback and retention](https://github.com/adamgreenwell/wayfindr/issues/1175) | P1 | 4–9 | Account-visible content-free audit, adoption/rejection events, bounded usage reporting and export/deletion/retention integration. Verify visibility, secret exclusion, duplicate feedback and deletion races. |
| [11. Full flow qualification rollout and documentation](https://github.com/adamgreenwell/wayfindr/issues/1176) | P1 | 1–10 | Qualify both Option 1 screens, all six Assist states (Preparing, Ready, Stale, Unavailable, Disabled and Insufficient context), and all admin connection states. The mock illustrates four states without limiting qualification. Cover fake-provider journeys, tenant isolation, grounding/refinement evaluation, accessible responsive UAT, automatic/manual/off and upgrade behavior, pilot criteria and rollback. Runtime/provider quality and deployment are separate evidence boundaries. |

The dependency order is 1 → 2 → 3 and 9 → 4 and 5 → 6 → 7 → 8 → 10 → 11.
UI work can use fixtures while its backend dependencies are implemented; that
does not establish integrated runtime behavior.

## GitHub mapping

Target: [Wayfindr Project 14](https://github.com/users/adamgreenwell/projects/14),
repository [adamgreenwell/wayfindr](https://github.com/adamgreenwell/wayfindr).
Use the existing Status and Priority fields. Foundation applies to slices 1–3,
Execution to 5 and 7–10, and Qualification to 11. Leave Phase unset for account
and agent UI rather than labeling them Operator experience.

Reference closed #763 and #743 as delivered predecessors. Keep #762 and the
closed #764 decision record separate from this assistive layer. Current suite
identity matters when evaluation evidence is reused; historical captures under
older suite identities do not establish current quality.

[Epic 1165](https://github.com/adamgreenwell/wayfindr/issues/1165) and child issues
1166–1176 are on Project 14 as Todo with the priorities and phases above. The
eleven native parent relationships and thirty blocked-by dependencies express
the delivery order. Each issue contains standalone acceptance criteria and
concrete dependency links. Option 1 is selected and the visual prerequisite is
satisfied. Issue 1166 owns this documentation contract; downstream issues own
runtime implementation and qualification. Card status follows actual work;
the contract does not complete those downstream dependencies.

## Qualification and rollout

Use fake providers and synthetic source material for automated coverage. Verify
the complete agent journey, connection editing and testing, multiple accounts in
one worker, concurrent viewers, provider/configuration changes during generation,
source deletion, exhausted budgets and queue failures. Compare both screens and
the four main states with Option 1's selected hierarchy. Check light/dark themes,
keyboard and screen-reader behavior, responsive layouts and translations.

Preserve existing operator-configured manual assistance through an explicit
installation-default path. Do not silently activate automatic provider calls on
upgrade. Admin enablement establishes the new default of preparation on open.

Pilot criteria should inspect useful-draft rate, material correction effort,
latency, failures, usage and customer outcomes. A connectivity probe, synthetic
evaluation, screenshot, or merged PR does not establish deployed model quality.
Release, deployment, live-provider testing and production activation require
their own authorized steps.
