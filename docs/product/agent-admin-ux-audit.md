# Agent & Admin UX Audit (living document)

This is a **breadcrumb trail**: where the agent/admin dashboard UX started, what
we changed for the MVP, and what remains. Update it as work lands so future
passes know where we came from and where to go. The deeper restructuring lives
in issue #511 (post-MVP holistic UI re-evaluation); this document is the record
that feeds it.

**Status: MVP UX cohesion pass shipped and live-validated on stage (2026-06-29). All four strokes done; remaining work tracked in #511.**

Status legend: ✅ done · 🔭 deferred to #511 · ⬜ planned

---

## Baseline (captured 2026-06-29)

The dashboard worked but felt disjointed. Root causes, grounded in the code and
a live look at staging:

1. **Pages are tall stacks of same-weight cards.** `class="section"` counts per
   page at baseline:

   | Page | sections | lines |
   |------|----------|-------|
   | `agent/tickets/show` | 50 | 1418 |
   | `agent/sites/show` | 36 | 807 |
   | `agent/conversations/show` | 31 | 1575 |
   | `agent/dashboard` (home) | 28 | 414 |
   | `agent/account/show` | 25 | 570 |

   Nothing signals priority, so every page is a long scroll. The "[X] map"
   in-page jump lists bolted onto the four biggest pages are a symptom: the
   pages are too long to navigate without a table of contents.

2. **One ~1,075-line inline `<style>` block** in
   `components/layouts/app.blade.php`, no component system. Each view
   hand-assembles sections, so spacing / header / density inconsistencies
   accumulate into "off."

3. **Navigation gaps + a wrap glitch.** Primary nav: Dashboard, Conversations,
   Tickets, Alerts, Sites, Account (+ Readiness for admins). **Reply Templates,
   Ticket Labels, and the Operator console were orphaned** (deep-link only). The
   nav also wrapped ("Account" dropped to a second line) at common widths.

4. **The home duplicated itself** — repeated the topbar search, repeated the nav
   (Support queues → Conversations/Tickets), then sliced the same conversation
   queue several ways: a wall of links, not a landing.

---

## MVP cut — four broad strokes

Broad + easy + high-impact, chosen so the demo never needs "this will get better
sometime." None of these restructure the heavy detail pages (that's #511).

1. ✅ **Global section/CSS cohesion pass** — sections now carry a subtle shadow
   so the cards lift off the page instead of reading as flat bordered boxes; the
   shared page-header treatment (below) replaces the per-page header drift.
   (Deeper visual-system work — type scale, spacing tokens — stays in #511.)
2. ✅ **Nav completeness + wrap fix** — the primary nav now sits on its own
   full-width row (no more squeeze/wrap of a single item on wide screens);
   platform operators get an **Operator** nav item; admins reach the previously
   orphaned **Reply Templates** and **Ticket Labels** from a new Management
   group on the Account page (their natural `dashboard.account.*` home).
3. ✅ **Dashboard home focus** — removed the two cards that only duplicated
   other surfaces: the "Find support trail" search card (the topbar search is on
   every page; its not-found feedback moved to the shared layout so it now works
   from any page) and the "Workspace shortcuts" card (profile/sites/account nav
   duplicates). The home now leads with support queues + conversation/ticket
   next steps. (A bolder cut — moving the at-a-glance Team/Sites/Alerts/Realtime
   panels off the home entirely — is left as a taste call for #511.)
4. ✅ **Consistent page header** — a shared `<x-page-header>` component
   (back link / title / subtitle / actions slot) now renders every agent and
   admin page's header, replacing ~17 hand-rolled variants.

---

## Path forward → #511 (post-MVP)

- 🔭 **Real IA for the heavy detail pages** (tickets/conversations/sites/account):
  grouping + progressive disclosure / tabs; demote the cobrowse and readiness
  diagnostic panels below the primary work; retire the "[X] map" band-aid once
  pages are navigable.
- 🔭 **Extract a true component/design system** from the inline CSS.
- 🔭 **Admin "Settings" home** consolidating account, sites, labels, templates,
  readiness.
- 🔭 **Dedicated responsive/mobile pass** on the agent dashboard (the visitor
  widget already had one; the agent UI has not).
- 🔭 **Empty-state + first-run/onboarding** flows.

---

## Progress log

- **2026-06-29** — Audit completed; baseline captured above. MVP cut agreed with
  Adam (all four strokes). Work starting.
- **2026-06-29** — Stroke 2 (nav completeness + wrap fix) done: nav on its own
  row, Operator nav item for operators, Account Management group for Reply
  Templates + Ticket Labels. Full server suite green (796).
- **2026-06-29** — Strokes 4 + 1 done together: new `<x-page-header>` component
  applied across all 17 agent/admin pages; subtle section card shadow for depth.
  Full server suite green (796). Remaining MVP stroke: 3 (dashboard home focus).
- **2026-06-29** — Stroke 3 (dashboard home focus) done: removed the duplicate
  search and workspace-shortcuts cards; relocated lookup not-found feedback to
  the layout. **All four MVP strokes complete.** Full suite green (797). Next:
  a live stage pass to eyeball the visual changes, then the #511 follow-up.
- **2026-06-29** — Live stage validation (wayfindr.on-forge.com, all four
  strokes deployed): confirmed the nav holds one row (no wrap), the shared
  page header renders consistently on the dashboard, conversation list,
  conversation detail, and account page, sections read as lifted cards, and the
  home opens on support queues + next steps with the duplicate cards gone. No
  visual regressions. Admin-only bits (Account Management links, Operator nav)
  are correctly gated and verified by tests rather than live (stage login is a
  plain agent). True narrow-viewport screenshots weren't capturable via the
  tooling; the only mobile-specific change (topbar stacking) is covered by the
  explicit grid-area template and the broader agent mobile pass stays in #511.
  **MVP UX cohesion pass closed.** Remaining items all live under #511.
