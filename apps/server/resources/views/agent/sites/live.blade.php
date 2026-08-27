<x-layouts.app title="Live visitors" :agent="$agent" :account="$account">
    <x-page-header
        :title="'Live visitors: '.$site->name"
        subtitle="Who is on this site right now, including people who have not got in touch." />

    <section class="section" aria-labelledby="live-board-heading">
        <div class="section-header">
            <h2 id="live-board-heading">On the site now</h2>
            <span class="lede" data-live-count>{{ $visitors->count() }}</span>
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
                                        <a href="{{ route('dashboard.visitors.show', $visitor['id']) }}">{{ $visitor['name'] ?? $visitor['email'] ?? 'Visitor '.$visitor['id'] }}</a>
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
                    var name = document.createElement('span');

                    name.textContent = visitor.made_contact
                        ? (visitor.name || visitor.email || ('Visitor ' + visitor.id))
                        : 'Not in touch yet';
                    who.appendChild(name);
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
                        existing.replaceWith(fresh);
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
                    }).catch(function () {
                        if (statusEl) {
                            statusEl.textContent = 'Live updates stopped. This list is correct as of when it last updated.';
                        }
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

                    if (event.event === config.eventName) {
                        var payload;

                        // The envelope's `data` is itself a JSON string, so it
                        // is decoded twice.
                        try {
                            payload = JSON.parse(event.data);
                        } catch (error) {
                            return;
                        }

                        applyVisitor(payload && payload.visitor);
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
                connect();
            })();
        </script>
    @endif
</x-layouts.app>
