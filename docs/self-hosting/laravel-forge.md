# Laravel Forge Deployment

This is the first-class staging/demo deployment path for Wayfindr. Forge gives
us a plain Laravel runtime, managed deploy hooks, queue workers, scheduled
jobs, TLS, and health checks without introducing our own platform layer yet.

Forge is recommended, not required. Wayfindr can run on any infrastructure that
can provide the same Laravel, Postgres, Redis, queue, scheduler, and realtime
services. The Forge path gets the most complete docs first because this project
is Laravel-first and Forge is a strong fit for Laravel applications.

No partnership, sponsorship, or hosting requirement is implied.

Wayfindr is still pre-alpha. Use this environment for product validation and
integration smoke tests, not for real customer data.

## Source Control Access

For anonymous-user testing, deploy from your own fork of Wayfindr. A random
self-hoster cannot add deploy keys to the upstream Wayfindr repository, and
they should not need to.

Recommended flow:

1. Fork Wayfindr into your GitHub account or organization.
2. In Forge, choose `Custom Git` as the source control provider.
3. Turn on `Generate a site deploy key for your source control provider`.
4. Add the generated key to your fork's GitHub `Settings > Deploy keys`.
5. Leave `Allow write access` unchecked.
6. Use the fork's SSH repository URL in Forge.

Example repository URL:

```text
git@github.com:your-org/wayfindr.git
```

If GitHub says `Key is already in use`, Forge is probably showing a server-level
SSH key that has already been attached to another repository. GitHub does not
allow the same public key to be reused as a deploy key across repositories. Use
a site deploy key instead so the key is unique to this Wayfindr site.

Using Forge's connected GitHub provider is also fine for teams that prefer
OAuth/App-based access, but the fork plus site deploy key path is the cleanest
repo-scoped install flow to document for self-hosters.

## Forge Site Shape

- Project type: Laravel.
- Repository: your fork, for example `your-org/wayfindr`.
- Initial deploy branch: `main` for stable releases, or a feature branch when
  intentionally testing unreleased work.
- PHP: 8.3 or newer.
- Database: Postgres.
- Cache/queue: Redis.
- Root directory: `/`.
- Web directory: `/apps/server/public`.
- Health check URL: `/up`.

For a new Forge site, leave zero-downtime deployments enabled. Forge enables
this by default for new sites, and it cannot be added to an existing site
later. Add `apps/server/storage` as a shared path so logs and uploaded files
survive release swaps. If Forge pre-populates a `storage -> storage` shared
path, change it to `apps/server/storage -> apps/server/storage` for Wayfindr's
monorepo layout. Forge automatically shares `.env` for zero-downtime sites.

Turn off Forge's creation-time `Install Composer dependencies` option. Wayfindr
is a monorepo, and Forge runs that automatic Composer install from the repository
root. Our `composer.json` lives in `apps/server`, so the install belongs in the
deploy script instead. Also leave the creation-time frontend build disabled; the
deploy script will run it only after the Laravel app has a lockfile to build
from.

## Environment

Manage these values in Forge's site environment editor, not in Git:

```dotenv
APP_NAME=Wayfindr
APP_ENV=staging
APP_KEY=base64:replace-with-generated-key
APP_DEBUG=false
APP_URL=https://replace-with-forge-site-host
WAYFINDR_VERSION=
WAYFINDR_COMMIT=

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=replace-with-forge-db-host
DB_PORT=5432
DB_DATABASE=replace-with-forge-db-name
DB_USERNAME=replace-with-forge-db-user
DB_PASSWORD=replace-with-forge-db-password

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

BROADCAST_CONNECTION=log
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

REVERB_APP_ID=replace-with-reverb-app-id
REVERB_APP_KEY=replace-with-random-public-key
REVERB_APP_SECRET=replace-with-random-secret
REVERB_HOST=replace-with-websocket-host
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
REVERB_SERVER_PATH=

MAIL_MAILER=log
MAIL_FROM_ADDRESS=hello@example.test
MAIL_FROM_NAME="${APP_NAME}"
```

