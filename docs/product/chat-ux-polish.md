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

Future typing indicators and read receipts should follow the same rule: show
only what Wayfindr can prove, expire stale signals quickly, and degrade cleanly
to manual refresh when realtime delivery is unavailable.
