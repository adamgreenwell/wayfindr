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

- Review `/operator` and `/dashboard/readiness` after deploys.
- Confirm `php artisan queue:failed` is empty after a smoke test.
- Confirm `php artisan schedule:list` renders and contains expected work.
- Run `php artisan wayfindr:cobrowse-transport-smoke` when validating realtime.
- Run a real mail smoke after changing the mail provider.
- Watch disk usage for PostgreSQL, attachments, logs, and local archives.

Environment shape, commands, WebSocket proxying, and deploy order live in the
[generic runtime requirements](https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/runtime-requirements.md).
Platform-specific instructions live in [Installation](Installation).
