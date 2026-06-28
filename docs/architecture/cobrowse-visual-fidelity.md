# Cobrowse Visual Fidelity Plan

This is a design note for [#491](https://github.com/adamgreenwell/wayfindr/issues/491)
(part of the observe-mode fidelity epic #490). It proposes how to make the agent
replay preview visually resemble the visitor's page **without** reopening the
privacy surface that the current strip-everything approach avoids. No code ships
with this note; implementation follows once the approach is agreed.

## Current Behavior

The agent replay preview is structural, unstyled HTML. `CobrowseReplayPreview`
removes all styling on the server: `<style>`, `<link>`, and the `style`
attribute are stripped (`UNSAFE_ELEMENT_NAMES` / `UNSAFE_ATTRIBUTE_NAMES`), and
`wrapPreviewHtml()` injects a single generic stylesheet. So the agent sees the
visitor's content and structure, but nothing resembling what the visitor
actually sees. For a flagship feature, "I can see where you're stuck" lands far
harder when the replay looks like the real page.

## Why Styling Is Risky

Re-introducing CSS is the reason this is its own slice. Styling can leak data
and reach the network:

- `url(...)` in `background-image`, `border-image`, `cursor`, `content`, etc. can
  exfiltrate to an attacker-controlled host the moment the preview renders.
- `<link rel="stylesheet">` and `@import` trigger external fetches.
- Web fonts (`@font-face` `src: url(...)`) are network fetches.
- Attribute/value selectors plus `content` can encode page data into requests.
- Even with no network, raw computed layout can aid fingerprinting.

Any visual-fidelity design must treat CSS as hostile input, exactly like the DOM
is treated today.

## Recommended Approach: bounded inline computed-style allowlist

Capture a small allowlist of **safe, resolved style properties per element** in
the widget at snapshot time, serialize them as an inline `style` attribute on
the sanitized clone, and let the server keep (rather than strip) only allowlisted
declarations.

- Capture happens client-side via `getComputedStyle(element)`, reading only an
  explicit allowlist of properties (see below). Computed values are already
  resolved (e.g. colors as `rgb(...)`, lengths as `px`), so no `var()`, no
  cascade, no external references survive.
- Any value containing `url(`, `image-set(`, `@import`, or an external reference
  is dropped. This is belt-and-suspenders: the allowlist already excludes
  image/content properties, and the value scan rejects anything that slips
  through.
- The server gains a strict `style`-attribute sanitizer: parse declarations,
  keep only allowlisted properties whose values match a safe grammar
  (colors, lengths, keywords, font-family names), drop everything else. The
  server stays the source of truth, so an older or hostile widget cannot inject
  raw CSS.
- The preview keeps rendering in the existing inert, sandboxed, `pointer-events:
  none` iframe.

### Proposed property allowlist (initial)

Layout/box: `display`, `box-sizing`, `width`, `height`, `max-width`, `margin*`,
`padding*`, `border*` (width/style/color only), `border-radius`.
Fl/grid: `flex-direction`, `flex-wrap`, `justify-content`, `align-items`, `gap`.
Typography: `color`, `font-family`, `font-size`, `font-weight`, `font-style`,
`line-height`, `text-align`, `text-decoration`, `text-transform`,
`white-space`, `letter-spacing`.
Surface: `background-color` (never `background`/`background-image`), `opacity`,
`visibility`.

Explicitly excluded: anything that takes `url()` (`background-image`,
`list-style-image`, `cursor`, `content`, `border-image`, `mask`), `position`
beyond `static/relative` (avoid layout traps), `@font-face`, transitions,
animations, filters.

### Cost / bounds

Inline styles inflate snapshot size, which is bounded by the existing payload
budget (snapshot HTML 65,535 chars). Mitigations to evaluate during
implementation: cap styled-element count, only emit properties that differ from
a baseline, and fall back to structural-only rendering when the budget is hit
(degrade gracefully, never drop masking).

## Rejected Alternatives

- **Capture and ship the page's stylesheets.** Too much surface: `@import`,
  `url()`, media queries, and selector matching would all have to be sanitized
  and re-scoped to the iframe. High risk, large payload.
- **Reference the live site's CSS from the preview.** Causes external fetches
  from the agent's browser and can leak that an agent is viewing; rejected.
- **Screenshot/canvas the page.** Contradicts ADR 0005 (shared page state, not
  pixels) and would capture unmasked pixels of sensitive fields.

## Privacy Invariants (must hold)

- Masking and form-value clearing run **before** style capture; masked nodes
  still render as `[masked]`.
- No captured style value may cause a network request or carry page data.
- The server re-sanitizes styles independently of the widget.
- Consent, payload budgets, and retention boundaries are unchanged.

## Phasing

1. This design note + agreement on the property allowlist and value grammar.
2. Server-side `style`-attribute sanitizer + tests (safe to land first; with no
   widget changes it is a no-op because the widget sends no styles yet).
3. Widget computed-style capture behind a bounded budget + masking-order tests.
4. Tune the allowlist and budgets against real dogfood pages.

## Open Questions

- Is inline computed-style the right tradeoff vs. accepting a structural-only
  preview for the MVP? (Fidelity is **not** an MVP dogfood gate.)
- How aggressive should the property allowlist be initially — layout + color
  only, or include typography from day one?
- Acceptable snapshot-size ceiling for styled previews before degrading to
  structural-only?
