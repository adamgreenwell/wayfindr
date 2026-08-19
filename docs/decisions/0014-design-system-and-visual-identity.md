# 0014: Design System and Visual Identity

Date: 2026-08-19

## Context

Wayfindr reached 0.6.0 with its self-hosting, upgrade, and backup guarantees settled
([ADR 0009](0009-backup-and-restore.md), [ADR 0011](0011-operator-settings-and-guided-onboarding.md),
[ADR 0012](0012-platform-versioning.md), [ADR 0013](0013-upgrade-preflight-and-release-requirements.md)).
The under-the-hood contracts hold. The interface on top of them has never had a
deliberate pass.

It exists because of one finding, which inverts where this work would naturally go:
**the design system cannot live in the CSS layer.**

There are two interfaces, and only one of them has stylesheets.

**The dashboard's design system is an inline `<style>` block.** All 1,599 lines of it,
at the top of `apps/server/resources/views/components/layouts/app.blade.php`:
roughly 140 hand-rolled classes over nine CSS custom properties.

**The widget's design system is a JavaScript string array.** `packages/widget-js/src/`
`wayfindr-widget.js` builds ~150 rules as string literals from about line 4193. The
package declares `"main": "src/wayfindr-widget.js"` and defines no build script — only
`test`. The source file *is* the shipped artifact, served by `WidgetScriptController`
at `/widget.js`. Nothing compiles it, so nothing can bundle a stylesheet into it.

Any token system that assumes a stylesheet therefore covers the dashboard and silently
abandons the widget. The two have already drifted in precisely the way that predicts:
they share `#0d6f68`, `#1d2523`, `#d8dfdc`, `#62706b` and `#f7f7f3` as **duplicated hex
literals** in two languages, with no mechanism that notices when one changes.

A third fact compounds it. `apps/server/resources/css/app.css` imports Tailwind v4 and
declares Instrument Sans, but **no view anywhere calls `@vite`**. Tailwind has never
shipped. The file is dead code that tells a contributor the opposite of the truth about
what utilities are available.

### What this costs at the user altitude

Reviewed live on the staging deploy, the consequences are not abstract.

**Color carries no information, and information is carried by the wrong color.** Teal is
spent on every link, every button, and every active state, so it signals nothing.
Meanwhile the conversation queue renders "Quiet" and "Unavailable" — neutral resting
states — as amber pills, which reads as a warning about a conversation that is fine.

**The queue cannot be scanned.** Rows are ~130px tall and three are visible at 1440x900.
Three stacked bands of chrome sit above the first row: seven filter buttons, then a
four-field filter panel, then a "Queue snapshot" strip of six count chips. The SITE
column repeats the same site name in bold teal on every row, while the VISITOR column
shows raw identifiers like `anon_6871486e01f24f83a7282a078af690ed`.

**The agent's view of a conversation is not a conversation.** On the conversation detail
page, visitor and agent messages are both full-width left-aligned rectangles under a
header reading "Messages · 3 total", separated only by a faint tint. The widget — the
same conversation, the other side of the glass — does it correctly, with `justify-self:
end`, 88% width, and tinted bubbles. **The visitor sees a chat and the agent sees a log
table.** An attachment-only message renders as an empty box.

**Nothing establishes hierarchy.** The agent home is four identically weighted cards
consuming ~950px of scroll to communicate about eight numbers, all set at body weight.
Its `h1` is the word "Wayfindr", already present in the topbar two lines above, with the
subtitle "Signed in as <the agent's own email address>".

**There is no identity to spend.** No typeface is selected in either system; both fall
back to the raw system stack. There are no icons anywhere in the dashboard. The
interface is light-only (`color-scheme: light`).

This contradicts the product's own operating principle — that the UI should be
unobtrusive and the agent should find what they need *now*, because a customer is
waiting.

### Direction

The product owner's stated preference is Bauhaus in the disciplined sense: form follows
function, no ornament, and **color used as orientation rather than decoration**. Three
platforms were walked live as references — Nucleo (cataloguing and combing large data
sets quickly), Sevalla (feeling like an application rather than a web page), and MyKinsta
(depth presented as small, contextually relevant surfaces).

All three independently ration their accent to one or two uses and keep everything
functional monochrome. Sevalla's orange appears at full saturation only in empty states;
its primary buttons are black. Kinsta puts red on the vulnerability count and leaves
every other figure neutral, with status reduced to a coloured dot. Nucleo uses a single
purple twice, both times commercially.

That convergence is the correction Wayfindr needs, and it is the same principle the
product owner named.

## Decision

### 1. One token source, two generated consumers, enforced by a drift check

Design tokens live in a single source of truth in the repository. A generator writes
them into both consumers:

