# Enrolling the independent host updater

The host helper is an explicit opt-in for an official installer-managed Linux
VM. It runs independently of Wayfindr's web and queue processes, so its durable
operation record remains available when the application is unavailable.

This slice supports authenticated preparation and status. It does not apply an
application update, run a protective backup, drain workers, pull an image, run
migrations, or automatically recover a changed database. Its capabilities are
`plan` and `status`; managed execution remains unavailable until the execution
and recovery slices are delivered.

Existing terminal updates and Docker, source, host PHP, and deployment-platform
installations acquire no systemd or helper requirement. The base Compose file
has no helper mounts. A Docker container never receives the Docker daemon socket.

## Supported enrollment

The initial helper supports one installation per Linux systemd host, with
Python 3.11 or newer at `/usr/bin/python3`, Docker Engine at `/usr/bin/docker`, and Docker Compose
installed as a system CLI plugin. User-directory Docker plugins are not loaded
from the helper's isolated Docker configuration.
The host and image architecture must agree and be `amd64` or `arm64`.
Rootless Docker, user namespace remapping, custom Compose files, floating images,
custom images, and prereleases are refused by enrollment.

The installed application must contain `wayfindr:update-plan`. Enrollment checks
that this fixed read-only command exists before installing credentials or the
service. Fetching the helper cannot add that command to an older image.
The draft changes need to reach an application release before an existing
published installation can use them.

Root must deliberately adopt and review the installation first. The directory,
`.env`, `compose.yml`, `install.sh`, and every ancestor must be root-owned, must not be
symlinks, and must not be writable by a group or another user. A typical managed
location is `/opt/wayfindr`. Home directories and helper-owned directories cannot
be used as installation paths. Enrollment does not change ownership or copy
your application files into another location.

Use a reviewed root-owned distribution of these helper files, with the same
official `docker/self-hosting/compose.yml` and guarded `scripts/self-host/install.sh`
bytes as the installed release. The script refuses a customized or different
Compose file or an older unguarded terminal controller. It never silently
replaces `install.sh` to make an installation eligible. The distribution's
paths and ancestors have the same ownership and write restrictions; a reviewed
distribution staged under `/root` is suitable. These checks protect the inputs
used by a privileged host process. No automatic download or helper self-update
is performed.

Enrollment reads Docker metadata through the local Engine socket with an empty
root-owned Docker configuration. It checks the official project and all five
running application services, their identical image identity, and their direct
host process mapping to UID:GID `1000:1000`. Docker contexts and credentials from
the invoking environment are not inherited.

## Explicit enrollment

From the reviewed distribution, after preparing a compatible root-owned
installation:

```bash
sudo /usr/bin/python3 scripts/self-host/enroll-updater.py enroll --install-dir /opt/wayfindr
```

Enrollment creates host-owned identity, a random credential independent of
`APP_KEY`, helper code, a systemd unit, an installation ownership marker, and a
`compose.updater.yml` overlay. It enables and starts the helper after verifying
its authenticated socket and durable startup generation. It does not recreate
an application container or alter `.env`, the base `compose.yml`, or `install.sh`.

Review the generated overlay, then activate its web-only mounts explicitly:

```bash
cd /opt/wayfindr
sudo docker --host unix:///var/run/docker.sock --config /etc/wayfindr-updater/docker \
  compose --env-file .env -f compose.yml -f compose.updater.yml up -d web
```

This explicit step recreates the web container. Choose the time you activate it.
The overlay mounts the helper's socket directory and credential file read-only.
It gives web the installation identity and fixed socket and credential paths;
workers receive no helper mount. Mounting the socket directory keeps the
connection path valid when the helper recreates its socket after a restart.
The systemd unit preserves that directory across stop/start as well, so an
existing container bind does not point at a removed directory. Host reboot
resets `/run`; Docker's re-established binds require separate VM qualification.

## Host-owned paths

