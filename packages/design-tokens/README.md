# Design tokens

The single source of truth for Wayfindr's visual language. See
[ADR 0014](../../docs/decisions/0014-design-system-and-visual-identity.md).

## Changing a colour, a size, or a typeface

Edit `tokens.json`, then:

```bash
make design-tokens
```

Commit `tokens.json` **and** the regenerated blocks. `make design-tokens-test`
runs in CI and fails if they disagree.

## Why a generator instead of a stylesheet

Wayfindr has two interfaces and only one of them has stylesheets.

The dashboard's styling is an inline `<style>` block in
`apps/server/resources/views/components/layouts/app.blade.php`. The widget's is
an array of CSS strings inside `packages/widget-js/src/wayfindr-widget.js` — a
file with **no build step**, which is itself the artifact served at `/widget.js`.
Nothing compiles it, so nothing can bundle a stylesheet into it.

A shared CSS file would therefore reach the dashboard and silently abandon the
widget, which is how the two came to share five hex values as duplicated
literals in two languages with nothing watching them.

So the tokens are *written into* both consumers, between marker comments, by
`scripts/generate-design-tokens.php`. Both outputs are committed, and the drift
check enforces that they match this file — the same way a lockfile is enforced.

## Rules

- **Never edit between the `wayfindr:tokens` markers.** The generator overwrites
  that region, and the drift check fails the build first.
- **Never define a `--wf-*` property by hand** anywhere else. Using one
  (`var(--wf-ink)`) is fine and expected; defining one outside the generated
  block is invisible drift, and the check rejects it.
- **Site colours are assignable identities, not theme values.** `site-*` entries
  are the palette an operator picks from per site; the chosen value reaches the
  widget at runtime, so an operator can recolour a site without a redeploy.
- **`signal-*` outranks site colour.** A site's hue says whose visitor this is;
  a signal says whether someone is waiting. Signal wins the eye.

## Structure

| Group | What it holds |
| --- | --- |
| `color` | Ground, ink, rules, and the brand mark. Themed light/dark. |
| `signal` | Semantic state. `signal-rest` is the neutral resting value that replaced amber-for-everything. |
| `site` | The assignable site identities. |
| `font` | The three IBM Plex stacks. |
| `text` | Type sizes named by role, not by size. |
| `space` | A 4px base scale. Queue density depends on the low end staying small. |
| `structure` | Radii, border and rail widths, minimum row height. |

A token with no `dark` value resolves to its `light` value in both themes, and
the generator omits it from the dark blocks — so the dark blocks show only what
was genuinely reconsidered.