Use `MAIL_MAILER=log` only for smoke installs that should not send email.
Before real support traffic, configure a real outbound provider such as `smtp`,
`ses`, `postmark`, or `resend`, set a monitored `MAIL_FROM_ADDRESS`, and send a
test email from the deployed environment. For SMTP, replace the local
`MAIL_HOST=127.0.0.1` and `MAIL_PORT=2525` defaults with the provider's real
host and port. Leave `MAIL_SCHEME` unset or `null` for port 587 STARTTLS SMTP
providers; use `MAIL_SCHEME=smtps` only for implicit TLS providers on port 465.
For a worked provider example — Google Workspace SMTP relay — plus SPF/DKIM/DMARC
deliverability and a troubleshooting table, see
[email-delivery.md](email-delivery.md).

Keep `BROADCAST_CONNECTION=log` until a Reverb process and WebSocket routing are
ready. Switch it to `reverb` when the site should publish live conversation
message events. `REVERB_APP_KEY` and `REVERB_APP_SECRET` should be long random
strings; `openssl rand -hex 16` is fine for each value.

Generate the `APP_KEY` on the server with:

```bash
cd ~/wayfindr.on-forge.com/current/apps/server
php artisan key:generate --show
```

Forge's zero-downtime `current` directory points to the monorepo root, not the
Laravel application root. Wayfindr ships a root-level `artisan` shim that
forwards to the real console entrypoint under `apps/server`, so `php artisan`
works from the monorepo root as well:

```bash
cd ~/wayfindr.on-forge.com/current
php artisan about
```

That shim is what lets Forge's own root-level tooling — the **Commands** panel,
the managed **scheduler** cron, and the **queue** worker — run `php artisan ...`
from the site root without a path prefix or `Could not open input file:
artisan` error. Running from `apps/server` directly works too:

```bash
cd ~/wayfindr.on-forge.com/current/apps/server
php artisan about
```

Forge stores the site environment file at the monorepo root. The Wayfindr deploy
script links that file into `apps/server/.env` before Composer and Artisan run.
If web routes fail with `No application encryption key has been specified` even
after `APP_KEY` was set in Forge, verify the link exists:

```bash
cd ~/wayfindr.on-forge.com/current/apps/server
readlink .env
php artisan tinker --execute="app('encrypter'); echo 'encrypter ok'.PHP_EOL;"
```

The link should resolve to `../../.env`.

## Deploy Script

Use [zero-downtime-deploy.forge](../../deploy/forge/zero-downtime-deploy.forge)
as the Forge deploy script for new sites. It assumes Forge's zero-downtime
release macros are available and that commands run from the monorepo root. The
macro lines are Forge-specific and are not meant to run in a local shell. After
the release is activated, the script restarts Laravel queues from the active
`current` release with `php artisan queue:restart` and asks Laravel Reverb to
gracefully reload with `php artisan reverb:restart`.

If the site was created with `Install Composer dependencies` enabled and Forge
reports `Composer could not find a composer.json file`, the site can still be
used. Replace the generated deploy script with Wayfindr's script, set the
environment variables, and run a fresh deploy.

If deployment reaches `view:cache` and fails with `View path not found`, verify
the shared storage path is `apps/server/storage -> apps/server/storage` and that
the deploy script includes Wayfindr's runtime-directory preparation step. The
failure usually means Forge linked an empty shared storage directory before the
Laravel storage subdirectories existed.

If zero-downtime deployments were disabled when the site was created, use
[standard-deploy.sh](../../deploy/forge/standard-deploy.sh) instead.

Both scripts:

- link Forge's root `.env` into `apps/server/.env`,
- install production Composer dependencies from `apps/server`,
- skip the frontend build until a `package-lock.json` exists,
- run migrations with `--force`,
- cache config, routes, and views,
- restart queues and Reverb after deploy.

## First Account Setup

After the first deploy, visit `/setup` on the Forge site to create the first
account, owner, and install site from the browser. This is the preferred
first-run path because it keeps the normal self-hosting flow in the web
interface instead of requiring SSH for routine activation.

The setup screen is locked after Wayfindr has an account-scoped user. If an
interrupted deploy or CLI run already created account or site records but no
owner, `/setup` reuses those first-run records instead of asking the operator to
perform database cleanup. If you prefer to create the first records from the
terminal, run the CLI bootstrap command from the Laravel app directory:

```bash
cd ~/wayfindr.on-forge.com/current/apps/server
php artisan wayfindr:bootstrap \
  --account="Acme Support" \
  --name="Ada Agent" \
  --email="ada@example.com" \
  --site="Acme Website" \
  --domain="example.com"
```