| Path | Purpose and access |
| --- | --- |
| `/etc/wayfindr-updater/installation.json` | Root-only identity, fixed installation path, official image, and reviewed file hashes |
| `/etc/wayfindr-updater/credential.json` | Random installation credential; root-owned, readable only by root and application GID 1000 |
| `/etc/wayfindr-updater/docker/` | Empty root-owned Docker configuration; no invoking-user contexts or credentials |
| `/usr/local/lib/wayfindr-updater/updater.py` | Root-owned helper code |
| `/usr/local/bin/wayfindr-updater` | Root-owned host CLI wrapper |
| `/var/lib/wayfindr-updater/` | Root-only lock, durable journal, and operation state |
| `/run/wayfindr-updater/updater.sock` | Local authenticated protocol; root-owned socket accessible to application GID 1000 |
| `<installation>/.updater-enrolled` | Root-only ownership marker preventing an unsynchronized terminal upgrade |
| `<installation>/compose.updater.yml` | Reviewed web-only helper mounts |

The helper accepts a fixed request vocabulary. Request input cannot choose an
installation path, command, Compose file, Docker context, or arbitrary image.
The host installation record supplies those facts. Status and logs expose
operation metadata and classified errors, rather than environment files,
credentials, command output, or customer content.

## Status and interrupted operations

Enrollment identity can be inspected without changing files:

```bash
sudo /usr/bin/python3 scripts/self-host/enroll-updater.py status
```

Root CLI status and operation logs remain available if web or the daemon is
unavailable:

```bash
sudo wayfindr-updater status
sudo wayfindr-updater logs --operation <operation-uuid>
sudo systemctl status wayfindr-updater.service
```

An operation ID and request identity are retained in the host journal. Retries
refer to that operation rather than starting a second operation. The helper
retains interrupted ownership across a helper or VM restart; a stale heartbeat
does not release it. For a preparation-only interrupted operation, root can
request the restricted reconciliation path:

```bash
sudo systemctl stop wayfindr-updater.service
sudo wayfindr-updater reconcile --operation <operation-uuid>
sudo systemctl start wayfindr-updater.service
```

The root CLI requires the helper's exclusive host lock, so the daemon must be
stopped for reconciliation. The journal validates the known preparation-only
checkpoint before releasing interrupted ownership.

Reconciliation does not imply that a future partially applied update is safe to
retry or roll back. Once application execution is added, uncertain schema state
will require the explicit recovery protocol and maintenance boundary.

While the application is running with the overlay, its authenticated status
command can inspect the same host record:

```bash
sudo docker --host unix:///var/run/docker.sock --config /etc/wayfindr-updater/docker \
  compose --env-file .env -f compose.yml -f compose.updater.yml exec -T web \
  php artisan wayfindr:updater-status --json
```

Add an operation UUID and `--logs` to include its first bounded event page.

## Preserving ownership

Re-running enrollment refuses to overwrite an existing identity, token, helper
code, service, marker, or journal. Partial enrollment is also retained for
operator inspection. An error after host files are created can leave enrollment
incomplete; inspect the service and host files before proceeding. The command
does not repair that condition by replacing files behind an active helper.

The terminal installer refuses `--upgrade` while `.updater-enrolled` exists.
This prevents a separate terminal controller from replacing an installation
owned by the helper without sharing its operation lock and journal. Terminal
upgrades for unenrolled installations behave as before. This preparation-only
slice has no helper replacement, credential rotation, or unenrollment command;
those operations require a separate ownership-aware implementation. Do not
delete an enrollment marker or journal to clear an interrupted operation.

Synthetic tests cover protocol, journal, and enrollment behavior. They do not
qualify this systemd service, Unix-socket mounts, host UID mapping, or VM reboot
behavior on a real VM. That independent qualification belongs to
[#1115](https://github.com/adamgreenwell/wayfindr/issues/1115).

Tracked delivery: [#1110](https://github.com/adamgreenwell/wayfindr/issues/1110) in
[the managed-update epic](https://github.com/adamgreenwell/wayfindr/issues/1107).
