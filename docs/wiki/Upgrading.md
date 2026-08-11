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
image, and restarts the services. The artifact itself enforces any release
actions that must happen before migration or before serving traffic.

## Before You Upgrade

1. Confirm the currently running version in `/operator`.
2. Read the target [GitHub release](https://github.com/adamgreenwell/wayfindr/releases).
3. Take a fresh backup and verify the offsite copy.
4. Check the release's required actions and advisory notices.
5. Preserve the previous image tag and environment so rollback remains possible.

Dependency-only security refreshes are still normal upgrades. Official images
carry the reviewed dependency set; source-based installs should update the
checkout, run `composer install --no-dev --prefer-dist --no-interaction` from
`apps/server`, and verify `composer audit --locked` before serving traffic.

Do not bypass a release guard casually. A refusal is designed to keep the old
release running when the new artifact cannot safely continue. Follow the
on-screen action, or step through an intermediate release if the guard says the
upgrade skipped required work.

The authoritative behavior is documented in the
[release manifest guide](https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/release-manifest.md)
and [upgrade ADR](https://github.com/adamgreenwell/wayfindr/blob/main/docs/decisions/0013-upgrade-preflight-and-release-requirements.md).
