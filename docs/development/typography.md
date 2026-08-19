# Typography

Provenance for the typefaces vendored in `apps/server/public/fonts/`.

IBM Plex, the dashboard's typeface (ADR 0014). Vendored rather than linked from
Google Fonts or Bunny so that a self-hosted install serves every byte it renders.

Wayfindr installs on `localhost`, on a bare IP, and on `.local` hostnames, and
some run air-gapped. A font fetched from a third party does not fail loudly on
those installs -- the dashboard simply renders in the system stack, looking
approximately right and subtly wrong, on exactly the deployments least able to
notice. The same reasoning as the widget's realtime client in
`packages/widget-js/vendor/`.

Six faces, chosen as the minimum the type scale needs. Every additional weight
is another file every operator ships, so weights are added only when a role
in `packages/design-tokens/tokens.json` actually calls for one.

| File | Package | Version | Weight | Bytes | SHA-256 |
| --- | --- | --- | --- | --- | --- |
| `IBMPlexSans-Regular.woff2` | `@ibm/plex-sans` | `1.1.0` | 400 | 63,020 | `ba711a3085ff9f27440b6b9c4550cfc47c97bf36591d5da958b975bb3add8c1a` |
| `IBMPlexSans-Medium.woff2` | `@ibm/plex-sans` | `1.1.0` | 500 | 66,740 | `5660f8a658f8bb50dbc005232f885eadffd2bc1c235c4f6fbb63469d1f9cde6d` |
| `IBMPlexSans-SemiBold.woff2` | `@ibm/plex-sans` | `1.1.0` | 600 | 67,060 | `f78048030eab62e860efa39a0df79e2e5581bf122eb95b9bc42c0b8a4988d205` |
| `IBMPlexSansCondensed-SemiBold.woff2` | `@ibm/plex-sans-condensed` | `2.0.0` | 600 | 66,040 | `385a082a1eac88343eab01fb6746be04b7175dacaf4550b17dee76ea0f78126d` |
| `IBMPlexMono-Regular.woff2` | `@ibm/plex-mono` | `2.5.0` | 400 | 49,248 | `ba204497f16b6d334cee9d1e963a831b73e3a56e1d6300a8489d18df7214b350` |
| `IBMPlexMono-Medium.woff2` | `@ibm/plex-mono` | `2.5.0` | 500 | 50,400 | `33faf307fa6031fb4062276d7320a6d632de890cbb347576fd80cfa01077bc25` |
The vendored bytes were verified byte-identical to `fonts/complete/woff2/` inside
the published npm tarball for each version, not merely downloaded.

`complete` rather than `split`: the split files are subset by script and need
`unicode-range` bookkeeping across dozens of files. The complete faces carry
Latin, Greek and Cyrillic in one file each, which is what a support desk staffed
in more than one language needs, and 380 KB total is a one-time cached cost on an
application agents keep open all day.

## Licence

SIL Open Font License 1.1 -- `OFL.txt`, shipped alongside the fonts as the
licence requires. OFL-1.1 permits bundling and redistribution, including inside
an AGPL-3.0 project.

## Updating

1. `curl -fsSL "$(npm view @ibm/plex-sans dist.tarball)" | tar xz`
2. Copy the wanted faces from `package/fonts/complete/woff2/`.
3. Record the new version, byte count and SHA-256 in the table above.
4. Run `make design-fonts-test`, which re-checks every recorded hash
   against the file on disk and every `@font-face` against what is vendored.

## Why these are not in the widget

The widget renders inside a customer's page. It declares the Plex stack so it
picks the faces up when the host already has them, but it never loads a font
file: that would put ~180 KB of our typography on a page the site owner did not
opt into, and a host with `font-src 'self'` would block it anyway and fall back
silently. The widget is designed for the fallback because on many sites the
fallback is what it gets. See ADR 0014.

## Why this file is not in `public/fonts/`

Everything under `public/` is served. `OFL.txt` belongs there — the licence should
reach anyone who receives the fonts. This record should not: an install has no
reason to publish its own build provenance at `/fonts/README.md`.
