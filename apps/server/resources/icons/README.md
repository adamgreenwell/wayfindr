# Icons

The dashboard's icon set (ADR 0014). Rendered with `<x-icon name="…" />`.

```blade
<x-icon name="conversations" />
<x-icon name="search" :size="20" />
<x-icon name="close" label="Close panel" />
```

Decorative by default. Nearly every icon here sits beside its own visible label,
and announcing both makes a screen reader read the navigation twice — so pass
`label` only when the icon carries the meaning alone, as in an icon-only button.

## Why these are hand-drawn

The obvious source was a commercial icon library. It cannot be used here:
Wayfindr is public and AGPL-3.0, so anyone who clones it receives the icons as
redistributable source files, which those licences prohibit. Owning a seat lets
you *use* a library in your own products; it does not let this repository hand
the icons to every self-hoster.

So the set is drawn for Wayfindr and carries the same licence as the rest of the
project. It is deliberately small — sixteen glyphs, added when a surface needs
one, rather than a library imported wholesale.

## Drawing a new one

- 24×24 viewBox, geometry inset to roughly 3–4px so glyphs match optically at
  16px.
- Stroke only, `stroke-width="1.5"`, `stroke="currentColor"`. No fills, and no
  hardcoded colour — an icon that paints itself cannot sit on the dark ground or
  take a site's colour.
- `stroke-linecap="butt"`, `stroke-linejoin="miter"`. Rounded terminals are the
  first thing that softens this direction away from what it is.
- Curves are allowed where they carry recognition rather than decoration. The
  paperclip was first drawn as a sharp spiral and read as a building; arcs made
  it a paperclip. The circle is a Bauhaus primitive, not a compromise.
- **Look at it at 16px before committing.** Two of the first sixteen failed only
  at size, and both looked fine in isolation at 40px.

`tests/Unit/IconSetTest.php` enforces the conventions above;
`tests/Feature/IconComponentTest.php` covers what reaches the page.

The `<svg>` wrapper in each file exists so a glyph can be previewed on its own.
The component supplies the authoritative one and strips the file's, so the set
cannot drift into sixteen slightly different stroke weights.
