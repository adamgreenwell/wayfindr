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
insertion copy in every dashboard locale. Each section keeps an independent resting
heading and status, revealed if legacy polling removes its card. This fallback
only observes direct card changes; it never prepares work or touches the composer.

## Completed panel integration

An existing composer draft opens a stable Preview reply section with the exact
original and proposed replacement. Cancel never writes a captured original back
over newer text. Apply performs a second fresh authorized Use read and compares
the exact original value, input/change revision, source revision, generation,
policy, article revision and reviewed candidate. Typing and deleting back to the
same text still invalidates Apply. The preview is transient page memory only;
no unsent draft is sent or stored durably. Empty-composer insertion and reviewed
ticket details keep their established separate paths; Assist never submits.

Sources open in a labelled independent tab, preserving the conversation and
composer. Inspection is a read-only conversation capability for replying agents;
it requires selected membership, current policy/connection, publication and the
exact full article revision. It has no authoring controls, model calls or usage
reservations. A full-body digest inside the existing opaque HMAC detects even
same-second edits beyond the displayed excerpt. Included-position links stay
within the current document and preserve its query and draft.

The rail foot lists each task independently: state, included conversation
position and count of text messages used. Both current and retained prior output
use provenance from the exact displayed artifact. Positions follow the
conversation's transcript ordering rather than exposing global IDs as human
message numbers. An activity boundary can include attachment-only messages;
attachment content remains excluded.

How Assist uses this conversation identifies only the selected connection source,
provider, model, optional OpenRouter upstream and effective character ceiling.
Credentials, endpoints and configuration objects are excluded. It explains bounded
scrubbed subject/message text, excluded data, current local account-article
matching and the explicit unsent-draft boundary for refinement. Grounded generation
and refinement actions remain the separate #1172/#1173 slices.

One polite AI live region outside the disclosure announces meaningful task and
preview changes. Initial content and repeated identical polls stay silent.
Background completion never moves focus; intentional Preview, Apply and Cancel
have predictable keyboard focus. Arbitrary original/candidate text uses unknown
language metadata, and the rail follows reduced-motion preferences.

The remaining implementation is delivered in the follow-up feature branch after
#1190; #1171 stays open until that follow-up pull request is merged.

## Verification

HTTP rendering tests cover translated placement, read-only Prepare rendering,
Manual initial actions, Off and preserved restored drafts. Browser-script tests
exercise responsive defaults, saved preferences, denied storage, collapse without
new work and fresh insertion into the separate composer without sending. Copied
variants prove preference, revision, source withdrawal, second authorization,
legacy freshness, language and safe navigation assertions catch regressions. Real-browser
checks use synthetic accounts, isolated storage and disposable PostgreSQL; they
establish UI behavior without activating a real provider.
