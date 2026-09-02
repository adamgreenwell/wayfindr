# Cobrowse performance on a noisy page

**Measured 2 September 2026.** This is the live counterpart to the
[dashboard baseline](performance-baseline.md): a real widget captured a
deterministic heavy page, Laravel accepted and retained the reports, Reverb
notified a real agent page, and that page fetched and rendered the
server-sanitized replay.

The short answer is that the bounds held and the preview stayed useful. The
stock widget kept every mutation request below its 60,000-byte ceiling. A
deliberate 322-record burst reported 272 skipped records, sent 50 mutations,
and followed with a clean snapshot. One deliberately aborted mutation request
was reported as one dropped batch by the next successful batch. Laravel kept
20 recent batches after 26 had been accepted, and the agent displayed the
pressure-wave result in a median 506 ms.

No product defect was found. There were no unplanned request failures or
uncaught page errors in three runs. The masked sentinel was absent from both
the cobrowse requests and agent preview, and unsafe event attributes and a
JavaScript URL were absent from the server-rendered replay.

## What ran

| | |
| --- | --- |
| Machine | Apple M4 Max, 16 logical CPUs, 128 GB |
| OS | macOS 27.0 (Darwin 27.0.0), arm64 |
| PHP / Laravel | PHP 8.5.8 / Laravel 13.26.1 |
| Browser | Chromium 151.0.7922.34, headless, precise memory reporting enabled |
| Data services | PostgreSQL 17 and Redis 7 in local Docker |
| Application | One local Laravel development-server process |
| Realtime | One local Reverb process over plain local HTTP/WebSocket |
| Browser shape | One visitor/tester page and one authenticated agent page in the same isolated Chromium context |
| Dataset | 100 seeded conversations, then one new cobrowse conversation per run; 103 were visible for the final detail measurement |

The Laravel development server is single-process. Visitor intake and the
agent's automatic preview fetches therefore queue behind each other more than
they would behind a normal multi-worker web server. These numbers are a local
baseline and path proof, not a Reverb capacity claim; concurrent-agent capacity
is measured separately.

## Page and workload

Each of three independent browser contexts built the same fake surface inside
the authenticated site tester:

