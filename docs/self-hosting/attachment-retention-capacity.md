# Attachment-retention capacity

**Measured 2 September 2026.** Wayfindr's hourly attachment sweep removed the
expected expired, failed, and orphaned data from fixtures containing 10,000
private objects on both local storage and a disposable S3-compatible store. It
preserved every bound and unexpired upload, kept their stored metadata exact,
and confirmed a representative from each survivor class remained downloadable
through the application's authorization check.

This establishes the behaviour and approximate cost of this particular object
count on the topology below. It is not a universal storage limit. The harness
allows up to 50,000 objects and 256 MiB of synthetic data, but its S3 mode is
deliberately limited to disposable loopback MinIO. It cannot measure an
operator's remote object store or network.

## What ran

| | |
| --- | --- |
| Revision | `7dd625fcfb120a9125b46e50ef8ec28ed885e961` |
| Working tree | Clean before and after both measurements |
| Machine | Apple M4 Max, 16 logical CPUs, 128 GB |
| OS | macOS 27.0 (Darwin 27.0.0), arm64 |
| Application | PHP 8.5.8, Laravel 13.26.1 |
| Database | SQLite 3.53.3 in a new operating-system temporary directory per run |
| Local storage | Private filesystem under a new `LARAVEL_STORAGE_PATH` |
| S3-compatible storage | MinIO `RELEASE.2025-09-07T16-13-09Z`, one loopback-only Docker 29.7.2 container with disposable bind storage |
| Fixture | 10,000 objects at 1,024 synthetic bytes each: 10,240,000 bytes total |

SQLite is deliberate safety isolation for this storage measurement: every run
gets a new database beside its disposable object store. The retention query and
model behaviour are covered separately by the application's PostgreSQL test
lane; these timings primarily describe storage enumeration, metadata checks,
and deletion on this machine.

The local run used a one-hour orphan grace period, so its fixture included both
1,500 old orphan objects that had to be removed and 500 recent orphan objects
that had to survive. S3 object timestamps cannot be backdated through the
storage API, so the disposable S3 run used a zero-hour orphan grace period and
made all 2,000 unowned objects eligible. Its recent must-survive objects were
the 1,000 unexpired pending uploads, which retained matching database rows and
remained downloadable.

## Results

| Storage driver | Seed | Dry run | Real sweep | Sweep PHP peak | Removed rows | Removed orphan objects | Objects left |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Local filesystem | 943.8 ms | 61.4 ms | 1,263.5 ms | 44.0 MiB | 3,000 | 1,500 | 5,500 |
| S3-compatible MinIO | 27,094.9 ms | 5,381.3 ms | 21,661.1 ms | 68.5 MiB | 3,000 | 2,000 | 5,000 |

The 3,000 removed rows in each run were 2,000 expired pending uploads and
1,000 failed uploads. The 4,000 bound and 1,000 fresh pending rows survived,
as did their objects and exact 1,024-byte metadata. The local run also retained
its 500 recent unowned objects. The dry runs changed neither rows nor objects.

The harness loaded a surviving bound attachment and a surviving pending upload
through `isDownloadableBy` for the fixture's real visitor, read the stored
bytes, and verified status, size, and checksum metadata. Both passed. An
unrelated control account and an object outside the measurement prefix also
survived each sweep byte-for-byte.

The command then found the registered hourly scheduler event, ran that event's
actual command in a child PHP process, and proved that a newly seeded expired
upload row and object were removed. The measured schedule expression was
`0 * * * *`.

### S3 request attempts

The AWS SDK attempt middleware counted the requests made against disposable
MinIO. Retries would therefore appear in these figures as additional attempts.

| Phase | List requests | HEAD requests | Delete requests |
| --- | ---: | ---: | ---: |
| Inventory before the sweep | 11 | 0 | 0 |
| Dry run | 11 | 2,000 | 0 |
| Verify the dry run | 11 | 0 | 0 |
| Real sweep | 8 | 5,000 | 5,000 |
| Verify the real sweep | 6 | 1 | 0 |
| Scheduler-probe setup | 1 | 1 | 0 |
| Final harness cleanup | 7 | 1 | 6 |

Five cleanup requests were S3 batch-delete operations; the sixth removed the
single outside-prefix control object. The scheduled command runs in its own PHP
process, outside the in-process SDK counter, so its deletion request is not
included in the table. The row and object postconditions still prove that
scheduled deletion completed.

The JSON reports contained no credentials or attachment contents. Their
SHA-256 digests were:

- local: `90b43adddcb0987bf779f3e701b300f0e6d61a3efa03167f13b892b65cf4ecb3`;
- S3-compatible: `5c4ce14aeb3c7495c821ba57a47abf30d814192db777d2e76f84cb74693979c4`.

