# Docker

Local development services live in the root `docker-compose.yml`.

Current services:

- Postgres on host port `54329`
- Redis on host port `6380`

These services support the Laravel scaffold in `apps/server`.

Self-hosting prototype templates live in `docker/self-hosting`. They describe
the intended Docker Compose and Coolify-style process map for the web, queue,
scheduler, Reverb, Postgres, and Redis services, plus a prototype Laravel
server image build. Run `scripts/self-host/generate-env.sh --app-url <url>` to
create a starter env file, then run `scripts/smoke/self-host-compose.sh` from
the repository root to prove the prototype stack locally. They are not
production installers yet.

License: MIT. See [LICENSE](LICENSE).
