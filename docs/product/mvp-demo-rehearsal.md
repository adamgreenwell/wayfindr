# MVP Demo Rehearsal

This runbook turns the MVP dogfood checklist into a repeatable demo rehearsal.
Use it before showing Wayfindr to a small trusted group or before routing a
trusted project through a staging install.

The rehearsal should prove the real support loop, not a perfect production
launch. Keep the scope tight: one host page, one test visitor, one agent, one
conversation, and one ticket.

## Rehearsal Inputs

Prepare these values outside the repository:

- Wayfindr app URL, such as `https://support.example.com`.
- Host page URL that loads the Wayfindr widget.
- Site public key for that host page.
- Test agent email and password.
- A verified recipient for the mail smoke command.
- A backup and restore note from the infrastructure provider.

Do not commit real credentials, production visitor data, private infrastructure
notes, or customer/prospect details to this repository.

## Runtime Gate Check

Before the demo, confirm:

1. The Wayfindr app loads over public HTTPS.
2. Plain HTTP redirects to HTTPS.
3. `/up` returns `200`.
4. `/setup` no longer exposes first-run setup after the first account exists.
5. `/login` loads and the test agent can sign in.
6. `/dashboard/readiness` loads for an account owner or admin.
7. `/operator` loads for a platform operator.
8. Queue workers are running on the intended durable queue connection.
9. The scheduler runs every minute.
10. Reverb is running and routed through HTTPS if realtime is part of the demo.
11. `php artisan wayfindr:mail-test --to="verified-recipient@example.com"`
    succeeds from the deployed app.
12. Backups exist outside Wayfindr and there is a known restore path.

If one of these gates is not true, keep the rehearsal local or internal and
call out the missing gate before anyone mistakes the demo for a ready install.

## Public Widget Intake Smoke

Run this when you only need to prove that the widget API can create a visitor,
conversation, and visitor message. It does not require agent credentials and it
does not create a ticket.

```bash
WAYFINDR_BASE_URL="https://support.example.com" \
WAYFINDR_SITE_PUBLIC_KEY="site_public_key_here" \
scripts/smoke/widget-intake.sh
```

This smoke creates real test records in the target Wayfindr install. Use a
clearly disposable site key or a staging site.

## Full Support-Loop Smoke

Run this when the demo install should prove the checklist from widget load to
ticket detail. The smoke signs in as an agent, opens the created conversation,
creates a ticket, and verifies the ticket detail page.

```bash
WAYFINDR_BASE_URL="https://support.example.com" \
WAYFINDR_HOST_PAGE_URL="https://docs.example.com/help" \
WAYFINDR_SITE_PUBLIC_KEY="site_public_key_here" \
WAYFINDR_AGENT_EMAIL="agent@example.com" \
WAYFINDR_AGENT_PASSWORD="agent-password" \
scripts/smoke/support-loop.sh
```

When `WAYFINDR_HOST_PAGE_URL` is set, the visitor side runs through Chromium
against the real host page: it opens the widget, sends the visitor message,
captures the generated support code, and then lets the agent/ticket checks
continue. If Chromium is not installed for Playwright yet, run:

```bash
npx --yes --package playwright playwright install chromium
```

Optional values:

- `WAYFINDR_VISITOR_SMOKE_MODE=api` skips the browser path and uses direct API
  calls for the visitor side. This is useful for local fallback checks, but it
  does not prove the host page widget loaded.
- `WAYFINDR_SMOKE_SUBJECT` changes the conversation subject.
- `WAYFINDR_SMOKE_MESSAGE` changes the visitor message body.
- `WAYFINDR_SMOKE_TICKET_CATEGORY` defaults to `question`.
- `WAYFINDR_SMOKE_TICKET_PRIORITY` defaults to `normal`.
- `WAYFINDR_ANONYMOUS_ID` pins the test visitor reference for API mode.
- `WAYFINDR_WIDGET_BROWSER_HEADED=1` runs the host-page browser visibly.
- `WAYFINDR_WIDGET_BROWSER_TIMEOUT_MS` adjusts the browser smoke timeout.

When `WAYFINDR_VISITOR_SMOKE_MODE=api` and `WAYFINDR_HOST_PAGE_URL` is set, the
script falls back to a static check that the host page or its linked JavaScript
exposes the expected Wayfindr app URL and site public key. That catches a stale
host deploy, but the browser mode is the stronger demo gate.

## Manual Demo Path

After the smoke passes, rehearse the human-facing path:

1. Open the host page that contains the widget.
2. Start a visitor conversation and copy the support code.
3. Sign in at `/login` as the test agent.
4. Open the conversation queue and find the support code.
5. Reply as the agent.
6. Refresh the widget manually and confirm the visitor sees the reply.
7. Create a ticket from the conversation.
8. Assign the ticket, add a label, add a reply, and move it through one status
   change.
9. Use support-code lookup to find the conversation or ticket.
10. Request cobrowse, grant consent from the widget, and confirm that page
    state appears only after consent.
11. Review `/dashboard/readiness` and `/operator`.
12. Close by naming the known MVP limitations that still apply.

Manual refresh remains an acceptable fallback. If realtime is not part of the
demo, say that plainly and keep the flow calm.

## Pass Criteria

The demo is ready for a small trusted audience when:

- the automated support-loop smoke passes;
- the manual visitor and agent path can be completed twice in a row;
- the operator can explain any readiness warnings;
- mail, queues, scheduler, Reverb, and backups have current status notes;
- no real customer data or sensitive test data is used;
- the demo owner can explain what is still outside the first dogfood MVP.
