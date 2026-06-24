# Self-Hosting Install

Wayfindr is pre-alpha. The current Docker Compose file is for local
development only, but the first staging/demo deployment target is Laravel
Forge.

Forge is the first-class documented path because Wayfindr is Laravel-first and
Forge handles the normal Laravel runtime pieces well: PHP, Nginx, Postgres,
Redis, queues, scheduler, TLS, health checks, and deploy hooks. There is no
hosting requirement or implied partnership here; Forge is simply the clearest
path we are choosing to maintain first.

Self-hosters control their own visitor data, logs, backups, retention windows,
privacy notices, and deletion/export workflows. Review
[data-responsibility.md](../privacy/data-responsibility.md) before using
Wayfindr with real visitors.

For controlled project dogfooding, review
[MVP Dogfood Readiness](../product/mvp-dogfood-readiness.md) before routing
real visitor traffic to an install. It defines the minimum support loop,
runtime gates, and safety boundaries that should be true before Wayfindr is
used outside local testing.

Use [laravel-forge.md](laravel-forge.md) for the current Forge deployment
checklist, deploy script templates, environment values, queue worker, scheduler,
and smoke test.

Use [runtime-requirements.md](runtime-requirements.md) when translating
Wayfindr to a non-Forge host. It documents the generic Laravel runtime contract:
web root, environment shape, queue worker, scheduler, Reverb process, mail,
storage, backups, and the post-install smoke path.

Use [setup-templates.md](setup-templates.md) for the first Docker Compose and
Coolify-style setup-template prototype. It is intentionally a process map and
environment scaffold, not a production installer.

For the Compose prototype, start with the local env generator instead of
hand-copying secrets:

```bash
scripts/self-host/generate-env.sh --app-url https://support.example.com
```

Review the generated `docker/self-hosting/.env` before booting the stack. The
generator creates local application, database, and Reverb secrets, but it leaves
mail in log mode so a real outbound mail provider still has to be configured
and smoke-tested deliberately.

After the application is deployed and the environment is configured, visit
`/setup` on the Wayfindr host to create the first account owner and install
site from the browser. The setup screen is available until the database has an
account-scoped user. If an interrupted bootstrap already created account or site
records but no owner, `/setup` reuses those first-run records so operators do
not need to clean up SQL by hand.

The first owner is also marked as the initial platform operator so they can use
`/operator` for instance readiness diagnostics. Platform operator access remains
separate from account roles and does not grant support-data visibility by
itself. The first site handoff links to this operator console alongside the
account readiness page so setup gaps stay easy to find after the widget snippet
is copied.

Set `WAYFINDR_VERSION` and `WAYFINDR_COMMIT` when your deployment process can
provide release identity values. They are optional, but they make `/operator`
more useful when someone needs to confirm what code is running.

The intended self-hosting baseline is still Docker Compose with:

- Laravel web process,
- queue worker,
- scheduler for Laravel scheduled work such as alert digest delivery,
- realtime process,
- outbound mail transport,
- Postgres,
- Redis,
- database and storage backups.

Production installs should use a public HTTPS `APP_URL`, secure WebSocket
configuration, a real outbound mail provider, and restorable backups. Wayfindr's
operator readiness screens flag the pieces the app can inspect directly and
mark backups as a manual responsibility because backup coverage lives in the
operator's infrastructure.
