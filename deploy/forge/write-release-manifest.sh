#!/usr/bin/env bash
# Forge compatibility wrapper for the host-generic manifest writer. Forge names
# its selected PHP command FORGE_PHP; the shared script uses WAYFINDR_PHP.
set -euo pipefail

root=$(git rev-parse --show-toplevel)
WAYFINDR_PHP="${FORGE_PHP:-php}" \
    bash "$root/deploy/write-release-manifest.sh"