When `--password` is omitted, the command generates and prints the first agent
password. When `--site-public-key` is omitted, it generates and prints the
public widget key for the site. The browser setup path asks for the owner
password directly and generates the site public key automatically.

After browser setup completes, Wayfindr signs in the first owner and sends them
directly to the new site's install snippet. The site settings page includes a
copy-ready widget script generated from the site public key, `APP_URL`, and any
public Reverb settings available to the application. It also links platform
operators to `/operator` so they can review instance health before chasing site
install issues. Use the dashboard's `Add site` action when you need separate
public keys for staging, production, or public dogfood sites.

The first owner is also marked as the initial platform operator. Open
`/operator` after bootstrap to review safe system identity and instance
readiness checks. Account owners and admins can still use
`/dashboard/readiness` for the same install checkup from the account dashboard.
These pages flag common self-hosting setup gaps such as missing app keys,
local or insecure public URLs, database connectivity problems, local-only mail
transport, queue worker configuration, Reverb settings, storage permissions,
scheduler setup, and backup/restore planning.

The backup check is intentionally manual. Wayfindr cannot prove Forge snapshots,
database dumps, storage retention, monitoring, or restore drills from inside the
application request. Confirm those pieces in Forge or your infrastructure
provider before putting real visitor conversations through the instance.

