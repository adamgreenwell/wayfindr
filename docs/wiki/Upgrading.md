# Upgrading

[Back to Home](Home)

Treat an upgrade as an operator change, not merely an image pull. Back up first,
read the release notes, and have a rollback target before changing a persistent
installation.

## Installer-Managed Stack

From the directory containing the generated `wayfindr` folder:

```bash
./wayfindr/install.sh --upgrade
```

The installer resolves the newest release, refreshes the stack files, pulls the
image, and restarts the services. Its preflight and the artifact enforce actions
that apply to the `image` profile before migration or before serving traffic.

## Host-Managed PHP

Forge and other host checkouts run on PHP supplied by the host. Read the target
release's actions and satisfy every `host`-profile action **before** updating the
checkout. Composer enforces platform requirements when it installs the target
lockfile, but in an in-place deploy that is already after `git pull`; a platform
failure there can leave new source beside the previous vendor tree.

Version 0.8.0 requires PHP 8.4.1 with `curl`, `gd`, and `intl`, using libcurl 7.59.0
or newer. Check the PHP binaries used by Composer, PHP-FPM, queues, the scheduler,
and Reverb, then reload or restart those processes before deploying. Add
`0.8.0/php-runtime-extensions` to `WAYFINDR_ACKNOWLEDGED_ACTIONS` after every
runtime passes; the guard requires this because one CLI process cannot prove the
others. The exact probe and normal Debian/Ubuntu package names are in that
release's changelog.

The current Forge scripts perform CLI checks before creating or updating a
release. Forge stores its own pasted copy of a deploy script, however, so a
repository update does not retrofit that preflight into an existing site. The
release action still applies to those sites and must be completed before their
first 0.8.0 deploy. A container built with Wayfindr's Dockerfile is the `image`
profile and already carries these dependencies.

## Before You Upgrade

1. Confirm the currently running version in `/operator`.
2. Read the target [GitHub release](https://github.com/adamgreenwell/wayfindr/releases).
3. Take a fresh backup and verify the offsite copy.
4. Check the release's required actions and advisory notices.
5. Preserve the previous image tag and environment so rollback remains possible.

Dependency-only security refreshes are still normal upgrades. Wayfindr-built
images carry the reviewed dependency set. After completing any applicable
pre-pull actions, host installs should update the checkout, run
`composer install --no-dev --prefer-dist --no-interaction` from `apps/server`,
and verify `composer audit --locked` before serving traffic.

Do not bypass a release guard casually. A refusal is designed to keep the old
release running when the new artifact cannot safely continue. Follow the
on-screen action, or step through an intermediate release if the guard says the
upgrade skipped required work.

The authoritative behavior is documented in the
[release manifest guide](https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/release-manifest.md)
and [upgrade ADR](https://github.com/adamgreenwell/wayfindr/blob/main/docs/decisions/0013-upgrade-preflight-and-release-requirements.md).
