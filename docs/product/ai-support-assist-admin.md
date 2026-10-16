# Account AI Assist administration

Administration for [#1169](https://github.com/adamgreenwell/wayfindr/issues/1169)
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
or re-enabling the shared connection also requires access to every currently enabled site;
a scoped manager can still propose, test, disable or disconnect. Opening the
page makes no external provider request.

## Account and site behavior

A separate section below the connection form controls Off, Manual and Prepare
when opened, plus Summary, Reply draft, Relevant articles and Ticket details.
Account permissions cap site choices: either Off disables assistance, account
Manual caps a site's Prepare choice to Manual, and task permissions intersect.
Ticket details applies only while conversion is open and no linked ticket exists.
The #1170 lifecycle now prepares permitted tasks on actual visible workspace
opens. Prepare automatically sends permitted, scrubbed conversation text to the
selected processor; Manual requires deliberate actions. Earlier Prepare choices
remain configured but behave as Manual until an admin reviews and saves both
the account policy and selected site policies under this disclosure. Old forms
are rejected, and saving alone never queues processing.

An untouched upgrade keeps existing Manual support and local article lookup.
The first explicit policy save initializes existing sites as Manual; later new
sites start Off until deliberately enabled. An enabled explicit policy requires
a usable saved, successfully tested account connection, including an explicitly
saved installation selection. Off can be saved without a working connection.
Explicit policies retire the legacy unmetered execution exception and use guarded
admission and account/operator budgets. Saving a connection alone never chooses
Prepare. Newly enabled sites offer Prepare as the deliberate enablement choice.

Account policy changes require current access to the affected account site set.
Site or bulk changes require Manage AI connections and current support access to
each selected site, checked again under the account lock. Stale forms cannot
replace a newer policy. Native account and site forms bind the reviewed policy
revisions and active processor snapshot; a changed saved processor requires a
fresh review before saving. Unrelated connection draft edits and synthetic tests
leave that active authority unchanged. Bulk saves accept up to 1,000 selected
sites; larger accounts can update smaller selections. Unselected site tokens do
not participate in the save. Native forms send only checked-site tokens;
truncated input is refused with guidance to select a smaller batch, while the
API/service bound remains 1,000 selected sites. A scoped manager sees only sites
they may administer;
shared connection activation and re-enablement also recheck the current enabled
site set. Disable/disconnect remains available.

Relevant articles is one shared permission for the knowledge task and article
context across tasks. Off prevents AI article existence checks, matching,
presentation and future passage retrieval/transmission while transcript-only
help can continue. On explicitly discloses bounded published same-account
passage sharing. Site switches do not create site-owned articles or knowledge
isolation. Legacy local article lookup does not establish provider passage
consent. Current reply/ticket prompts remain transcript-only; grounded replies
and refinement are later slices.

Opaque policy revisions are independent of connection test receipts. Every
policy edit invalidates prior queued work, provider admission, late publication,
visible results and Use authorization, including Off→On cycles. Unsent reserved
allowances are released after commit; dispatched charges and leases reconcile
normally. No network request is made when a policy page is opened or saved.

Use asks the server for a fresh, private, uncached payload. It rechecks current
access, task/policy/generation, source freshness, connection and target eligibility.
Knowledge insertion also binds the reviewed article version. Included-message
edits/deletions mark all four results stale, even when the newest message ID
does not change. The browser checks
again for user edits after the request before filling an empty composer or
untouched ticket form. Policy polling withdraws obsolete suggestions without
erasing an agent's composer or ticket edits. Existing unstamped output must be
regenerated before insertion. Nothing sends a reply or creates a ticket itself.

## Usage

Account request/token holds, conservative charges, observed complete usage,
current concurrency and UTC allowance period are separate from behavior controls.
These are allowance counters, not provider invoices. Installation-wide consumption
is not shown to account managers. Blank account limits inherit operator ceilings,
zero denies new guarded attempts, and positive limits may only narrow them.
Full usage forms bind the numeric settings snapshot so an older tab cannot
restore another manager's limits. Context ceilings similarly narrow the operator
cap for account/site processing.
Ordinary support remains available when AI is Off, unavailable or out of budget.

Source and synthetic UI qualification do not establish release, deployment,
live-provider testing or production activation.
