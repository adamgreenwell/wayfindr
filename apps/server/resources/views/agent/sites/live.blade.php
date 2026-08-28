<x-layouts.app title="Live visitors" :agent="$agent" :account="$account">
    <x-page-header
        :title="'Live visitors: '.$site->name"
        subtitle="Who is on this site right now, including people who have not got in touch." />

    <section class="section" aria-labelledby="live-board-heading">
        <div class="section-header">
            <h2 id="live-board-heading">On the site now</h2>
            <span class="lede" data-live-count>{{ $presentCount }}</span>
        </div>

        @if (! $reporting->enabled)
            {{-- Not an empty board. An operator looking at nothing deserves to
                 know whether nobody is here or nothing is being recorded. --}}
            <div class="notice-copy">
                <p>This site does not record visitors who have not made contact, so this board stays empty by design.</p>

                @if ($canUpdatePrivacy)
                    <p><a href="{{ route('dashboard.sites.show', $site) }}#presence-settings-heading">Turn on live visitor presence</a> to see people browsing before they get in touch.</p>
                @else
                    <p>Account owners and admins decide whether this site watches visitors who have not made contact.</p>
                @endif
            </div>
        @else
            <p class="field-help">
                Somebody appears here while their browser reports in, and drops off {{ $presentMinutes }} minutes after it stops. Visitors are told in the widget and can decline.
            </p>

            <div class="table-scroll">
                <table class="table" data-live-board>
                    <thead>
                        <tr>
                            <th scope="col">Visitor</th>
                            <th scope="col">Page</th>
                            <th scope="col">On site for</th>
                            <th scope="col">Presence</th>
                        </tr>
                    </thead>
                    <tbody data-live-rows>
                        @forelse ($visitors as $visitor)
                            <tr data-visitor-id="{{ $visitor['id'] }}" data-last-seen="{{ $visitor['last_web_seen_at'] }}">
                                <td>
                                    @if ($visitor['made_contact'])
                                        <a href="{{ $visitor['profile_url'] }}">{{ $visitor['name'] ?? $visitor['email'] ?? 'Visitor '.$visitor['id'] }}</a>
                                        <span class="lede">{{ $visitor['conversations_count'] }} {{ Str::plural('conversation', $visitor['conversations_count']) }}</span>
                                    @else
                                        {{-- No link: there is nothing on the other side of it yet, and
                                             a name we were never told is not one to invent. --}}
                                        <span>Not in touch yet</span>
                                    @endif
                                </td>
                                <td data-live-page>
                                    @if ($visitor['page_url'])
                                        <code>{{ $visitor['page_url'] }}</code>
                                    @else
                                        <span class="empty">Not reported</span>
                                    @endif
                                </td>
                                <td data-live-duration data-started="{{ $visitor['visit_started_at'] }}">
                                    {{ $visitor['visit_started_at'] ? \Carbon\CarbonImmutable::parse($visitor['visit_started_at'])->diffForHumans(null, true) : '—' }}
                                </td>
                                <td>
                                    <span class="readiness-status" data-live-state data-status="{{ $visitor['state'] === 'active' ? 'ready' : 'manual' }}">
                                        {{ \App\Support\Visitors\VisitorPresence::label($visitor['state']) }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr data-live-empty>
                                <td colspan="4"><span class="empty">Nobody is on the site right now.</span></td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <p class="field-help" data-live-status role="status" aria-live="polite">
                @if ($realtime)
                    Updating live.
                @else
                    This install does not run realtime updates, so this list is correct as of when the page loaded.
                @endif
            </p>
        @endif
    </section>

    @if ($realtime && $reporting->enabled)
        <script>
            (function () {
                var config = @json($realtime);
                var rows = document.querySelector('[data-live-rows]');
                var countEl = document.querySelector('[data-live-count]');
                var statusEl = document.querySelector('[data-live-status]');

                if (!config || !rows || !window.WebSocket) {
                    if (statusEl) {
                        statusEl.textContent = 'Live updates are unavailable, so this list is correct as of when the page loaded.';
                    }

                    return;
                }

                var labels = @json($presenceLabels);

                // State travels, words are local. The payload is broadcast to
                // every agent watching and they do not all read the same
                // language, so the socket carries `active` and this picks the
                // sentence -- the same rule the conversation presence payload
                // already follows.
                function labelFor(state) {
                    return labels[state] || labels.not_reported;
                }

                function emptyRow() {
                    return rows.querySelector('[data-live-empty]');
                }

                function refreshCount() {
                    var present = rows.querySelectorAll('[data-visitor-id]').length;

                    if (countEl) {
                        countEl.textContent = String(present);
                    }

                    var empty = emptyRow();

                    if (empty) {
                        empty.hidden = present > 0;
                    }
                }

                function durationFrom(startedAt) {
                    if (!startedAt) {
                        return '\u2014';
                    }

                    var started = Date.parse(startedAt);

                    if (isNaN(started)) {
                        return '\u2014';
                    }

                    var seconds = Math.max(0, Math.round((Date.now() - started) / 1000));

                    if (seconds < 60) {
                        return seconds + 's';
                    }

                    var minutes = Math.floor(seconds / 60);

                    if (minutes < 60) {
                        return minutes + 'm';
                    }

                    return Math.floor(minutes / 60) + 'h ' + (minutes % 60) + 'm';
                }

                function textCell(value, fallback) {
                    var cell = document.createElement('td');

                    if (value) {
                        var code = document.createElement('code');

                        // textContent, never innerHTML. The page address is
                        // reported by a public endpoint, so it is attacker
                        // controlled, and this is an agent's browser.
                        code.textContent = value;
                        cell.appendChild(code);
                    } else {
                        var empty = document.createElement('span');

                        empty.className = 'empty';
                        empty.textContent = fallback;
                        cell.appendChild(empty);
                    }

                    return cell;
                }

                function buildRow(visitor) {
                    var row = document.createElement('tr');

                    row.dataset.visitorId = String(visitor.id);
                    row.dataset.lastSeen = visitor.last_web_seen_at || '';

                    var who = document.createElement('td');

                    if (visitor.made_contact) {
                        // The same link and count the server rendered. Rebuilding
                        // a plainer row meant the profile link and the
                        // conversation context an agent could see at page load
                        // vanished on the first heartbeat -- within 45 seconds,
                        // and looking like the page had simply lost them.
                        var link = document.createElement('a');

                        link.textContent = visitor.name || visitor.email || ('Visitor ' + visitor.id);

                        if (visitor.profile_url) {
                            link.href = visitor.profile_url;
                            who.appendChild(link);
                        } else {
                            who.appendChild(document.createTextNode(link.textContent));
                        }

                        var count = document.createElement('span');
                        var total = Number(visitor.conversations_count) || 0;

                        count.className = 'lede';
                        count.textContent = total + (total === 1 ? ' conversation' : ' conversations');
                        who.appendChild(count);
                    } else {
                        var stranger = document.createElement('span');

                        stranger.textContent = 'Not in touch yet';
                        who.appendChild(stranger);
                    }

                    row.appendChild(who);

                    var page = textCell(visitor.page_url, 'Not reported');

                    page.setAttribute('data-live-page', '');
                    row.appendChild(page);

                    var duration = document.createElement('td');

                    duration.setAttribute('data-live-duration', '');
                    duration.dataset.started = visitor.visit_started_at || '';
                    duration.textContent = durationFrom(visitor.visit_started_at);
                    row.appendChild(duration);

                    var state = document.createElement('td');
                    var badge = document.createElement('span');

                    badge.className = 'readiness-status';
                    badge.setAttribute('data-live-state', '');
                    badge.dataset.status = visitor.state === 'active' ? 'ready' : 'manual';
                    badge.textContent = labelFor(visitor.state);
                    state.appendChild(badge);
                    row.appendChild(state);

                    return row;
                }

                function applyVisitor(visitor) {
                    if (!visitor || typeof visitor.id === 'undefined') {
                        return;
                    }

                    var existing = rows.querySelector('[data-visitor-id="' + CSS.escape(String(visitor.id)) + '"]');
                    var fresh = buildRow(visitor);

                    if (existing) {
                        existing.remove();

                        // Moved, not replaced in place. The server orders by
                        // the latest sighting, so a visitor already on the
                        // board when it loaded would otherwise keep their
                        // original position for ever -- the ordering frozen at
                        // page load while the timestamps underneath it change.
                        rows.insertBefore(fresh, rows.firstChild);
                    } else {
                        // Newest first, matching the server's ordering. A row
                        // appended to the bottom would put the person who just
                        // arrived where an agent stops looking.
                        rows.insertBefore(fresh, rows.firstChild);
                    }

                    refreshCount();
                }

                // Somebody who stops reporting is gone, and nothing tells us so
                // -- absence is not an event. The board ages its own rows out
                // on the same cutoff the query used, so a tab left open does
                // not slowly fill with people who left hours ago.
                function dropDeparted() {
                    var cutoff = Date.now() - (config.presentMinutes * 60 * 1000);

                    rows.querySelectorAll('[data-visitor-id]').forEach(function (row) {
                        var seen = row.dataset.lastSeen ? Date.parse(row.dataset.lastSeen) : NaN;

                        if (!isNaN(seen) && seen < cutoff) {
                            row.remove();

                            return;
                        }

                        var duration = row.querySelector('[data-live-duration]');

                        if (duration) {
                            duration.textContent = durationFrom(duration.dataset.started);
                        }
                    });

                    refreshCount();
                }

                /**
                 * Re-read the board from the server.
                 *
                 * Fetches the page an agent would reload and takes only its
                 * rows, so a resynced row and a broadcast row cannot disagree
                 * about what a row looks like -- they are the same markup from
                 * the same template.
                 */
                function resyncBoard() {
                    // Events that land while the snapshot is being fetched are
                    // NEWER than it. Replacing the rows wholesale would then
                    // overwrite them with older state -- and that is the likely
                    // ordering, not the unlucky one, since a broadcast beats
                    // the page render it raced.
                    var pending = [];
                    var seq = ++resyncSequence;

                    resyncBuffer = pending;

                    fetch(window.location.href, {
                        credentials: 'same-origin',
                        headers: { Accept: 'text/html' },
                    }).then(function (response) {
                        if (!response.ok) {
                            throw new Error('resync');
                        }

                        return response.text();
                    }).then(function (html) {
                        var fresh = new DOMParser()
                            .parseFromString(html, 'text/html')
                            .querySelector('[data-live-rows]');

                        if (!fresh) {
                            return;
                        }

                        // An overtaken snapshot is discarded. The subscribe
                        // resync and the minute timer can overlap, and if the
                        // older request lands last it would replace the newer
                        // board with staler markup -- and replay a buffer that
                        // stopped collecting when the second call took over.
                        if (seq !== resyncSequence) {
                            return;
                        }

                        rows.replaceChildren.apply(rows, Array.prototype.slice.call(fresh.childNodes));

                        // Re-applied on top, in arrival order, so the newer
                        // state wins over the snapshot that did not have it.
                        pending.forEach(applyVisitor);
                        refreshCount();
                    }).catch(function () {
                        // The rows already on the page stay. A failed resync
                        // leaves the board possibly missing somebody, which is
                        // exactly what it was a moment ago.
                    }).then(function () {
                        if (resyncBuffer === pending) {
                            resyncBuffer = null;
                        }
                    });
                }

                // Holds events that arrive while a snapshot is being fetched.
                // Null when no resync is in flight, which is most of the time.
                var resyncBuffer = null;

                // Orders overlapping snapshots, so only the newest is applied.
                var resyncSequence = 0;

                var socketScheme = config.scheme === 'https' ? 'wss' : 'ws';
                var socketUrl = socketScheme + '://' + config.host + ':' + config.port + '/app/'
                    + encodeURIComponent(config.appKey) + '?protocol=7&client=wayfindr-board&version=0.0.0&flash=false';
                var socket = null;
                var reconnectDelay = 1000;
                var reconnectTimer = null;
                var pageClosing = false;

                function scheduleReconnect() {
                    if (pageClosing || reconnectTimer) {
                        return;
                    }

                    reconnectTimer = window.setTimeout(function () {
                        reconnectTimer = null;
                        connect();
                    }, reconnectDelay);

                    reconnectDelay = Math.min(reconnectDelay * 2, 15000);
                }

                function authorize(activeSocket, socketId) {
                    var token = document.querySelector('meta[name="csrf-token"]');

                    return fetch(config.authEndpoint, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-CSRF-TOKEN': token ? token.getAttribute('content') : '',
                            Accept: 'application/json',
                        },
                        body: new URLSearchParams({
                            socket_id: socketId,
                            channel_name: config.channelName,
                        }),
                    }).then(function (response) {
                        if (!response.ok) {
                            throw new Error('auth');
                        }

                        return response.json();
                    }).then(function (data) {
                        activeSocket.send(JSON.stringify({
                            event: 'pusher:subscribe',
                            data: { auth: data.auth, channel: config.channelName },
                        }));

                        reconnectDelay = 1000;

                        if (statusEl) {
                            statusEl.textContent = 'Updating live.';
                        }

                        // NOT resynced here. Authorization succeeding only
                        // means the subscribe frame has been sent; Reverb has
                        // not yet confirmed it, so a snapshot taken now still
                        // has a window on the far side of it. The resync waits
                        // for `pusher_internal:subscription_succeeded`.
                    }).catch(function () {
                        if (statusEl) {
                            statusEl.textContent = 'Reconnecting to live updates.';
                        }

                        // A failed authorization leaves the socket HEALTHY and
                        // unsubscribed, so no close event ever fires and the
                        // reconnect that only the close handler schedules never
                        // runs. The board would then sit there, connected to
                        // nothing, for the rest of the session -- looking
                        // exactly like a quiet afternoon.
                        try {
                            activeSocket.close();
                        } catch (error) {
                            // Closing is best effort; the reconnect is what matters.
                        }

                        scheduleReconnect();
                    });
                }

                function handleSocketMessage(message) {
                    var event;

                    try {
                        event = JSON.parse(message.data);
                    } catch (error) {
                        return;
                    }

                    if (event.event === 'pusher:connection_established') {
                        var established;

                        try {
                            established = JSON.parse(event.data);
                        } catch (error) {
                            return;
                        }

                        authorize(socket, established.socket_id);

                        return;
                    }

                    // The subscription is live from HERE, not from the moment
                    // authorization returned. Resyncing on every confirmation,
                    // first one included: Reverb does not replay, so whatever
                    // was broadcast between the server rendering this page and
                    // this frame is gone -- and after a reconnect that gap is
                    // however long the socket was down.
                    if (event.event === 'pusher_internal:subscription_succeeded') {
                        resyncBoard();

                        return;
                    }

                    if (event.event === config.eventName) {
                        var payload;

                        // The envelope's `data` is itself a JSON string, so it
                        // is decoded twice.
                        try {
                            payload = JSON.parse(event.data);
                        } catch (error) {
                            return;
                        }

                        var visitor = payload && payload.visitor;

                        // Buffered as well as applied. A resync in flight is
                        // about to overwrite this row with older markup, and
                        // the buffer is what puts it back.
                        if (resyncBuffer && visitor) {
                            resyncBuffer.push(visitor);
                        }

                        applyVisitor(visitor);
                    }
                }

                function connect() {
                    socket = new WebSocket(socketUrl);

                    socket.addEventListener('message', handleSocketMessage);

                    socket.addEventListener('close', function () {
                        if (statusEl && !pageClosing) {
                            statusEl.textContent = 'Reconnecting to live updates.';
                        }

                        scheduleReconnect();
                    });

                    socket.addEventListener('error', function () {
                        // A close event follows and schedules the reconnect.
                    });
                }

                window.addEventListener('beforeunload', function () {
                    pageClosing = true;

                    if (socket) {
                        try {
                            socket.close();
                        } catch (error) {
                            // Ignore teardown errors.
                        }
                    }
                });

                window.setInterval(dropDeparted, 15000);

                // A periodic resync, because some changes have no event.
                // Another operator revoking presence deletes the rows this
                // board is showing -- with their page addresses -- and nothing
                // is broadcast for a deletion, so an open board would keep
                // displaying them until each aged out on its own. A minute
                // bounds that without making the socket pointless.
                window.setInterval(resyncBoard, 60000);
                connect();
            })();
        </script>
    @endif
</x-layouts.app>
