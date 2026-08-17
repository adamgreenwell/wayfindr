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

### Public, Private, and Local Addresses

The URL you give `--app-url` is the URL the stack serves, including its port,
and its host decides how the certificate is obtained:

- **A real hostname** resolvable from the internet gets a publicly-issued
  certificate, which requires DNS pointing at the machine and ports 80 and 443
  free. Automatic HTTPS only works on 443, because that is what the issuing
  protocol validates over.
- **An `https://` address no public authority can issue for** — an IP address,
  `https://localhost`, or a name under `.local`, `.internal`, `.home.arpa`,
  `.test` and similar reserved suffixes — gets a certificate issued locally
  during install. No DNS record, no public port, and **no restriction to 443**,
  so `https://localhost:2345` is a valid install.
- **A bare `localhost`** infers `http://` and has no certificate at all, so
  there is nothing to trust and no export step. Pass `https://localhost` if you
  want TLS locally.

Loopback addresses publish on loopback only, so nothing is exposed to the
network. Every other address publishes on all interfaces, as its URL implies.

Browsers warn on a locally-issued certificate until its root is trusted; the
installer prints the command that exports it. See
[Quick Start](Quick-Start) for that step and
[Troubleshooting](Troubleshooting) if a local address will not load.

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
