# AI Support Assist qualification and rollout

This is the working qualification record for [#1176](https://github.com/adamgreenwell/wayfindr/issues/1176),
following slices #1166–#1175. The [behavior contract](ai-support-assist-contract.md)
and [ADR 0004](../decisions/0004-ai-as-assistive-product-and-development-layer.md)
remain authoritative. Source integration, human UAT, provider quality and release
approval are separate gates. This first slice does not close #1176 or authorize
release, deployment, live-provider testing or production activation.

## Evidence rules

For every run retain: Git commit; test command and exit status; PHP/Node/browser
versions; database engine; synthetic fixture or evaluation suite identity;
provider adapter/model/route configuration identity (never its credential);
locale, viewport, theme and motion preference where relevant; tester/date;
expected and observed outcome; and failing, blocked or skipped checks.
Use synthetic accounts/articles/messages only. Keep credentials and unsent draft
text out of screenshots, logs, issue comments and shared evidence bundles.

Do not combine receipts from different source/configuration revisions into a
single passing result. An omitted case is unexecuted, not passed. A copied
regression must produce the intended assertion failure, not a bootstrap error.
Retest affected journeys when their implementation or evidence identity changes.

## Automated integration entry point

From `apps/server`, using the repository's qualified PHP version:

```bash
php artisan config:clear
php -d memory_limit=1G vendor/bin/pest tests/Feature/AiAssistFullFlowTest.php
node --test tests/BrowserScripts/*.test.cjs
```

The full-flow test starts with no saved account connection or policy. It uses
the real authenticated HTTP endpoints for draft, synthetic test, reviewed save,
separate connection enable, account/site Prepare consent, opening preparation,
source inspection, Use, private refinement, Apply and separate message Send.
It checks that preparation/Use/Apply do not write messages or create tickets.
One provider instance processes account A then B; returning to A reuses its
generation. Foreign reads are denied and selected excerpts/credentials remain
account scoped. Additional cases cover source deletion, disabled processing,
zero allowance and a queue admission failure with ordinary manual replies.

The connection probe is a deterministic test double. Generation uses the existing
fake provider with actual usage claim/dispatch/reconciliation and authority checks;
it does not exercise a real SDK, DNS, socket or remote model. HTTP endpoints
authorize insertion, but this test does not establish browser insertion, keyboard
behavior or model quality. Client feedback POSTs are synthetic observations, not
proof that a human viewed or inserted a suggestion. Queue doubles do not establish
multi-process concurrency. Existing scoped suites remain required:

| Boundary | Existing source evidence to rerun as applicable |
| --- | --- |
| Guarded probes, endpoint/DNS/redirect policy and adapters | `GuardedAccountAiConnectionProbeTest`, `AgentCopilotProviderTest`, account connection permission/foundation tests |
| Multiple viewers, revocation, configuration changes and queue races | `AiAssistPreparationLifecycleTest`, `AiAssistPreparationConcurrencyTest`, `AiAssistPolicyConcurrencyTest`, `AccountAiConnectionsTest` |
| Grounding, conflict, injection, fabricated citations, unsupported actions and deletion | `AgentConversationCopilotReplyDraftTest`, `AiAssistSourceInspectionTest`, grounded parser/guard unit tests |
| Private ownership, expiry, original preservation and races | `DraftRefinementContextTest`, `DraftRefinementLifecycleTest`, `DraftRefinementConcurrencyTest`, `DraftRefinementSafetyGuardTest` |
| Budgets and accounting | `AgentConversationCopilotUsageIntegrationTest`, usage ledger/policy and independent-process race tests |
| Observations, reports, privacy and retention | `AssistFeedbackTest`, `AssistFeedbackConcurrencyTest`, `AssistRetentionTest`, visitor export/erasure tests |
| Browser insertion, late edits, source withdrawal, feedback retries and clipping | `tests/BrowserScripts` Assist, draft-review and refinement suites |

PostgreSQL-only race cases require the guarded disposable database setup described
by their fixtures; a SQLite skip is not a passing contention test. Full SQLite and
PostgreSQL CI remain required for the candidate. The existing
[evaluation harness](../development/ai-evaluation.md) has its own exact suite,
prompt, scorer and capture identities; its synthetic answers do not qualify the
current runtime grounded-reply or refinement recipe. Capture and evaluate those
recipes separately before making provider-quality claims.

## Human UAT prerequisites

Use an authorized disposable environment with a deterministic provider, no real
customer records, and two accounts A/B. Give A an owner, a responding agent, a
site-scoped manager and two sites; give B distinct published guidance. Create a
conversation with a visitor text turn and a matching published A article, plus a
conversation with no usable text. Provide test-only controls for holding queue
work, failing a provider/queue, deleting a source and exhausting an allowance.
Those controls are setup requirements, not product UI features.

Have a keyboard, a supported browser and screen reader available. Record browser,
OS and screen-reader versions and actual device used. Execute in English first,
then repeat critical controls/state announcements in German and Italian. Cover
light/dark, reduced motion, desktop and a 390-pixel-wide viewport; include a real
small device before device acceptance. A scripted DOM assertion or screenshot
does not count as screen-reader acceptance. The following scripts are **unexecuted
for this qualification slice**; historical screen checks do not fill their receipts.

| ID / priority / persona | Preconditions and steps | Observable expected result and evidence |
| --- | --- | --- |
| UAT-01 / P0 / account owner | Open Account → AI Assist. Choose own connection and enter synthetic endpoint/model/key. Save draft; test it; review and Save connection; separately enable processing. Save account behavior and selected sites as Prepare with article consent. Open a conversation. | Grouped installation/own radio form; behavior/tasks/sites/limits below. Key remains write-only. Draft/test/save never prepare support work. Active processor changes only after tested save; Prepare requires separate reviewed policy. Record proposal state, saved processor and enablement separately. |
| UAT-02 / P0 / replying agent | Start with an empty composer. Open the conversation and await Ready. Inspect the citation in a new tab, return, Insert, then Refine/Clarify. Review original and candidate, Cancel once, retry after any shown cooldown, then Apply. Finally deliberately Send. | Right rail stacks Catch up, Suggested reply, Sources. Transcript/composer dominate. No background insertion. Source inspection preserves page/draft. Cancel preserves current edits. Insert/Apply affect only the composer; exactly one message appears only after Send. Capture message counts before/after each boundary and focus/announcements. |
| UAT-03 / P0 / agent with unsent edits | Type a draft before a suggestion is ready. Preview replacement, then change the composer, including type-and-delete back to identical text; try Apply. Repeat with a source deleted or access revoked while preview is open. | Newer human edits survive. Obsolete Apply is refused; dependent source-derived prose is withdrawn. No unexpected send, ticket creation or focus jump. Record original/current/expected identity without publishing private draft content. |
| UAT-04 / P0 / owner and restricted agents | In A, test account/site permission intersections and site-scoped manager access. Attempt B conversation/source URLs and another agent's refinement. Revoke A agent access while work runs. | No foreign source, credential, preview or account usage appears. Revoked work cannot publish/use. Restricted manager cannot activate shared processing over inaccessible enabled sites; disable/disconnect stays available. Record denial and absence of output. |
| UAT-05 / P0 / replying agent | Hold queued preparation, release it, add a new visitor turn, fail the provider, disable policy, and open the empty conversation. Exercise explicit Refresh when offered. | Observe Preparing, Ready, Stale, Unavailable, Disabled and Insufficient context with readable labels and correct action availability. Unchanged failure/no-result opens do not repeatedly call providers. Refresh respects server cooldown. Ordinary composer/Send remains usable throughout. Record all six states individually. |
| UAT-06 / P0 / account owner | Hold a synthetic connection test; complete it; change processing fields; force a test failure; leave a configured untested proposal. Edit from two tabs, rotate/clear the key, and change the processor after reviewing a policy form. | Preparing, Ready, Stale, Unavailable and Configured but untested are distinguishable from saved connection/enablement. Pending actions cannot duplicate. Old tabs cannot save changed/untested processors or stale consent. Key never reappears; replacement is not inherited by another destination. Capture controls before and after each response. |
| UAT-07 / P1 / keyboard and screen-reader user | Run UAT-01/02 using keyboard alone; collapse/expand rail; open/close preview; scroll long original/candidate text; wait for completion while focus stays in composer. Repeat with screen reader. | Native disclosure/form controls have meaningful names/order; one polite Assist live region announces meaningful changes without poll chatter. Background work does not steal focus. Preview/Apply/Cancel focus is predictable; long text regions are reachable and scrollable. Record actual spoken announcements and focus sequence. |
| UAT-08 / P1 / small-device and localized user | Repeat UAT-02/05/06 at desktop and 390px, light/dark, reduced motion, EN/DE/IT. Scroll the expanded rail and leave output clipped inside its scroll area. | At narrow widths the disclosure sits above the conversation and composer remains reachable. No horizontal page overflow, clipped controls, illegible status or raw translation keys. Reduced motion is respected. Clipped output is not counted as viewed until revealed. Record viewport/theme/locale/state and device evidence. |
| UAT-09 / P0 / upgrading operator and owner | Rehearse upgrade from a known pre-feature fixture with operator-configured Manual assistance. Open conversation before explicit opt-in. Test unconfigured, Off, unavailable and out-of-budget cases. | Upgrade preserves the explicit installation-default Manual path and creates no automatic call. Existing local assistance/manual replies work as documented. New sites stay Off after explicit policy setup until enabled. Record versions, migrations, policy snapshots and call/usage counts; an install-only run is not upgrade proof. |
| UAT-10 / P0 / rollback operator | In the disposable environment follow the containment procedure below while work is pending and while a fake call is in flight. Reopen the conversation and send a manual reply. | No new dispatch or obsolete publication/use after containment. In-flight data cannot be recalled. Manual support remains usable; old credentials are not exposed or silently restored. Record request/generation and remaining holds until settled. |

Execute UAT-01 → UAT-02 first: connection selection through a deliberately sent
reply is the highest-risk seam. Then UAT-03–06, UAT-09–10, and the accessibility/
responsive matrix UAT-07–08. Missing environment controls, devices or assistive
technology leave the corresponding cases blocked and #1176 open.

## Pilot decision record

Before an authorized pilot, name its owner, cohort/sites, time window, reviewed
provider/model/route and numeric go/hold thresholds. Agree minimum sample counts
and assessment coverage rather than choosing thresholds after seeing results.
Do not turn on processing merely because this checklist or CI passed.

| Measure | Decision evidence |
| --- | --- |
| Useful draft rate | Useful / definite useful-or-not-useful reply/refinement assessments; show unsure/unassessed and coverage alongside the rate. Summary views are not insertion opportunities. |
| Material correction effort | Material / assessed inserted drafts, with assessment coverage and a human review sample of important corrections. Never infer correction from unchanged text or retain agent edits for analytics. |
| Latency and failure | Report server elapsed samples separately from measured time-to-visible in browser; split failures from quota refusal/unknown dispatch. Define the pilot's latency percentile and acceptable failure/refusal rates beforehand. |
| Usage | Holds, conservative charges, reported tokens and missing reports separately; include budget refusals and unknown dispatch. Allowance counters are not provider invoices. |
| Customer outcomes | Recorded close and ratings for observed cohorts with rating coverage. Closure is not proven resolution; observed adoption is not proof of causation or customer benefit. |

Contain immediately on tenant/source/secret disclosure, autonomous action,
unexpected insertion/send, lost human edits or unbounded processing. Hold expansion
on unmet agreed quality/coverage/latency/usage thresholds. Evidence from a probe,
fixture, screenshot, merged PR or historical evaluator cannot substitute for a
reviewed pilot's deployed configuration and representative human assessments.

## Containment and recovery

1. An authorized account manager disables processing, or sets account/site policy
   Off for the affected scope. Keep ordinary support available. Disable/disconnect
   does not require another successful test. Record scope and time without secrets.
2. Confirm new opens do not dispatch work and stale queued/running completions
   cannot publish or authorize Use/Apply. Inspect content-free audit/usage identities.
   A call already dispatched may have sent permitted text; disabling cannot undo it.
   Keep dispatched charges/unknown leases until normal reconciliation/expiry.
3. If the credential/destination must be removed, Disconnect erases account-owned
   configuration and refuses automatic installation fallback. Operator credentials
   have separate ownership. Do not delete usage history to reset limits or use
   metadata pruning as a rollback mechanism.
4. Preserve needed content-free diagnostic evidence under documented retention
   and visitor erasure rules. Account deletion/export and private refinement
   expiry remain applicable; do not add prompt/draft logging for diagnosis.
5. Software/database rollback requires its own reviewed deployment/backup plan.
   Do not reverse migrations or restore an old database over current support work
   as part of this feature's containment procedure.
6. Recovery requires review of the defect and affected evidence, an exact fresh
   proposal/test/save when processing changed, and separate enable/policy consent.
   Repeat affected UAT and pilot gates under the new identity before expansion.

## Operational references and remaining work

- [Providers and endpoint policy](../self-hosting/agent-copilot-providers.md),
  [provider product policy](ai-support-assist-provider-policy.md).
- [Connection permissions and revisions](ai-support-assist-account-connections.md),
  [admin controls and consent](ai-support-assist-admin.md).
- [Preparation, freshness and retries](../architecture/ai-assist-preparation-lifecycle.md),
  [grounding and source scope](../architecture/ai-assist-grounded-drafts.md),
  [private refinement](../architecture/ai-assist-draft-refinement.md).
- [Admission and limits](ai-support-assist-usage-admission.md),
  [audit, feedback, reports and retention](../architecture/ai-assist-observability.md),
  [privacy inventory](../privacy/data-inventory.md).

Remaining #1176 work: execute and record the complete human UAT/upgrade/containment
matrix; assemble current exact-identity runtime grounding/refinement evaluations;
audit each predecessor's acceptance against the final candidate; and fill the
pilot decision record. Live-provider evidence and rollout remain separately
authorized. This document is a runnable checklist, not a completed release certificate.
