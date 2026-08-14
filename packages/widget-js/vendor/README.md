# Vendored third-party code

## pusher.min.js

The widget's realtime transport. Vendored rather than fetched from
`js.pusher.com` so that a self-hosted install serves every byte it runs: an
air-gapped or firewalled deployment gets realtime, a host page with a strict
`script-src 'self' https://<wayfindr-host>` policy is not broken by it, and a
visitor's browser never contacts a third party to use support chat. See
issue #714.

| | |
| --- | --- |
| Package | `pusher-js` |
| Version | `8.3.0` |
| File | `dist/web/pusher.min.js` |
| SHA-256 | `368a455b2523fb21cfb886b4dcef7f391f1ce15815acd2d371e546c0104f61a1` |
| npm integrity | `sha512-6GohP06WlVeomAQQe9qWh1IDzd3+InluWt+ZUOcecVK1SEQkg6a8uYVsvxSJm7cbccfmHhE0jDkmhKIhue8vmA==` |
| Licence | MIT — `pusher-js-LICENCE` |

The vendored bytes were verified identical to `dist/web/pusher.min.js` inside
the published npm tarball for 8.3.0, not merely downloaded from the CDN.

### Updating

1. `curl -fsSL https://js.pusher.com/<version>/pusher.min.js -o pusher.min.js`
2. Verify it matches the npm tarball for that version, and record the new
   version and hashes in the table above.
3. Run `scripts/test-widget-bundle.sh`, which re-checks the recorded SHA-256
   against the file on disk.

### It still names pusher.com internally, and that is fine

The library's built-in defaults include `cdn_https: "https://js.pusher.com"`
and `stats_host: "stats.pusher.com"`. Neither is reachable from Wayfindr's
configuration, and both are pinned by tests rather than left to chance:

- `cdn_https` is only used to lazily fetch dependencies for the **HTTP
  fallback transports**. The widget passes `enabledTransports: ['ws', 'wss']`,
  so those transports never activate and nothing is fetched.
- `stats_host` is only used when stats are enabled. They default to off, and
  the widget also passes `enableStats: false` explicitly, so an upgrade
  changing that default cannot quietly add an outbound request.

So grepping the served script for "pusher.com" finds matches and always will.
The question that matters is whether a browser ever *contacts* it, and it does
not.