- the dashboard's CSS custom-property block, and
- the widget's CSS string literals, between marker comments in
  `packages/widget-js/src/wayfindr-widget.js`.

Both generated outputs are **committed**, preserving the property that the widget source
file is the shipped artifact and requires no build step to serve. CI re-runs the
generator and fails if either output differs from what is committed, the same way a
lockfile is enforced.

This is the only shape that satisfies both constraints: a single definition of the visual
language, and a widget that stays a plain classic script.

### 2. Per-site colour is delivered at runtime, not generated

The wayfinding signature (below) assigns a colour per site, editable by operators. That
value must not require regenerating or redeploying the widget, so it is **not** a build
token. It is stored on the site record and delivered to the widget in its existing
site-configuration response, applied as a CSS custom property on the widget root.

Static tokens are generated; site identity is runtime. The two mechanisms stay separate.

### 3. Site colour as the wayfinding system

Each site carries an operator-editable colour drawn from a constrained palette. It
appears as a flat edge on queue rows, a chip in the conversation transcript, the accent on
that site's own pages, and the widget's accent for that site's visitors.

This replaces the repeated bold-teal site name in the queue with an orientation cue an
agent learns rather than reads, which matters because Wayfindr's model is one desk
covering many sites. It also closes the loop across all three surfaces from one operator
decision: the operator chooses, the agent orients, the visitor sees it.

The precedent is functional, not stylistic. Hinnerk Scheper's 1926 colour plan for the
Dessau Bauhaus building coded circulation and function in flat colour so the building
could be navigated by hue. Colour earns its place by doing a job — which is also the
product's name.

### 4. One typeface family, shipped as local assets

IBM Plex — Sans, Condensed, and Mono — replaces the system stack. Contrast comes from
weight and scale within one rationalist family rather than from importing a second voice.

Mono is not cosmetic: support codes (`WF-GZDZRBTE`), commit SHAs, environment keys, and
storage paths are machine identifiers that people read aloud and retype, and they are
currently set in body sans.

The deciding constraint is functional. Wayfindr is self-hosted, including on `localhost`,
bare IP, and `.local` installs (see the local-URL work in 0.4.0). **Fonts must ship as
local assets**, never a CDN request, or an air-gapped install renders in a fallback. One
openly licensed family means fewer files and no external dependency.

### 5. Tokens are authored dual-mode; both modes ship in 0.6.0

Every token is defined with a light and a dark value from the outset. Retrofitting dark
mode across ~140 hand-rolled classes and a JavaScript string array a second time is the
expensive path; doing it once at token-definition time is close to free. Agents work in
this interface all day.

### 6. Dead Tailwind is removed

`apps/server/resources/css/app.css` and its Vite wiring are deleted, or Vite is wired up
and Tailwind genuinely adopted. The current state — configured, declared, never loaded —
misinforms every contributor who reads it. This ADR chooses deletion: the token layer
above does not need a utility framework, and adding one would put a third styling
system alongside the two that already exist.

### 7. Delivered as a single 0.6.0 visual release, built from stacked PRs

The renovation lands as one coordinated release with a changelog entry, rather than
arriving piecemeal across versions for operators who have to explain it to their own
users. To keep that reviewable, work merges as ordered PRs into a
`release/0.6.0-ui` integration branch — token layer, then shell, then queues, then
transcript, then operator surfaces — so no single review exceeds a normal diff.

## Consequences

**Every screen changes.** This is a user-visible break in appearance for existing
self-hosted installs. It requires release notes written for operators to forward to their
own agents, and screenshots in the changelog.

**The sites table gains a colour column**, with a migration and a default assignment
strategy for existing sites so no install lands on a screen of uncoloured rows.

**CI gains a drift check** that must pass before the generated outputs can diverge.
Contributors editing colour in either consumer directly will see a failure telling them
to edit the source and regenerate.

**Font assets are added to the repository and the container image**, increasing artifact
size. This is accepted: the alternative is a CDN dependency that breaks the offline and
air-gapped installs the platform explicitly supports.

**Density increases substantially.** Queue rows targeting roughly a quarter of their
current height will show far more at once. This should be validated with a real agent on
a real queue before the release is cut, not asserted from screenshots — the platform has
been bitten before by verifying below the altitude a user actually operates at.

**The widget's visual change reaches end visitors**, not just operators and agents. It
is the one surface where a regression is seen by someone who never agreed to run
Wayfindr, so it warrants live validation on a real site before release.

**Icons enter the dashboard for the first time.** They are informational, not
decorative: an icon earns its place when recognition beats reading, which holds for
constantly repeated navigation and for state that encodes faster than a word
(attachment, cobrowse active, escalated). It does not hold beside an already-labelled
button, or as decoration on a section heading.
