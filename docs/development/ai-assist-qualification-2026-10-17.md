# AI Assist candidate qualification — 2026-10-17

This record qualifies the completion candidate on
`feat/1176-ai-assist-qualification-completion`, based on `5954d0cf` (merged
[#1198](https://github.com/adamgreenwell/wayfindr/pull/1198)). It covers #1176
and the integrated #1174 acceptance audit. The accompanying
[machine receipts](evidence/ai-assist/2026-10-17/) identify the fixture, source,
guards and actual observed outcomes. Tester: Codex, 2026-10-17.

All accounts, support text and providers were synthetic. No live model, production
data, release, deployment or pilot was exercised. Adam reports no accessibility
or screen-reader failures using built-in macOS VoiceOver in the [human receipt](https://github.com/adamgreenwell/wayfindr/pull/1199#issuecomment-6242979703).
Adam also explicitly confirms [real phone/tablet checks passed](https://github.com/adamgreenwell/wayfindr/pull/1199#issuecomment-6243045464).
Device model, browser/OS/VoiceOver versions are unspecified. Human gates are
reported passed; issue closure follows completion merge and the acceptance audit.
A real deployed upgrade remains an operator acceptance step.

## Candidate changes

- Add 25 runtime grounding/refinement evaluation cases and five schema-upgrade/
  containment cases, using the real builders, guards, workers and accounting with
  deterministic providers.
- Repair overlapping feedback labels and style bounded, square native selects.
  A native browser geometry probe passes the corrected layout and fails a copy
  of the old rendered markup on the intended overlap assertion.
- Explain disabled private Preview when the browser lacks secure UUID support.
  HTTPS and a supported modern browser are required; ordinary Send remains usable.
  EN/DE/IT copy and a browser regression cover this boundary. No weak identity
  fallback was added.

## Automated qualification

Runtime: PHP 8.5.10; Chromium 155.0.8059.80. SQLite was in-memory. PostgreSQL 17
ran in a task-owned disposable container, loopback published, tmpfs data, no
mounts. The runner verified actual PDO driver/database before tests. Contention
tests use independent processes; SQLite skips do not substitute for those runs.
Server commands run from `apps/server`, with `artisan config:clear` before each
Pest invocation and `-d memory_limit=1G`.

The 48-file PostgreSQL qualification run passed **1,268 cases / 9,153 assertions**.
It includes the connection, provider, policy, preparation, grounding, refinement,
usage, observability, retention and race suites listed in `verification.json`.
The scoped run predates the final UI copy/layout and runtime-tree identity
correction; its earlier runtime receipt is superseded. Current affected-suite
results and the full SQLite baseline comparison are
recorded there separately, rather than treating different revisions as one run.
Local macOS owner-bootstrap failures are an exact pre-existing baseline, not a
passing full suite. Candidate CI must independently pass the complete lanes.

The existing full-flow test joins draft → synthetic test → reviewed save →
separate enable → account/site consent → open → source-linked output → authorized
Use → private refinement/Apply → separate Send. One provider processes A then B;
foreign reads fail. Additional cases preserve manual support after source deletion,
disablement, zero allowance and queue failure. The browser-script suite covers
late edits, type-and-delete revision changes, withdrawn sources, original/candidate
review, feedback retries and clipped visibility.

### Current runtime evaluation

`AiAssistRuntimeEvaluationTest` reads
`tests/Fixtures/ai-assist-runtime-evaluation.json` and exercises the current shared
reply and private refinement pipelines. Eleven reply cases and fourteen refinement
cases include EN/DE/IT supported text, conflict, injected instructions, fabricated
citations, unselected sentences, unsupported action/promise claims, changed numbers,
dropped qualification/negation, missing guidance and wrong-language translation.

The JSON receipts retain per-case state/decision, selected-source count, prompt
hashes and guard identities. Source hashes conservatively cover all production
PHP under app/config/database/lang/routes/views, the complete synthetic fixture
tree, the evaluation test, bootstrap source and Composer manifests. Generated
bootstrap caches and environment secrets are excluded. This includes presentation,
policy, accounting and orchestration dependencies; even unrelated production PHP
changes rotate the identity and require fresh evidence. A receipt identity
regression checks representative dependencies are present.
They omit prompts, unsent draft prose and credentials. A case enters the receipt
only after its state, single provider call/usage attempt, no message/ticket writes,
private input cleanup and no external Laravel HTTP assertions pass. Filtered or
failed runs cannot claim `complete=true`.

These are adversarial examples of runtime guard behavior, **not measured live model
quality**. The reply guard deliberately accepts only supported complete extractive
sentences; retrieval remains bounded lexical selection. Private refinement uses
conservative heuristics, not a general semantic equivalence proof. Human review of
facts, numbers, qualifications and meaning remains necessary before Apply/Send.

### Upgrade and containment

`AiAssistUpgradeContainmentTest` reverses exactly eleven named feature migrations
inside the disposable test schema, seeds historical records while feature columns
are absent, then reapplies them. Existing conversation/message/manual suggestion
records survive. No account connection or automatic consent is backfilled; the
installation path remains Manual, opens make no calls, explicit manual preparation
still queues, and separate human Send works. This is a schema rehearsal, not proof
of a deployed installer, backup restoration or production migration.

Four additional cases cover pending/in-flight × disable/disconnect. Pending work
makes no provider call and releases known-unsent holds. In-flight completion cannot
publish/use after containment, but its request and seven reported tokens remain
charged. Disconnect removes owned configuration and refuses silent installation
fallback. Manual support stays usable. Operational containment never recommends
reversing schema migrations over live support data.

## Native browser evidence

The disposable HTTPS preview used actual authenticated pages, form handlers,
database queue workers and deterministic provider/probe/discovery bindings.
Browser automation used a separate synthetic account from Adam's human preview.
The temporary certificate was scoped to that preview; system trust was unchanged.

| Journey | Observed evidence | Limit |
| --- | --- | --- |
| Owner setup | Draft/test/reviewed save, separate Enable and account/site Prepare consent; saved key blank | Synthetic connectivity, not remote provider readiness |
| Agent | Actual Insert changed only the composer; private Preview reached Ready; message count stayed one | Human review and deliberate final Send are separate |
| Six Assist states | Preparing, Ready, Stale, Unavailable, Disabled, Insufficient context; no overflow/background insertion; Use enabled only in Ready; one polite rail region | Held preparation is a test fixture; not a provider latency measurement |
| Admin | Ready, Stale on actual unsaved edit, Preparing, Unavailable, Configured but untested; write-only key and pending/untested action restrictions | Non-Ready records were projected on an owned fixture and restored; actual setup journey recorded separately |
| Responsive/locales | Twelve Ready rows: EN/DE/IT × light/dark × 1440/390 px, reduced motion; reachable Send, no horizontal overflow, transcript dominance on desktop | Emulation is not a physical-device pass; six-state capture is EN/desktop/light, not the full cross-product |
| Source freshness | Actual new visitor turn changes Ready to Stale; existing human draft survives; insertion disabled | Covered on a Manual fixture to prevent regeneration |
| Keyboard | See `native-keyboard.json` for executed native disclosure, source-tab and preview/cancel assertions | Browser focus assertions are not spoken screen-reader acceptance |

The selected screenshots show the corrected desktop controls and narrow German
dark composer. Machine receipts retain dimensions/preferences and observed checks;
they are not design mocks or a release certificate. Adam subsequently reports
accessibility/screen-reader acceptance using macOS VoiceOver. This is a human-reported pass, without a detailed spoken-announcement
transcript or named browser/version. Adam separately confirms a real phone/tablet
pass. Localized human comprehension and a complete device matrix are not inferred
from these reports. See the [human receipt](evidence/ai-assist/2026-10-17/human-acceptance.json);
`verification.json` preserves the earlier automated capture with human gates still
pending at that time.

## Predecessor acceptance audit

| Issue | Integrated evidence |
| --- | --- |
| #1166 contract | Full-flow boundaries; private output, explicit Use/Apply/Send, no autonomous support mutations; ADR 0004 unchanged |
| #1167 connections | `AccountAiConnectionsTest`, `AccountAiConnectionPermissionTest`; A/B full flow, write-only native setup |
| #1168 providers | `AgentCopilotProviderTest`, `GuardedAccountAiConnectionProbeTest`; guarded routes/receipts and no hidden fallback |
| #1169 administration | `AgentAccountAiAssistTest`, policy/UI/permission tests; native grouped setup and admin state checks |
| #1170 lifecycle | Preparation lifecycle/identity/consent/audit/throttle suites, true concurrency, source-revision and containment tests |
| #1171 rail | Rail/panel/source inspection suites, browser scripts, native six-state/responsive/keyboard evidence; human macOS VoiceOver pass linked above |
| #1172 grounding | Grounded reply integration/selector/parser/reference suites and current eleven-case runtime reply evaluation |
| #1173 refinement | Context/lifecycle/audit/safety/true concurrency suites, fourteen-case runtime evaluation and browser edit preservation |
| #1174 usage | Complete ten-criterion mapping below |
| #1175 observations | Feedback/retention/audit/true concurrency suites, visible/inserted distinctions, scoped reports and privacy tests |

### #1174: ten integrated criteria

| Criterion | Current acceptance evidence |
| --- | --- |
| Visible allowances/caps and atomic reservation | Usage policy/ledger/admission suites; account AI administration and guarded provider integration |
| Database UTC periods and consistent scope locking | `AiUsageLedgerTest`, `AiUsageLedgerConcurrencyTest`; explicit database-time clock cases |
| Queued midnight rollover, original dispatch reconciliation | Ledger rollover/late/duplicate reconciliation cases; provider usage integration |
| Cross-midnight concurrency; inherit/zero/narrow rules | Ledger contention and policy tests; zero-allowance full-flow manual fallback |
| Concurrent requests/viewers and shared charge | Independent-process usage/preparation concurrency; equivalent-generation identity and full-flow cache reuse |
| Reported/unknown usage and no free retries | Usage integration, ledger settlement/parser tests; failed/partial/unknown response cases |
| Bounded expiry/cooldown/manual retry/no hidden fallback | Ledger expiry, preparation throttle/lifecycle, guarded connection tests and disconnect containment |
| Sanitized failure states and manual support | Account controls, panel integration, queue/disabled/budget full flow; native Unavailable/Disabled |
| Content-free observed usage/failures | `AssistFeedbackTest`, preparation/private audits, retention; configured model identity and bounded reports |
| Fake races/revision/reset/timeout/partial usage | The PostgreSQL scoped/race runs; synthetic runtime/containment additions; no live calls |

These mappings establish integrated acceptance evidence. Human macOS VoiceOver
and real small-device passes are recorded above. #1174 and #1176 close after
final candidate review/CI, completion merge and the GitHub acceptance audit.
Pilot thresholds and containment/recovery are in the
[qualification guide](../product/ai-support-assist-qualification.md). Pilot owner,
cohort and provider are intentionally unassigned until a separately authorized
pilot; no processing has been activated by this qualification record.

## Review and CI follow-up

The runtime/UI candidate `f430c7e6c63d698394a842cf612c597b61dd91cb` passed all
five [CI lanes](https://github.com/adamgreenwell/wayfindr/actions/runs/39064378209):
SQLite 6,893 passed / 79,704 assertions (57 skipped, 7 deprecated), PostgreSQL
6,943 passed / 80,311 assertions (7 skipped, 7 deprecated), widget/assets,
repository contracts and self-hosting contracts. Both recipe-identity P2 findings
are fixed and resolved. Later documentation-only receipts do not change the
1,111-file runtime identity; final PR checks and merge state remain recorded in
[#1199](https://github.com/adamgreenwell/wayfindr/pull/1199).
