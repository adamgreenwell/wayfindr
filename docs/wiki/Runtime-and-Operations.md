# Runtime and Operations

[Back to Home](Home)

A healthy Wayfindr installation is several cooperating processes, not just a
Laravel web request.

| Concern | What must stay healthy |
| --- | --- |
| Web | Laravel, the public widget, setup, dashboards, and health endpoints. |
| Queue | Normal background jobs and outbound notifications. |
| Backup queue | Long-running operator-triggered backup and restore jobs. |
| Scheduler | Laravel's scheduled work, invoked every minute. |
| Reverb | WebSocket delivery for live chat and cobrowse state. |
| PostgreSQL | Durable product and audit data. |
| Redis | Cache, queues, shared coordination, and realtime-friendly state. |
| Storage | Local attachments, logs, and local backup archives. |

## Routine Checks

- Review `/operator` after deploys.
- Confirm `php artisan queue:failed` is empty after a smoke test.
- Confirm `php artisan schedule:list` renders and contains expected work.
- Run `php artisan wayfindr:cobrowse-transport-smoke` when validating realtime.
- Run a real mail smoke after changing the mail provider.
- Watch disk usage for PostgreSQL, attachments, logs, and local archives.

Environment shape, commands, WebSocket proxying, and deploy order live in the
[generic runtime requirements](https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/runtime-requirements.md).
Platform-specific instructions live in [Installation](Installation).

## How Much It Can Take

Wayfindr has been measured under a desk's worth of data — 50,000 conversations
over twelve months. The figures, the hardware, and the gaps are published in the
[performance baseline](https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/performance-baseline.md).

Before that measurement, Wayfindr had never been run under load at all, and both
queues rendered every matching row. What an operator should know:

- **The conversation queue is capped now.** Its closed lane went from 187 MB and
  twenty-three seconds to 1 MB and 161 ms.
- **The ticket queue is not.** It still hydrates every matching row, which is why
  the measurement needs a 1 GB memory limit — the shipped image sets 256M and
  the ticket queue alone builds a 63 MB response. Tracked as
  [#847](https://github.com/adamgreenwell/wayfindr/issues/847). It cannot be
  capped until its attention and external-issue filters run in SQL rather than
  PHP, which is the work that issue is tracking.
- **The conversation detail page does not grow with the desk**, which matters
  because it is where an agent spends the day: 12–13 ms whether the desk holds a
  thousand conversations or fifty thousand.
- **The reports do grow, sub-linearly.** The 90-day window goes from 30 ms at
  1,000 conversations to 607 ms at 50,000 — fifty times the data for about
  twenty times the milliseconds. Usable at this size, and worth watching on
  slower hardware rather than assuming it behaves like the detail page.

### Measuring your own install

**Do not run this on an install serving real traffic.** The seeder is not
measurement scaffolding that disappears afterwards: it writes an owner account
`desk-agent-0@example.test` whose password is literally `password`, and it
commits. Only the measurement transaction rolls back; the seeded desk stays,
login-capable, until you remove it. On an internet-facing install that is a
publicly known owner credential.

Use a staging copy, a restored backup, or a throwaway VM. If a desk was already
seeded somewhere it should not have been, `--fresh` deletes exactly that account
before seeding, so a `--fresh` run on a machine you are about to discard is the
way to be sure it is gone.

Docker Compose, which is what the [one-line and Compose installs](Installation)
produce — `artisan` lives in the `web` container, not on the host:

```bash
docker compose --env-file .env exec web php artisan wayfindr:seed-desk --conversations=50000 --months=12 --fresh --force
```

```bash
docker compose --env-file .env exec web php -d memory_limit=1G artisan wayfindr:measure-dashboard --runs=3
```

Source or Forge deployments, where PHP is on the host:

```bash
php artisan wayfindr:seed-desk --conversations=50000 --months=12 --fresh --force
```

```bash
php -d memory_limit=1G artisan wayfindr:measure-dashboard --runs=3
```

`--force` is required because the image runs as production, and it is a real
warning: this writes tens of thousands of rows to an account of the seeder's
own. `--fresh` deletes exactly that account and refuses if anything it did not
create is sitting there.
