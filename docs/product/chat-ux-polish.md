# Chat UX Polish

Wayfindr chat should feel calm and truthful for visitors and agents. Realtime
features should add useful context without implying certainty the platform
cannot prove.

## Visitor Presence

Visitor presence is currently timestamp-based. The widget refreshes
`last_seen_at` when an authenticated visitor starts a conversation, sends a
message, or fetches the message timeline. Agents see a compact presence signal
on conversation queues and conversation detail screens.

Presence labels intentionally avoid hard claims like "online":

- `Active recently`: the visitor was seen in the last 2 minutes.
- `Recently active`: the visitor was seen in the last 15 minutes.
- `Quiet`: the visitor has not been seen for more than 15 minutes.
- `Not reported`: no visitor heartbeat has been recorded.

Typing indicators and read receipts should follow the same rule: show only what
Wayfindr can prove, expire stale signals quickly, and degrade cleanly to manual
refresh when realtime delivery is unavailable.

## Typing Indicators

Visitor typing is currently a short-lived timestamp reported by the authenticated
widget session. Agents can see `Typing now` in the conversation queue and reply
context only while the signal is fresh.

Agent typing is also short-lived. The dashboard composer reports when an agent
is actively writing, and visitor message refreshes can show `Support is
typing...` while that signal is fresh. The widget hides stale or unavailable
agent typing state instead of making promises it cannot keep.

Typing copy should stay calm and disposable:

- `Typing now`: the visitor sent a recent typing signal.
- `Not typing`: no typing signal exists or the last signal is stale.
- `Support is typing...`: an agent sent a recent typing signal.

Typing should never be treated as a durable workflow state. It is a gentle cue
to wait a beat before sending a reply, not proof that a visitor is still present
or obligated to answer.

## Read Receipts

Visitor read receipts are currently tied to explicit widget message fetches that
include the visitor read signal. Agents can see whether the latest agent reply
was seen from the conversation queue and reply context.

Read labels avoid implying delivery guarantees:

- `Visitor saw reply`: the latest agent reply has a recorded visitor read time.
- `Not seen yet`: the latest agent reply has not received a visitor read signal.
- `No agent reply yet`: there is no agent reply to evaluate.

These labels should remain secondary context. They help agents decide whether to
wait, clarify, or follow up without turning normal visitor silence into an alarm.
