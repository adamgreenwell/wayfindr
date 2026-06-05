# 0003: Laravel Forge As A First-Class Deployment Path

Date: 2026-05-22

## Decision

Wayfindr will treat Laravel Forge as a first-class deployment path for staging,
demo, and early production-like environments.

Forge documentation, deploy script templates, smoke tests, and operational
checklists should stay current with the app as it evolves. The project should
make it easy for a Laravel team to launch Wayfindr on Forge without reverse
engineering the monorepo layout or runtime assumptions.

Forge is not a requirement. Wayfindr remains open source and self-hostable on
any infrastructure that can run the required Laravel, Postgres, Redis, queue,
scheduler, and realtime services.

## Rationale

Wayfindr is Laravel-first, so Forge is a natural deployment companion:

- Forge understands Laravel application shape, deploy scripts, queues, scheduled
  jobs, environment files, TLS, and health checks.
- A documented Forge path gives contributors and evaluators a clear way to run
  a production-like environment without adding a custom platform layer.
- Using the Laravel ecosystem where it fits keeps early operations boring,
  familiar, and easier to support.

This preference is practical, not commercial. The repository should not imply a
partnership, sponsorship, hosting requirement, or hidden business motive.

## Consequences

- Forge docs and scripts should be maintained as part of the project, not as an
  afterthought.
- Generic self-hosting guidance must remain possible and honest, even if Forge
  is the most polished documented path.
- Deployment docs should explain Forge-specific assumptions explicitly so they
  can be translated to other hosts.
- New runtime requirements should update both the Forge path and the generic
  infrastructure requirements.

The generic runtime contract now lives in
[runtime-requirements.md](../self-hosting/runtime-requirements.md). Use it as
the baseline when translating Wayfindr to non-Forge hosts.
