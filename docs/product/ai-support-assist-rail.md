# Conversation Assist rail

The first [#1171](https://github.com/adamgreenwell/wayfindr/issues/1171) slice moves
existing conversation suggestions into the selected Option 1 surface. It builds
on the [merged preparation lifecycle](../architecture/ai-assist-preparation-lifecycle.md)
from [#1189](https://github.com/adamgreenwell/wayfindr/pull/1189).

## Implemented foundation

The transcript and ordinary composer occupy the main column. A collapsible right
rail stacks Catch up, Suggested reply and Sources. Insert and Refresh stay with
the suggestion; Send stays in the ordinary composer. The shared reply-shell
boundary still lets fresh authorized Use find that separate composer. Existing
Manual, Prepare and Off policies and task state handling are reused.

The native disclosure remains usable without JavaScript. With JavaScript, wide
screens default open and narrow screens default closed. A deliberate preference
is stored per agent in this browser; responsive defaults never overwrite it.
Blocked storage leaves the native disclosure usable. Collapse/expand does not
invoke preparation, move drafts, submit forms or announce a workspace reopen.

At widths up to 1,100 pixels the disclosure moves above the main conversation;
its expanded content has bounded scrolling so the composer stays reachable.
The desktop rail is sticky and uses existing IBM Plex, surface/text/rule tokens,
16-pixel section padding and square controls. Status text remains per task.
English, German and Italian labels are included. Per-task coverage and data-use
copy remain available; account article guidance is distinguished from conversation
facts. Off or unavailable tasks have compact resting sections without processing
controls. Reply helpers remain part of ordinary composing.

Policy lookup failures leave the ordinary conversation usable and show unavailable
rail sections without processing controls. Native legacy/prepared status fragments
derive the same rail labels, so polling preserves the selected headings and
insertion copy in every dashboard locale.

## Remaining #1171 work

This foundation does not close the issue. The remaining integration includes:

- Preview/Apply/Cancel for an existing draft, with original preservation and
  revision checks. Current dirty-draft insertion remains safely blocked.
- Inspectable authorized source links and the selected-processor/context
  disclosure, including the explicit refinement data-use explanation.
- Rail-foot included-message position/freshness and consolidated limitations.
- One polite rail live region across legacy and prepared task updates, with
  meaningful announcements and the full keyboard/screen-reader acceptance pass.
- Full Option 1 state, conversion, responsive and theme qualification after
  those behaviors land. Grounded generation and refinement remain their separate
  #1172/#1173 implementation slices.

## Verification

HTTP rendering tests cover translated placement, read-only Prepare rendering,
Manual initial actions, Off and preserved restored drafts. Browser-script tests
exercise responsive defaults, saved preferences, denied storage, collapse without
new work and fresh insertion into the separate composer without sending. Copied
mutants prove the default/preference assertions catch regressions. Real-browser
checks use synthetic accounts, isolated storage and disposable PostgreSQL; they
establish UI behavior without activating a real provider.
