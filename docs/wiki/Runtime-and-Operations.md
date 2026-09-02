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
queues rendered every matching row. Two things an operator should know:

- **The conversation queue is capped now.** Its closed lane went from 187 MB and
  twenty-three seconds to 1 MB and 161 ms.
- **The ticket queue is not.** It still hydrates every matching row, which is why
  the measurement command needs `-d memory_limit=1G` — the shipped image sets
  256M and the ticket queue alone builds a 63 MB response. Tracked as
  [#847](https://github.com/adamgreenwell/wayfindr/issues/847). It cannot be
  capped until its attention and external-issue filters run in SQL rather than
  PHP, which is the work that issue is tracking.

The conversation detail page — where an agent spends most of the day — does not
degrade with the size of the desk, and neither do the report tabs.

Both commands ship, so these are reproducible against your own hardware rather
than taken on trust:

```bash
php artisan wayfindr:seed-desk --conversations=50000 --months=12 --fresh --force
```

```bash
php -d memory_limit=1G artisan wayfindr:measure-dashboard --runs=3
```

`--force` is required because the image runs as production, and it is a real
warning: this writes tens of thousands of rows. They go to an account of the
seeder's own, and `--fresh` deletes exactly that account and refuses if anything
it did not create is sitting there. Measurement itself runs inside a transaction
that is always rolled back. Even so, **measure a staging copy if you have one** —
a real install is still being asked to hold a second desk and serve those
responses while you watch.
