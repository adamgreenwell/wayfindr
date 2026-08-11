# Installation

[Back to Home](Home)

Wayfindr supports three deployment paths. They all need the same application
shape: a web process, normal and backup queue workers, a one-minute scheduler,
Reverb when realtime is enabled, PostgreSQL, Redis, persistent storage, and
HTTPS for public traffic.

## One-Line Docker Installer

This is the recommended path for an unfamiliar self-hoster. It uses the
official Compose stack and published image, generates safe starter secrets, and
keeps upgrades on the supported path.

Read the
[self-hosting install guide](https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/install.md)
before running it on a persistent host.

## Docker Compose by Hand

Use the committed stack when you want to review or customize environment values
before boot. The
[Compose README](https://github.com/adamgreenwell/wayfindr/blob/main/docker/self-hosting/README.md)
documents pull-only and source-build flows, TLS modes, optional ClamAV, and the
durable volumes.

## Laravel Forge

Forge is a first-class, Laravel-native path. The
[Forge guide](https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/laravel-forge.md)
covers the monorepo web root, deploy script, workers, scheduler, Reverb, and
post-deploy checks.

## Other Platforms

For a plain VPS, Coolify-style host, Kubernetes, or another Laravel-capable
platform, map the
[generic runtime contract](https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/runtime-requirements.md)
rather than copying a Forge-specific setup blindly.