`WAYFINDR_VERSION` and `WAYFINDR_COMMIT` are the release identity. A Forge site
builds from source on the host, so nothing bakes an identity into it the way the
official image does. Without them the install falls back to `<VERSION>-dev` (read
from the repository's `VERSION` file) — honest about the lineage, but it does not
pin a build, so anything that compares versions treats it as unverifiable. In
practice that means a **restore cannot confirm the archive matches this install**
and will keep the site in maintenance for you to check (ADR 0012).

**Derive them per deploy rather than typing them in once.** A value set by hand
in Forge's Environment panel survives every later deploy, so a
continuously-deployed branch runs many commits under one identity — and since a
version that is not a development build is treated as an exact identity, backups
taken from materially different code would compare as the *same build* and a
restore would skip its schema-mismatch warning. A stale identity is worse than no
identity, because it is believed.

So have the deploy script rewrite those two lines. They must be written
**before** `config:cache`, because the identity is resolved in a config file and
is therefore baked into the config cache:

`--follow-symlinks` is not optional here. On a zero-downtime site Forge shares
`.env` as a symlink into the release, and GNU `sed -i` without that flag
*replaces* the symlink with a regular copy — silently. The release is then
detached from the Environment panel: later panel edits update the shared file
while this release keeps reading its stale copy until the next deploy.

Keep this block **ASCII-only** when editing it, including the comments. Unlike
the rest of this guide it is not read in place — it is selected, copied, and
pasted into a browser-based editor, and every one of those steps is a chance for
a non-ASCII character to arrive as something else. Two of the `echo` lines carry
their text in single quotes, so a mangled dash there does not spoil a sentence,
it ends a quoted string early and the deploy dies at a syntax error somewhere
further down. An em dash buys nothing that a hyphen does not.

```bash
# Insert before the artisan cache steps. Both scripts have changed into
# `apps/server` by that point, so nothing here may assume the repository root is
# the working directory: `VERSION` lives at the root, and the clean-tree gate
# below would otherwise inspect only `apps/server` and miss a change anywhere
# else in the repo. Resolve the root once and run every path-sensitive command
# through it.
#
# `git ls-files` is scoped to the current directory on its own, and the gate's
# `git diff` calls take a `.` pathspec so that the shared path can be excluded -
# which makes them cwd-sensitive too. Do not drop the `-C "$root"` from either on
# the grounds that a bare `git diff` is repo-wide; these are not bare.
# `git tag` and `git rev-parse` need no help.
root=$(git rev-parse --show-toplevel)

# A checkout sitting exactly on a tag reports that tag's canonical version;
# anything else is a development build. Deriving this rather than hand-setting it is what keeps a
# tag-pinned site honest when it later moves off the tag.
# Pick the highest release-version tag pointing at HEAD.
#
# Enumerate rather than use `git describe --exact-match`, which returns only ONE
# tag: with both `v1.2.3` and a movable `production` on the same commit it may
# return the alias, and adding an alias would then change the identity of an
# unchanged release.
#
# The grammar is anchored and explicit about digits, because shell globs are not
# (`v[0-9]*.[0-9]*.[0-9]*` also accepts `v1-production.2.3`, `1a.2b.3c`,
# `v1.2.3.4`). Matching the whole string doubles as the safety check: git permits
# `&` and `|` in ref names and both are hostile in the sed below - `&` expands to
# the matched text, `|` closes the expression.
#
# It is the real SemVer grammar rather than a digits-and-dots approximation,
# assembled from named parts because one anchored expression would be unreadable.
# ADR 0012 adopts SemVer and plans a parser, so a tag this accepts must be one
# that parser will accept: `01.2.3`, `1.2.3-01` and `1.2.3-alpha..1` all look
# like versions and are none of them valid, and stamping one as an exact release
# identity would record a version nothing downstream can parse.
#
# A trailing `-dev` is reserved for development identities (ADR 0012); a tag
# using it would be recorded as exact here while the restore treats it as
# unverifiable, so it is excluded too.
#
# Anything rejected falls back to the SHA-qualified development identity, which
# is the honest description of a checkout that is not on a release.
# Both Forge deploy scripts run `set -euo pipefail`, so a `grep` that matches
# nothing would abort the deploy - precisely on the untagged branch deploy this
# fallback exists to serve. `|| true` keeps the no-match path successful.
# Canonicalize once, here, rather than at each place that reads this list. ADR
# 0012 makes the unprefixed form canonical, and folding `v1.2.3` into `1.2.3` at
# the boundary means `1.2.3` and its `v1.2.3` alias are one entry rather than
# two - so adding an alias cannot change what is selected, how many candidates
# there appear to be, or the identity of unchanged code. Every step below can
# then assume unprefixed input and compare like with like.
# No leading zeroes in the three core numbers.
semver_core='(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)'
# A prerelease identifier is numeric without leading zeroes, or contains a
# non-digit. Dot-separated, and none of them may be empty.
semver_pre_id='(0|[1-9][0-9]*|[0-9]*[a-zA-Z-][0-9a-zA-Z-]*)'
semver_pre="(-${semver_pre_id}(\.${semver_pre_id})*)?"
# Build metadata is looser: any non-empty alphanumeric-or-hyphen identifiers.
semver_build='(\+[0-9a-zA-Z-]+(\.[0-9a-zA-Z-]+)*)?'

release_tags=$(git tag --points-at HEAD 2>/dev/null \
  | grep -E "^v?${semver_core}${semver_pre}${semver_build}$" \
  | grep -vEi -- '-dev$' \
  | sed -E 's/^v//' \
  | sort -u || true)

# Prefer a stable tag over a prerelease on the same commit. `sort -V` is natural
# ordering, not SemVer precedence: it ranks `1.2.3-alpha` ABOVE `1.2.3`, so a
# promoted release would otherwise be stamped with its prerelease name.
#
# A prerelease is a hyphen immediately after the version core - NOT any hyphen,
# which would misread `1.2.3+build-1` (a stable release with a hyphen in its
# build metadata) as a prerelease and hand the identity back to the alpha tag.
# The `v?` is belt-and-braces: the list is unprefixed by construction, and this
# keeps the filter correct rather than silently wrong if that ever changes.
#
# Sorting is safe now that every candidate is unprefixed. On mixed input it is
# not - `sort -V` compares the surrounding text too, so a digit sorts before `v`
# and `1.3.0` alongside `v1.2.3` would select the OLDER tag. Canonicalizing at
# the boundary is what removes that hazard, rather than a decorated sort key here.
#
# Nothing normalizes the `v` on read yet - RestoreService still compares with a
# plain `!==` - so recording the canonical form is what keeps a site's own
# backups comparable across an alias being added. Official images are still
# stamped verbatim by release-image.yml, so an archive from one reads
# `v0.1.0-alpha.3` against a Forge install's `0.1.0-alpha.3`; that pair compares
# unequal, which warns rather than proceeds, and ADR 0012's read-side
# normalization (slice 3) is what reconciles the two writers for good.
tag=
stable=$(printf '%s\n' "$release_tags" | grep -vE '^v?[0-9]+\.[0-9]+\.[0-9]+-' || true)

# Precedence ignores build metadata (SemVer section 10), so `1.2.3` and `1.2.3+build.1`
# do not rank against each other - they tie. `sort -V | tail -1` would hand the
# identity to whichever sorts last, and adding a metadata alias to a released
# commit would then rename code that has not changed.
#
# Metadata cannot simply be stripped to break the tie: ADR 0012 treats two builds
# differing only in build metadata as different code, so folding them together
# would equate builds that are not the same - a fail-open, and a worse one than
# the problem it solves.
#
# So rank on the metadata-free key, then look at everything holding the top rank.
stable_ranked=$(printf '%s\n' "$stable" | sed -E 's/^([^+]*)(.*)$/\1 \1\2/')
top_precedence=$(printf '%s\n' "$stable_ranked" | cut -d' ' -f1 | sort -V | tail -1)
top_stable=$(printf '%s\n' "$stable_ranked" | awk -v k="$top_precedence" '$1 == k { print $2 }')

# One tag at the top rank is the release. Several means they differ only in build
# metadata, which SemVer does not order - the same position as two prereleases,
# answered the same way: decline, and let the development identity say so.
if [ "$(printf '%s\n' "$top_stable" | grep -c .)" = 1 ]; then
  tag=$top_stable
fi

# No stable tag: take a prerelease only when it is unambiguous. `sort -V` is not
# SemVer precedence for prerelease identifiers either - SemVer ranks `alpha.beta`
# above `alpha.1`, `sort -V` inverts it - and rather than implement that
# comparison here, decline to choose. Picking arbitrarily would let a later alias
# change the identity of unchanged code and report skew between identical builds.
# The count is over canonical, deduplicated versions, so `1.2.3-alpha.1` and its
# `v1.2.3-alpha.1` alias count once. Counting raw tags would read that pair as
# two candidates, call an unambiguous release ambiguous, and drop a site that had
# been identifying as `1.2.3-alpha.1` down to a development identity.
if [ -z "$tag" ] && [ "$(printf '%s\n' "$release_tags" | grep -c .)" = 1 ]; then
  tag=$release_tags
fi

# `tag` is empty here in three cases, all deliberate: no release tag at all, two
# or more stable tags tied on precedence, or two or more prereleases with no
# stable tag. The last two are declined answers rather than missing ones - if a
# future reader takes an empty `tag` for an oversight and resolves it by picking
# a candidate, that reintroduces an alias renaming unchanged code. The only thing
# below that may touch it is the dirty-tree gate, which clears it.

# A commit names the deployed code only when the tree matches it. Forge's
# zero-downtime path makes a fresh checkout per release, so this passes by
# construction - but the standard path runs `git pull` in a persistent checkout
# (deploy/forge/standard-deploy.sh) that can carry local edits, and a sha
# stamped from a modified tree names code that is not what is running. Worse,
# a clean checkout and any number of differently-modified ones would then all
# claim the SAME identity, which is a fail-open in the restore's skew check.
#
# "Dirty" means tracked modifications AND non-ignored untracked files: a
# brand-new migration is invisible to a tracked-changes check but is very much
# part of what is deployed.
#
# The shared path has to be excluded or this check fires on every healthy
# zero-downtime release. Forge's `$CREATE_RELEASE()` installs shared paths before
# this block runs, replacing `apps/server/storage` with a symlink - so git sees
# the ten tracked `.gitignore` files under it as deleted, and the symlink itself
# as untracked. That is the platform doing exactly what it was configured to do,
# and it says nothing about whether the deployed code matches HEAD. Left in, it
# would put every clean release on the unverifiable identity and print a warning
# each time, which is how operators learn to ignore warnings.
#
# Excluded by path rather than by ignoring deletions generally: this hides only
# the directory Forge is documented to share, and a modified migration or a new
# untracked file anywhere else is still caught.
shared_paths=':!apps/server/storage'

if git -C "$root" diff --quiet -- . "$shared_paths" \
   && git -C "$root" diff --cached --quiet -- . "$shared_paths" \
   && [ -z "$(git -C "$root" ls-files --others --exclude-standard -- . "$shared_paths")" ]; then
  commit=$(git rev-parse HEAD)
else
  # A tag names a commit, not a modified copy of one, so a dirty tree cannot
  # claim a release either.
  commit=
  tag=
  echo 'WARNING: working tree is not clean - recording an unverifiable identity.'
fi

# No commit means identify by lineage only. The bare `-dev` is deliberate: ADR
# 0012 makes it never compare equal to anything, so the restore treats it as
# indeterminate and warns rather than asserting a match it cannot support.
if [ -n "$commit" ]; then
  version=${tag:-"$(cat "$root/VERSION")-dev+$commit"}
else
  version=${tag:-"$(cat "$root/VERSION")-dev"}
fi

# Running from `apps/server`, this `.env` is the link both deploy scripts create
# to the Forge environment file at the repository root, so it is a symlink on
# every site type rather than only on zero-downtime ones. Record what it was
# rather than assuming, so the check below still fits if you relocate this block.
#
# A regular file here means the link was already broken before this deploy - most
# likely by an earlier version of this snippet, which used a bare `sed -i` and so
# let GNU sed replace the link with a copy. Everything below still writes the
# identity to the file the app reads, so the version stays correct; what is lost
# is the Environment panel, whose edits now go to a file nobody reads. Say so
# rather than repairing it: that copy may hold the only version of values that
# were edited into it, and a deploy script is the wrong place to silently discard
# an operator's configuration. See "Repairing a detached environment file".
if [ ! -L .env ]; then
  echo 'WARNING: .env is a regular file, not a link to the Forge environment file.'
  echo '         Environment panel edits are not reaching this site.'
fi

was_link=0; [ -L .env ] && was_link=1

sed -i --follow-symlinks "s|^WAYFINDR_VERSION=.*|WAYFINDR_VERSION=${version}|" .env
sed -i --follow-symlinks "s|^WAYFINDR_COMMIT=.*|WAYFINDR_COMMIT=${commit}|" .env

# If it was a shared symlink, it must still be one. Written as an `if` rather
# than an `&&` chain because this is the last line of the snippet: an `&&` chain
# whose warning does not fire exits non-zero, which is fine mid-script but fails
# the whole deploy for anyone who pastes this block at the end of theirs.
if [ "$was_link" = 1 ] && [ ! -L .env ]; then
  echo 'WARNING: .env is no longer a symlink - this release is detached from the Environment panel.'
fi
```

(Both keys are present-but-empty in the environment template above, so `sed`
has a line to replace.)

Deploying a **tagged release** rather than a branch needs nothing extra: the
snippet detects a checkout sitting exactly on a tag and reports that tag's
version, falling back to a development identity otherwise. That is deliberately
derived rather than hand-set — a value typed into the panel for a tagged deploy
keeps claiming that tag after the site moves off it, which is the stale-identity
problem this section exists to avoid.

The recorded value drops the tag's leading `v`, so `v1.2.3` is stored as
`1.2.3`. ADR 0012 makes the unprefixed form canonical, and storing it is what
keeps an identity stable when a commit later gains a `v`-prefixed alias of a tag
it already had.

### Repairing a detached environment file

If a deploy warns that `.env` is a regular file rather than a link, an earlier
version of this snippet replaced the link with a copy — it used a bare `sed -i`,
and GNU sed rewrites the link itself unless told to follow it. The site keeps
working, because Laravel reads the copy, but the Forge Environment panel now
edits a file nothing loads: a password rotated in the panel never reaches the
app, and the discrepancy is invisible until something fails to connect.

Repair it by hand rather than by script — the copy is the file that has been
live, so it, not the panel, may hold the current values.

Start from the site's Laravel directory. Only zero-downtime sites have the
`current` release link; a standard site works in the site path directly, so use
whichever exists rather than assuming:

```bash
cd ~/your-site.com/current/apps/server 2>/dev/null || cd ~/your-site.com/apps/server
pwd
diff <(sort .env) <(sort ../../.env)
```

Copy anything the panel is missing into the Forge Environment editor, save, and
confirm the two agree. Then replace the copy with the link the deploy scripts
expect, keeping a backup until the site is verified:

```bash
mv .env .env.detached.bak
ln -s ../../.env .env
readlink .env    # ../../.env
```

Redeploy, confirm the site is healthy, and delete `.env.detached.bak`. Keeping a
stale copy is its own hazard: `link_laravel_environment_file` skips linking
whenever a `.env` already exists, so a leftover file silently detaches the site
again on a future deploy.

The clean-tree gate is not optional, and it is not only for other people's
hosts. A **zero-downtime** Forge release is a fresh checkout of the ref, so its
tree is clean by construction and the gate costs nothing. The **standard**
deploy path is different: [standard-deploy.sh](../../deploy/forge/standard-deploy.sh)
runs `git pull` in a persistent checkout, without a reset and without a
cleanliness check, so whatever was left in that directory is part of what runs.
A sha stamped there can name code that is not deployed, and every
differently-modified checkout would claim the same identity — the fail-open in
the restore's skew check that this whole section exists to prevent.

So the snippet always gates, and a dirty tree drops both the tag and the commit
rather than publishing a confident lie. What it records instead is a bare
`<version>-dev`, which ADR 0012 defines as never equal to anything — the restore
then reports the pair as indeterminate and warns, which is the honest answer for
a tree nobody can identify. The same gate guards source builds in
[install.md](install.md).

The command refuses to run when bootstrap records already exist. Use `--force`
only when you intentionally want to create or update the supplied account,
agent, and site records:

```bash
php artisan wayfindr:bootstrap \
  --force \
  --account="Acme Support" \
  --name="Ada Agent" \
  --email="ada@example.com" \
  --site="Acme Website"
```

## Queues And Scheduler

Create one Forge queue worker for the site:

```bash
php artisan queue:work redis --sleep=3 --tries=3 --timeout=90
```

If operators will trigger backups from the GUI (Operator → Configure backups →
"Run a backup now"), add a **second** Forge queue worker on the dedicated
`backups` connection, whose retry window exceeds the backup timeout so a slow
backup is never re-released and false-failed:

```bash
php artisan queue:work backups --queue=backups --sleep=5 --tries=1 --timeout=3600
```

Leave this worker off and GUI-triggered backups will queue but never run;
scheduled backups (run under the scheduler) are unaffected.

Use the Laravel Scheduler toggle in Forge's Application panel. Forge will
configure it to run once per minute using the site's selected PHP version.
Forge's scheduler cron and the queue worker both invoke `php artisan ...` from
the site root; the root-level `artisan` shim (above) is what makes that resolve
in the monorepo layout, so neither needs a custom path.

Alert digest email is registered with Laravel's scheduler and runs hourly for
agents who choose digest cadence. After enabling the scheduler, run this from
the site root (or `apps/server`) to confirm Forge can see the task:

```bash
php artisan schedule:list
```

You should see `php artisan wayfindr:send-alert-digests` in the scheduled task
list. Dashboard alerts still appear immediately; this scheduled job only moves
metadata-only digest email. If `schedule:list` cannot run, check the configured
cache/Redis connection as well as the scheduler itself; Laravel inspects
scheduler mutex state while rendering the list.

## Reverb Process

Wayfindr can broadcast conversation messages over Laravel Reverb. Add a Forge
daemon/background process from the Laravel app directory:

```bash
php8.4 artisan reverb:start --host=127.0.0.1 --port=8080
```

Use the `forge` user, one process, and autorestart. The process is long-running;
`Starting server on 127.0.0.1:8080 (...)` means Reverb is listening.

Reverb's public connection settings are intentionally separate from the private
server bind address. In a TLS deployment that proxies WebSockets through the
same site hostname, the common shape is:

```dotenv
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=wayfindr-production
REVERB_APP_KEY=replace-with-public-reverb-key
REVERB_APP_SECRET=replace-with-private-reverb-secret
REVERB_HOST=replace-with-forge-site-host
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
```

Forge-managed Reverb sites should route secure WebSocket traffic to the private
Reverb process. When using the same hostname as `APP_URL`, add Nginx proxy
blocks for `/app` and `/apps` before the main `location /` block:

```nginx
location /app {
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_set_header Scheme $scheme;
    proxy_set_header SERVER_PORT $server_port;
    proxy_set_header REMOTE_ADDR $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";

    proxy_pass http://127.0.0.1:8080;
}

location /apps {
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_set_header Scheme $scheme;
    proxy_set_header SERVER_PORT $server_port;
    proxy_set_header REMOTE_ADDR $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";

    proxy_pass http://127.0.0.1:8080;
}
```

If the WebSocket host differs from `APP_URL`, make sure that hostname has TLS
before testing from an external smoke site.

The widget needs the public Reverb connection settings plus `pusher-js` on the
host page. The dashboard-generated site install snippet includes these values
automatically when Reverb is configured. Manually, the shape looks like this:

```html
<script src="https://js.pusher.com/8.3.0/pusher.min.js"></script>
<script
  src="https://replace-with-forge-site-host/widget.js"
  data-wayfindr-api-base-url="https://replace-with-forge-site-host"
  data-wayfindr-site-key="replace-with-bootstrap-site-public-key"
  data-wayfindr-reverb-app-key="replace-with-public-reverb-app-key"
  data-wayfindr-reverb-host="replace-with-websocket-host"
  data-wayfindr-reverb-port="443"
  data-wayfindr-reverb-scheme="https"
></script>
```

The Reverb app key is safe to expose to browsers. Keep `REVERB_APP_SECRET`
server-side only.

The deploy scripts call `php artisan reverb:restart` after each deploy so the
background process reloads the active release.

## First Deploy Checklist

1. Provision a Forge server with PHP 8.3+, Postgres, Redis, and Nginx.
2. Fork Wayfindr to your GitHub account or organization.
3. Create the Forge site using `Custom Git`.
4. Enable `Generate a site deploy key for your source control provider`.
5. Add the generated key to the fork as a read-only GitHub deploy key.
6. Use the fork's SSH repository URL and the target deploy branch.
7. Set root directory to `/` and web directory to `/apps/server/public`.
8. Turn off Forge's creation-time Composer install and frontend build options.
9. Keep zero-downtime deployments enabled.
10. Add the environment values above in Forge.
11. Replace the generated deploy script with Wayfindr's deploy script.
12. Run the first deploy.
13. Enable TLS before testing the widget from another origin.
14. Enable the deployment health check against `/up`.
15. Visit `/setup` and create the first account owner and install site.
16. Add the queue worker and scheduler, then confirm
    `php artisan wayfindr:send-alert-digests` appears in
    `php artisan schedule:list`. Add the second `backups`-connection worker if
    operators will run backups from the GUI.
17. Add the Reverb process when switching `BROADCAST_CONNECTION` to `reverb`.
18. Configure real outbound mail when email alerts, password resets, or
    notifications should leave the app, then run `php artisan wayfindr:mail-test
    --to="you@example.com"` from `apps/server` to confirm a real inbox receives
    mail.
19. Confirm database and storage backups are scheduled, retained, monitored,
    and restorable.
20. Sign in with the generated first agent credentials.
21. Review `/operator` or `/dashboard/readiness`, resolve any setup gaps, and
    follow the post-install smoke path before real visitor traffic.

## Smoke Test

From a local machine with `curl` and PHP available:

```bash
WAYFINDR_BASE_URL=https://replace-with-forge-site-host \
WAYFINDR_SITE_PUBLIC_KEY=replace-with-bootstrap-site-public-key \
./scripts/smoke/widget-intake.sh
```

Then sign in to the Forge site as the demo agent, confirm the smoke conversation
appears in the agent inbox, and send a short agent reply.

After configuring outbound mail, run a real mail smoke test from the Laravel
application directory:

```bash
cd ~/wayfindr.on-forge.com/current/apps/server
php artisan wayfindr:mail-test --to="verified-recipient@example.com"
```

If your mail provider still has sandbox restrictions, send to a verified
recipient until production access is approved. The command prints the configured
mailer, SMTP host, sender, and recipient, but it never prints SMTP credentials.

For browser embed checks from an external static site, use the deployed widget
script URL:

```text
https://replace-with-forge-site-host/widget.js
```

## Rollback

For zero-downtime deployments, use Forge's deployment history to reactivate the
previous release. If a migration shipped with the failed deploy, inspect it
before rolling back the database. Do not run destructive rollbacks against a
shared staging database unless the data can be thrown away.

For standard deployments, redeploy the previous known-good commit or branch and
run:

```bash
cd apps/server
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
php artisan reverb:restart
```

## References

- [Forge deployments](https://forge.laravel.com/docs/sites/deployments)
- [Forge environment variables](https://forge.laravel.com/docs/sites/environment-variables)
- [Forge queues](https://forge.laravel.com/docs/sites/queues)
- [Forge Laravel application panel](https://forge.laravel.com/docs/sites/laravel)
- [Forge source control](https://forge.laravel.com/docs/source-control)
- [Forge SSH and deploy keys](https://forge.laravel.com/docs/ssh)