- 600 synthetic cards and 3,016 injected elements;
- 15 deliberately masked regions (19 masked elements in the complete captured
  page, including the tester's built-in fake fields);
- 25 steady mutation flushes containing text, safe-attribute, subtree-addition,
  subtree-removal, and masked-text changes;
- one deliberately aborted mutation request, followed by a synchronous burst
  of 322 records (320 attribute changes plus safe and masked text changes);
- 26 successful mutation batches, 174 accepted mutations, and about 5.25
  seconds from the first steady change to the pressure-wave preview.

The burst is intentionally above both client boundaries. The 250-record queue
discarded its oldest 72 records, then the 50-item batch boundary skipped 200
more. The resulting 272 is therefore expected evidence of both caps, not an
unexplained loss.

## Exact budgets exercised

| Layer | Budget | Value |
| --- | --- | ---: |
| Widget | Serialized mutation batch | 60,000 bytes |
| Widget | Pending mutation queue | 250 records |
| Widget | Mutation flush | 50 ms |
| Widget | Pressure snapshot cooldown | 30,000 ms |
| Widget | Cobrowse status poll | 5,000 ms |
| Widget | Resync retry limit | 3 attempts |
| Server | Snapshot HTML | 65,535 characters |
| Server | Snapshot text | 10,000 characters |
| Server | Mutations per batch | 50 items |
| Server | Mutation text | 5,000 characters |
| Server | Mutation HTML | 10,000 characters |
| Server | Mutation attribute value | 2,048 characters |
| Server | Recent mutation batches | 20 retained |
| Server | Telemetry payload | 10,485,760 bytes |

The harness reads both the browser's exported defaults and the server's echoed
limits and fails if the values above drift. It does not silently measure a
custom budget.

## Results by layer

### Client collection

The synthetic DOM itself was built in a median 1.5 ms. A normal mutation
request was 1,717 bytes at both the median and p95; the pressure batch was the
largest at 12,797 bytes. All were well below 60,000 bytes.

Across the three runs, mutation HTTP time was 128 ms median, 207 ms p95, and
265 ms maximum. That includes browser-to-Laravel transport and time waiting
behind concurrent preview reads on the single-process development server.

Each run reported exactly 272 skipped records and one dropped batch. The drop
was the harness's one controlled request abort. Natural request failures were
zero.

### HTTP intake and recovery

The initial capture contained 3,167 nodes, 19 masks, 59,977 HTML characters,
and the full 10,000-character text allowance. Its JSON request was 73,000 bytes
including the envelope and took 35–39 ms across the runs.

The pressure mutation was accepted as HTTP 200 in 70–94 ms. The next clean
snapshot carried mutation sequence 27, proving it was the pressure recovery
rather than the initial capture; its 73,009-byte request was accepted in a
median 335 ms.

### Reverb notification

The agent received 30 cobrowse events per run: one telemetry update, one page
state, two snapshots, and 26 mutation events. Those events caused 28 automatic
preview reads per run. From creating the pressure burst to seeing its safe
marker in the agent iframe took 506 ms median and 519 ms p95.

The harness observed four WebSocket connections and three closes per run. They
were caused by the expected agent navigations: submitting the request, the
first-snapshot reload that creates the previously absent replay iframe, and the
final explicit detail-page reload. They are not transport reconnects under
load.

### Storage retention

Laravel accepted 26 batches, retained the newest 20, and trimmed six in every
run. The cumulative counters still reported all 174 accepted mutations, 272
skips, and the controlled drop after the older batch bodies had been trimmed.

### Agent rendering

The browser measurement used the final, populated cobrowse detail page—not the
empty-session control from the dashboard baseline.

| Browser metric | Median | p95 |
| --- | ---: | ---: |
| HTTP response | 56.5 ms | 57.4 ms |
| DOM content loaded | 70.3 ms | 73.6 ms |
| Load complete | 73.9 ms | 76.8 ms |
| Response body | 316,667 bytes | 318,328 bytes |
| Chromium JS heap after load | 8.11 MB | 8.14 MB |

The separate HTTP-kernel measurement targets the last run's exact support code
and rolls every request back:

| Server metric | Result |
| --- | ---: |
| Laravel render, median of 3 | 37.5 ms |
| Queries | 28 |
| Response | 318,150 bytes |
| PHP peak allocated memory | 46,661,632 bytes (44.5 MiB) |
| Status | 200 |

For context, the no-cobrowse detail control measured on the same machine was
23 ms, 26 queries, and about 148 KB. Rendering the populated replay roughly
doubles the response body and adds two queries, but remained below 80 ms to
browser load in this local run.

## Privacy check

The fixture contains only synthetic text. Even so, the published results above
contain no page text, page URL, snapshot HTML, mutation body, visitor token, or
replay source. The machine-readable harness output records only counts,
durations, byte lengths, status codes, booleans, budget values, and the
disposable conversation support codes needed for the exact follow-up command.

Every run also makes three assertions:

- a sentinel inside explicit masked regions never appears in any cobrowse POST;
- the sentinel never appears in the agent iframe's sanitized `srcdoc`;
- an inline event attribute and a `javascript:` URL never survive into the
  agent iframe.

## Reproducing it

Use a throwaway install. The existing desk seeder commits an owner account with
the documented password `password`; only the detail profiler rolls back its
own requests. Do not put this account on an internet-facing installation.

Start from the repository root and prepare the local services and a small,
repeatable desk:

```bash
docker compose up -d postgres redis
cd apps/server
php artisan migrate --force
php artisan wayfindr:seed-desk --conversations=100 --messages=4 --agents=2 --sites=1 --fresh
```

The web and Reverb processes must receive the same local Reverb settings. In
one terminal, from `apps/server`:

```bash
APP_URL=http://127.0.0.1:8000 \
BROADCAST_CONNECTION=reverb \
REVERB_APP_ID=wayfindr-cobrowse-baseline \
REVERB_APP_KEY=wayfindr-cobrowse-baseline-key \
REVERB_APP_SECRET=wayfindr-cobrowse-baseline-secret \
REVERB_HOST=127.0.0.1 REVERB_PORT=8080 REVERB_SCHEME=http \
REVERB_CLIENT_HOST=127.0.0.1 REVERB_CLIENT_PORT=8080 REVERB_CLIENT_SCHEME=http \
php artisan reverb:start --host=127.0.0.1 --port=8080
```

In a second terminal, also from `apps/server`:

```bash
APP_URL=http://127.0.0.1:8000 \
BROADCAST_CONNECTION=reverb \
REVERB_APP_ID=wayfindr-cobrowse-baseline \
REVERB_APP_KEY=wayfindr-cobrowse-baseline-key \
REVERB_APP_SECRET=wayfindr-cobrowse-baseline-secret \
REVERB_HOST=127.0.0.1 REVERB_PORT=8080 REVERB_SCHEME=http \
REVERB_CLIENT_HOST=127.0.0.1 REVERB_CLIENT_PORT=8080 REVERB_CLIENT_SCHEME=http \
php artisan serve --host=127.0.0.1 --port=8000
```

Playwright downloads a version-matched browser separately. From the repository
root, install it once and run the three samples:

```bash
npx --yes --package playwright playwright install chromium
```

```bash
WAYFINDR_BASE_URL=http://127.0.0.1:8000 \
WAYFINDR_AGENT_EMAIL=desk-agent-0@example.test \
WAYFINDR_AGENT_PASSWORD=password \
WAYFINDR_COBROWSE_RUNS=3 \
WAYFINDR_COBROWSE_OUTPUT=/tmp/wayfindr-cobrowse.json \
scripts/smoke/cobrowse-heavy-page.sh
```

The wrapper fails unless the real widget, HTTP intake, Reverb event, automatic
preview read, retained-batch cap, pressure snapshot, and privacy checks all
complete. `WAYFINDR_SITE_ID` can select a particular supported site's tester;
otherwise the first one visible to the measurement agent is used.

Finally, point the rollback-safe profiler at the exact last conversation. From
`apps/server`:

```bash
measurement_support_code="$(php -r '
    $report = json_decode(stream_get_contents(STDIN), true);
    $samples = $report["samples"] ?? [];
    $last = end($samples);
    echo $last["support_code"] ?? "";
' < /tmp/wayfindr-cobrowse.json)"
```

```bash
APP_URL=http://127.0.0.1:8000 \
BROADCAST_CONNECTION=reverb \
REVERB_APP_ID=wayfindr-cobrowse-baseline \
REVERB_APP_KEY=wayfindr-cobrowse-baseline-key \
REVERB_APP_SECRET=wayfindr-cobrowse-baseline-secret \
REVERB_HOST=127.0.0.1 REVERB_PORT=8080 REVERB_SCHEME=http \
REVERB_CLIENT_HOST=127.0.0.1 REVERB_CLIENT_PORT=8080 REVERB_CLIENT_SCHEME=http \
php artisan wayfindr:measure-dashboard --runs=3 --page=detail \
    --support-code="$measurement_support_code" --json
```

Remove the deliberately login-capable desk when finished:

```bash
php artisan wayfindr:seed-desk --purge
```
