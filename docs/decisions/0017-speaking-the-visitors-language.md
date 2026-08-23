# 0017: Speaking the visitor's language

Date: 2026-08-23

## Context

Neither the widget nor the dashboard was translatable.
[#749](https://github.com/adamgreenwell/wayfindr/issues/749) raises it, and
notes the asymmetry that makes it bite Wayfindr harder than a hosted
competitor: operators who choose self-hosting frequently do so for
data-residency reasons, which correlates almost exactly with not operating
primarily in English. The product's strongest advantage is aimed at the buyers
most likely to need a language it does not offer.

This ADR covers the widget. The dashboard is the same problem at fifty times the
volume — roughly 12,000 words across 72 Blade files, ~500 literals in PHP, and
2,880 test assertions that read English prose — and is deliberately a separate
slice.

The widget is the half that matters most and costs least. **An agent chose to
work here and can cope with an English console; a visitor chose nothing.** They
arrived on somebody's website with a question, and the chat box is not a product
they opted into.

## Decision

### 1. Catalogues are inlined in the widget source

Not fetched, not bundled per locale, not spliced in by the server.

The widget has no build step. `packages/widget-js/package.json` has no build
script; `main` points at the source file, and `WidgetScriptController` reads it
from disk per request. A separate locale file would therefore be one more
artifact that has to reach the Docker image — which is exactly how the vendored
realtime client once went missing, leaving the widget silently degraded and
prompting `scripts/test-widget-bundle.sh`. A string that ships inside the only
file being served cannot fail to arrive.

The cost is size and it is bounded: a catalogue is roughly 3 KB. If that ever
stops being a good trade, `WidgetScriptController` already splices content into
the response and can splice one catalogue instead. The seam is a single object.

### 2. The language is resolved before anything is drawn

The panel is built at init, and bootstrap does not run until the visitor opens
it. A language that only arrived with the site payload would show every visitor
an English launcher and correct it afterwards.

So the widget resolves synchronously from what it has — the host page's
attribute and the browser — and adopts the site default later if that turns out
to change the answer.

### 3. The order is host page, then browser, then site default

- **The host page** (`data-wayfindr-locale`, or the `locale` option) wins.
  An application that has signed someone in knows the language they chose.
- **The visitor's browser** comes next. It is the visitor answering for
  themselves.
- **The site's configured default** is last, because it is the operator's guess
  about who visits. It decides for the traffic where nobody better has spoken,
  which is most anonymous traffic — so it still matters.
- **English** when none of them names a language we carry.

The install snippet deliberately does **not** emit the site default as
`data-wayfindr-locale`. Doing so would promote it to the host-page slot and
invert this order, making the operator's guess beat the visitor's own browser.

A requested language we do not carry falls through rather than failing. The next
answer down is still a language the visitor reads.

### 4. Direction follows the catalogue rendered, not the language requested

Wayfindr ships no right-to-left catalogue yet. Asking for Arabic therefore
renders English — and English inside a right-to-left box is worse than English
outside one.

The mechanism is in place and unused: the widget sets `dir` and `lang` on **its
own root**, so a right-to-left panel can sit inside a left-to-right host page
without touching the host's direction, and its CSS uses logical properties
(`inset-inline-end`) so the launcher anchors to the correct corner. Whether a
language runs right to left is answerable today through
`Wayfindr.textDirection()`.

This is honest rather than complete: **RTL layout is implemented and has never
rendered in anger.** It becomes real the day an RTL catalogue ships, and that
should be treated as unproven until then.

### 5. Operator-authored copy is never translated

The away message, the intake introduction and the cobrowse notice are written by
the operator and shown exactly as written, whatever language the widget is
speaking. Three of the widget's visitor-facing strings were already server-
supplied for precisely this reason.

An operator running a German desk writes German; the product does not
second-guess them, and there is nowhere sensible to put a translation of copy
only they can author.

### 6. The supported-language list is duplicated, and a test guards it

The widget cannot read PHP and the server cannot read the widget at runtime, so
`App\Support\Sites\WidgetLanguage::SUPPORTED` and the widget's `MESSAGES` keys
are two lists of the same thing. A test parses the widget source and compares
them.

Offering an operator a language whose catalogue was never added would set a site
default the widget silently ignores — which looks like a broken setting rather
than a missing one. This is the same shape as the design-token drift check, for
the same reason: one truth, two consumers that cannot see each other, and CI
holding them together.

## Consequences

**A translation gap is invisible without help.** A missing key falls back to
English, so a widget looks translated and says one sentence in the wrong
language. Two tests hold the catalogues to the same key set and to the same
placeholders — a translation that drops `{code}` loses the support code the
visitor needs to quote back.

**Message text is parameterised, not concatenated.** Nine sites built sentences
by joining strings, which no translator can reorder. They are templates now.

**German ships, and needs a native speaker.** The translations were produced
during implementation, not by a professional translator. They are grammatical
and consistent but should be reviewed before anyone claims German is supported
to a customer.

**The dashboard remains English.** An operator configuring a German site does so
through an English console, which is the next slice rather than a defect.

**A visitor's own words are still their own.** Nothing here touches conversation
content; machine translation of live messages belongs with the AI tier and is
deliberately out of scope.
