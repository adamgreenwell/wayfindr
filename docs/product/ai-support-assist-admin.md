# Account AI Assist administration

This first UI slice of [#1169](https://github.com/adamgreenwell/wayfindr/issues/1169)
implements the selected [Option 1 grouped account form](../design/ai-support-assist/2026-10-15/conversation-rail-v5.png).
The [behavior contract](ai-support-assist-contract.md) remains authoritative.

## Connection workflow

Account → AI Assist opens one account-owned connection form. A manager chooses
the permitted installation connection or an account-owned supported provider,
then enters the exact model and any required endpoint or OpenRouter upstream.
Credentials are write-only: the page reports their status and accepts replacement
or clearing without revealing the saved key. Choosing another processor or
endpoint does not forward an existing credential.

Drafts, synthetic connection tests and activation are separate actions. Editing
or testing a proposal preserves the current saved connection. Save connection
requires the exact successfully tested processing revision and current policy.
An older browser form cannot silently overwrite a newer proposal or activate
changed settings. Tests use fixed sample text, never support records, and do
not establish model quality. Optional discovery makes one bounded catalog request;
manual model entry remains available.

Preparing, Ready, Stale, Unavailable and Configured but untested describe the
proposal's test state. The saved connection and processing enablement are shown
separately. Disabling retains configuration. Disconnecting disables processing,
erases account credentials and refuses installation fallback. Reconnection needs
explicit source selection, testing, saving and re-enabling. Account controls
never erase the operator credential.

Only current account members with Manage AI connections can open or change this
screen. Existing lifecycle services recheck writes under account locks. Saving
or re-enabling the shared connection also requires access to every affected site;
a scoped manager can still propose, test, disable or disconnect. Opening the
page makes no external provider request.

## Usage and remaining behavior controls

The separate section below the connection form shows account request/token holds,
charges, observed complete usage, current concurrency and UTC period. These are
allowance counters, not provider invoices. Installation-wide consumption is not
shown to account managers. Blank account limits inherit operator ceilings, zero
denies new guarded attempts, and positive limits may only narrow those ceilings.

This slice does not add account/site Off, Manual or Prepare when opened policies,
task switches, or the shared Relevant articles context switch. Their storage,
revision invalidation and enforcement across queued, late and visible work remain
in #1169, before automatic preparation in #1170. The page describes the current
explicitly requested manual behavior and permitted context instead of presenting
controls that execution would ignore. Saving a connection never enables automatic
preparation. Existing manual upgrade behavior is preserved.

The conversation Assist panel, grounded drafts and draft refinement remain later
slices. Source and synthetic UI qualification do not establish release, deployment,
live-provider testing or production activation.
