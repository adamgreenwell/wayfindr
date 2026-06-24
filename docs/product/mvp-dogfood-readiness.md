# MVP Dogfood Readiness

Wayfindr's first MVP target is controlled dogfooding inside a trusted project,
not a broad production launch. The goal is to run the real support loop with
real operators, real widget installs, and real deployment infrastructure while
keeping expectations honest and safety boundaries visible.

This document defines the readiness contract for that stage.

## MVP Definition

The MVP is ready for controlled dogfooding when a trusted team can:

- install Wayfindr on a production-like HTTPS host;
- create the first account owner and site through browser setup;
- add the widget to a host project without custom code changes in Wayfindr;
- identify a visitor with a safe anonymous or host-provided reference;
- exchange visitor and agent messages with manual refresh as a fallback;
- create and work durable tickets from support conversations;
- receive dashboard and email alerts when configured;
- request consent-based cobrowse observe-mode without sending page state before
  visitor consent;
- use operator readiness screens to spot obvious runtime gaps;
- understand what data is collected and what the self-hosting operator owns.

This MVP is not a promise that every install is production hardened. It is a
focused dogfood gate for one or more trusted projects where operators can watch
the system closely and respond quickly.

## Required Runtime Gates

Before real visitor traffic reaches a dogfood install, the operator should
confirm:

- `APP_URL` is public HTTPS and matches the widget host configuration.
- `APP_DEBUG=false`.
- Database migrations have run against the intended database.
- Queue workers are running on a durable queue connection.
- The scheduler runs once per minute.
- Reverb is running and routed through HTTPS when realtime delivery is enabled.
- The deploy script restarts queues and Reverb after each release.
- Outbound mail is configured and
  `php artisan wayfindr:mail-test --to="verified-recipient@example.com"`
  succeeds.
- Backups are configured outside the application and have a restore path.
- The first account owner can reach `/dashboard`, `/dashboard/readiness`, and
  `/operator`.

If any gate is not true, the install can still be useful for local testing, but
it should not receive real visitor support traffic yet.

## Required Support Loop

The dogfood support loop should work end to end:

1. An operator creates a site and copies the widget snippet.
2. The host project loads the widget from the Wayfindr instance.
3. A visitor can start a conversation and receive a support code.
4. An agent can see the conversation in the queue.
5. The visitor and agent can exchange at least one message each.
6. The agent can create a ticket from the conversation.
7. The ticket can be assigned, labeled, replied to, and moved through its
   lifecycle.
8. The support code can find the conversation, ticket, or visitor context later.
9. Alerts and email delivery behave according to the agent's configured
   preferences.
10. The operator can inspect readiness without viewing customer support data.

Manual refresh must remain a valid fallback. Realtime delivery makes the
experience better, but the dogfood loop should not collapse if a websocket is
temporarily unavailable.

Use [MVP Demo Rehearsal](mvp-demo-rehearsal.md) to prove this loop against a
specific staging install and host page before showing it to a trusted group.

## Required Safety Boundaries

Dogfood readiness requires these boundaries to hold:

- Widget requests are scoped to a site public key and signed visitor session.
- Agents only see conversations, tickets, visitors, and alerts for sites they
  can support.
- Platform operator access does not bypass account or site support access.
- Cobrowse snapshots, mutation batches, page state, and telemetry are not sent
  before explicit visitor consent.
- Sensitive cobrowse fields are masked in the browser before leaving the page.
- External issue exports remain explicit and conservative; Wayfindr tickets stay
  canonical.
- Automatic mail stays metadata-first and avoids transcript bodies, private
  notes, cobrowse payloads, and raw visitor page data.
- Public docs do not promise compliance, hosting guarantees, or operational
  coverage the project does not yet provide.

## Known MVP Limitations

The first dogfood MVP can tolerate these limitations as long as they are visible:

- no polished one-command production installer;
- no general hosted account lifecycle tooling;
- no automated retention, export, or deletion controls beyond documented
  responsibility guidance;
- no full external issue status sync;
- no remote-control cobrowse;
- no formal SLA, uptime, or support guarantees;
- no AI-assisted support workflow in the critical path.

These are not ignored. They are intentionally outside the first controlled
dogfood gate so the team can learn from the core support loop before expanding
the platform.

## First Hardening Slices

After this contract, the next MVP-hardening slices should prefer:

1. Public widget API abuse controls and documented rate-limit posture. See
   [Widget API Abuse Controls](widget-api-abuse-controls.md).
2. A repeatable host-project smoke test from widget load to ticket creation.
3. Operator-visible dogfood readiness summary for the gates above.
4. Retention visibility and plain-language data responsibility reminders.
5. Widget install diagnostics that help operators confirm the host project is
   using the intended Wayfindr instance and site public key.

Feature expansion should pause when it competes with these hardening gates.
The goal is not to stop building. The goal is to make the first real use boring
in the best possible way.