## Reproducing it

Run from the repository root. The wrapper creates a temporary SQLite database,
temporary storage, and the one-conversation desk fixture the attachment models
need. `local` uses only the temporary filesystem; `s3` also starts the pinned
MinIO image on a random loopback port and removes its container and bind data on
exit. Before the destructive `migrate:fresh`, the wrapper boots the application
with a temporary, nonexistent config-cache path and runs the command's read-only
disposable-target preflight. That preflight resolves the SQLite file itself and
rejects symbolic or hard links, so a path under the disposable directory cannot
redirect the migration to a database elsewhere.

```bash
WAYFINDR_ATTACHMENT_RETENTION_PHP_BINARY="$(command -v php)" \
WAYFINDR_ATTACHMENT_RETENTION_OBJECTS=10000 \
WAYFINDR_ATTACHMENT_RETENTION_BYTES=1024 \
WAYFINDR_ATTACHMENT_RETENTION_OUTPUT=/tmp/wayfindr-attachment-retention-local.json \
scripts/smoke/attachment-retention-capacity.sh local
```

```bash
WAYFINDR_ATTACHMENT_RETENTION_PHP_BINARY="$(command -v php)" \
WAYFINDR_ATTACHMENT_RETENTION_OBJECTS=10000 \
WAYFINDR_ATTACHMENT_RETENTION_BYTES=1024 \
WAYFINDR_ATTACHMENT_RETENTION_OUTPUT=/tmp/wayfindr-attachment-retention-s3.json \
scripts/smoke/attachment-retention-capacity.sh s3
```

PHP 8.4.1 or newer is required. Docker is required only for the S3-compatible
run. Report paths must be absolute, outside the repository, new, and have an
existing parent directory; the harness will not overwrite a report. It resolves
that parent before enforcing the repository boundary, so `..` components and
symlinks cannot redirect the report back into the checkout. The command claims
the destination with an exclusive sidecar reservation for the whole measurement
and publishes with a no-replace filesystem operation, so overlapping runs cannot
replace one another's evidence.

The application command underneath the wrapper is:

```bash
WAYFINDR_ATTACHMENT_RETENTION_DISPOSABLE=YES \
php artisan wayfindr:measure-attachment-retention \
    --objects=10000 \
    --bytes=1024 \
    --output=/absolute/path/to/new-report.json \
    --confirm-disposable
```

Do not invoke that command by hand unless the environment is genuinely
disposable and satisfies all of its guards. The wrapper is the supported
reproduction path because it builds those safe targets consistently.

The production cleanup path measured by both the direct and scheduler probes
is:

```bash
php artisan wayfindr:sweep-orphaned-attachments --dry-run
php artisan wayfindr:sweep-orphaned-attachments
php artisan schedule:list
```

The sweep is registered hourly. As with all scheduled Wayfindr work, an
operator must still invoke `php artisan schedule:run` once per minute through
their process manager or hosting platform.

## Safety and cleanup

The measurement command refuses to run unless all of these are true:

- `WAYFINDR_ATTACHMENT_RETENTION_DISPOSABLE` is exactly `YES` and
  `--confirm-disposable` is present;
- the application is not in production or booted from cached configuration;
- the database is SQLite inside the operating-system temporary directory;
- local storage is inside the temporary `LARAVEL_STORAGE_PATH`, or the S3
  endpoint is loopback with the harness's exact dedicated bucket and synthetic
  credentials;
- no additional configured `attachments*` disk could be discovered by the real
  sweep;
- the S3 run root is a unique `runs/wayfindr-attachment-retention-*` prefix;
- the requested fixture is 10–50,000 objects and no more than 256 MiB;
- the output is a new absolute path outside the repository; and
- the Git worktree is clean and `HEAD` does not change during the run.

After verification, both full runs removed the measurement account, the
unrelated control account created by the harness, the run prefix, and the
synthetic outside-prefix control object. The local temporary directory and S3
container storage were then removed by the wrapper. No pre-existing account,
prefix, bucket, or object was used.

## What this does not establish

- It does not establish a maximum attachment count or a failure point.
- It does not model remote S3 latency, throttling, billing, or concurrent
  application traffic; loopback MinIO is a protocol-compatible control.
- It does not measure large attachment bodies. The object count is the focus,
  and the total payload is only 9.77 MiB.
- It does not turn the figures above into an SLO. The local mode can be repeated
  on deployment-like hardware, but the S3 mode intentionally remains a
  loopback protocol control. Measuring a remote service would require a
  separate, strongly guarded harness and isolated test credentials.

No retention defect surfaced in either full run, so there was no separate
defect to file from this measurement.
