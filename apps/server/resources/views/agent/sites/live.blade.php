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

                // The uncapped total, as the server last reported it. The row
                // count is NOT the same number once a site is past the display
                // limit, and overwriting the total with it on the first
                // heartbeat put the capped figure back on a page that had
                // rendered the real one.
                var presentTotal = countEl ? Number(countEl.textContent) || 0 : 0;

                var displayLimit = Number(config.displayLimit) || 0;

                // Is every visitor the total counts actually on this page?
                //
                // At or below the server's limit the rows ARE the population,
                // so a departure is a real decrease. Above it the rows are a
                // window onto a larger set, and the ones below the cap were
                // never here to leave -- which is why a departure could not
                // lower the total before, and why it must now.
                function boardIsWhole() {
                    return displayLimit > 0 && presentTotal <= displayLimit;
                }

                function refreshCount(total) {
                    var present = rows.querySelectorAll('[data-visitor-id]').length;

                    if (typeof total === 'number') {
                        presentTotal = total;
                    } else if (present > presentTotal) {
                        // A new arrival can push past the last known total.
                        presentTotal = present;
                    } else if (boardIsWhole()) {
                        // Nobody is hidden, so the rows are the count. Without
                        // this the heading went on claiming the old figure
                        // after everybody aged out -- an empty table under
                        // "3 on the site now" until the next resync a minute
                        // later.
                        presentTotal = present;
                    }

                    if (countEl) {
                        countEl.textContent = String(presentTotal);
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

                    // An older event about somebody already on the board is
                    // dropped.
                    //
                    // One visitor with two tabs writes twice, and the database
                    // serialises those -- but the broadcasts happen after each
                    // commit and can reach Reverb in the other order. Replacing
                    // the row unconditionally let the older one win, putting a
                    // stale page address, contact state and sighting time on
                    // the board and leaving them there until the next heartbeat
                    // or the minute's resync.
                    //
                    // Compared against the row's own `data-last-seen`, which is
                    // what the server stamped: the two events are ordered by
                    // the writes they describe, not by their arrival.
                    if (existing && !isNewerThanRow(existing, visitor)) {
                        return;
                    }

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

                        // One more person on the site -- but only where the
                        // absence of a row actually means they are new.
                        //
                        // On a WHOLE board it does: every visitor the total
                        // counts is rendered, so nothing here was already
                        // counted. Incremented before the trim below, and here
                        // rather than in refreshCount(), which infers the total
                        // from the rows and cannot see somebody just evicted.
                        //
                        // On a CAPPED board it does not. Somebody outside the
                        // rendered 200 is already in the total, and their next
                        // heartbeat also arrives with no row to match -- so
                        // counting it inflated the heading every time a capped
                        // visitor reported, and they report every 45 seconds.
                        // The count climbed away from the real population until
                        // the resync pulled it back, once a minute, for ever.
                        //
                        // There is no way to tell the two apart from here. The
                        // server knows, and asking it would mean a count query
                        // on every broadcast -- the hottest path in the feature
                        // -- for a number the resync already carries. So a
                        // capped board leaves its total alone and waits.
                        if (boardIsWhole()) {
                            presentTotal = presentTotal + 1;
                        }

                        // The server renders at most `displayLimit` rows, for
                        // readability and to bound the query. Realtime inserts
                        // were not held to it, so a busy site grew past it
                        // between resyncs -- by every distinct visitor who
                        // reported in during that minute.
                        var rendered = rows.querySelectorAll('[data-visitor-id]');

                        if (displayLimit > 0 && rendered.length > displayLimit) {
                            rendered[rendered.length - 1].remove();
                        }
                    }

                    refreshCount();
                }

                // This site has stopped collecting, and nothing about a visitor
                // may reach the screen again for the life of this page. A
                // resync is what re-establishes a board, and a resync on a
                // revoked site renders the disabled page and clears again.
                var boardCleared = false;

                // Nobody is being watched here any more.
                //
                // Stops the socket as well as emptying the table: a board left
                // subscribed would take the next broadcast -- there can be one
                // in flight -- and put a visitor back on a page that has just
                // said the site is not collecting them.
                function clearBoard() {
                    // Latched, because closing a socket does not cancel a
                    // message the browser has already queued for it. An update
                    // dispatched before the revocation arrives after the rows
                    // are gone, and an unguarded handler puts that visitor --
                    // and possibly their page address -- straight back on the
                    // screen the operator has just cleared.
                    boardCleared = true;

                    rows.replaceChildren();
                    presentTotal = 0;
                    refreshCount(0);

                    pageClosing = true;

                    if (reconnectTimer) {
                        window.clearTimeout(reconnectTimer);
                        reconnectTimer = null;
                    }

                    if (socket) {
                        try {
                            socket.close();
                        } catch (error) {
                            // Closing is best effort; the rows are already gone.
                        }
                    }

                    if (statusEl) {
                        statusEl.textContent = 'Live visitor presence is off for this site.';
                    }
                }

                // Is this event about a later sighting than the row already has?
                //
                // An unparseable or missing timestamp on either side answers
                // yes: the alternative is dropping a real update because a
                // value could not be read, and a board that refuses updates it
                // does not understand goes quietly stale. Equal timestamps also
                // pass -- the same second is not evidence of being older, and
                // re-rendering an identical row costs nothing.
                function isNewerThanRow(row, visitor) {
                    var had = row.dataset.lastSeen ? Date.parse(row.dataset.lastSeen) : NaN;
                    var has = visitor.last_web_seen_at ? Date.parse(visitor.last_web_seen_at) : NaN;

                    if (isNaN(had) || isNaN(has)) {
                        return true;
                    }

                    return has >= had;
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
                        var parsed = new DOMParser().parseFromString(html, 'text/html');
                        var fresh = parsed.querySelector('[data-live-rows]');

                        // An overtaken snapshot is discarded. The subscribe
                        // resync and the minute timer can overlap, and if the
                        // older request lands last it would replace the newer
                        // board with staler markup -- and replay a buffer that
                        // stopped collecting when the second call took over.
                        if (seq !== resyncSequence) {
                            return;
                        }

                        // No rows element is not an empty answer, it is a
                        // REVOCATION. The page drops that element entirely when
                        // presence is off, which is the shape it takes the
                        // moment another operator switches this site off -- so
                        // the response carrying the revocation was the one this
                        // handler used to ignore, and the visitors and their
                        // page addresses stayed on screen until each row aged
                        // out locally, up to fifteen minutes later.
                        if (!fresh) {
                            clearBoard();

                            return;
                        }

                        rows.replaceChildren.apply(rows, Array.prototype.slice.call(fresh.childNodes));

                        // The snapshot carries the uncapped total, which is the
                        // only place the browser can learn it.
                        //
                        // Applied BEFORE the buffer is replayed, not after. A
                        // visitor who arrived between the snapshot being
                        // queried and its response landing is in `pending` and
                        // not in the snapshot -- so replaying them adds a row
                        // and counts them, and then setting the total to the
                        // snapshot's figure took that away again. The table
                        // held somebody the heading did not, until the next
                        // resync a minute later.
                        var freshCount = parsed.querySelector('[data-live-count]');

                        refreshCount(freshCount ? Number(freshCount.textContent) || 0 : undefined);

                        // Re-applied on top, in arrival order, so the newer
                        // state wins over the snapshot that did not have it.
                        pending.forEach(applyVisitor);
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

                // Which socket is in service. A callback from an older one is
                // answering about a connection nobody is using.
                var socketGeneration = 0;

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
                        // Only the socket still in service may react.
                        if (activeSocket.wayfindrGeneration !== socketGeneration) {
                            return;
                        }

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
                    // Everything below is about a visitor, and this site has
                    // stopped collecting them.
                    if (boardCleared) {
                        return;
                    }

                    var event;

                    try {
                        event = JSON.parse(message.data);
                    } catch (error) {
                        return;
                    }

                    // Reverb sends this on a quiet connection and closes the
                    // socket if nothing answers. It is a protocol MESSAGE, not
                    // a WebSocket control frame, so the browser does not reply
                    // on our behalf -- the bundled Pusher client does that, and
                    // this board speaks the protocol itself.
                    //
                    // Without it the board still worked, which is why it was
                    // easy to miss: the socket was retired, the close handler
                    // reconnected, and the subscription resynced. A board with
                    // a gap in it every minute or so, on every install.
                    //
                    // Answered on the socket the frame ARRIVED on, not on
                    // whichever one is current: a ping to a socket being
                    // replaced should not have its pong sent down its
                    // successor.
                    if (event.event === 'pusher:ping') {
                        try {
                            message.target.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
                        } catch (error) {
                            // A socket that cannot be written to is already
                            // gone, and the close handler reconnects.
                        }

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
                    // Each socket carries a generation. An authorization fetch
                    // for a socket that has since been replaced can still
                    // resolve, and its failure path would schedule a reconnect
                    // while the CURRENT socket is healthy -- opening another
                    // one nobody closes, and another after that.
                    var generation = ++socketGeneration;

                    socket = new WebSocket(socketUrl);
                    socket.wayfindrGeneration = generation;

                    socket.addEventListener('message', handleSocketMessage);

                    socket.addEventListener('close', function () {
                        // The same guard the authorization callback takes, and
                        // this is the handler that always runs.
                        //
                        // A failed authorization closes its own socket and
                        // schedules a reconnect in the same breath. `close`
                        // arrives asynchronously, by which time the reconnect
                        // has fired and a healthy socket is in service -- so an
                        // unguarded handler scheduled another one and opened a
                        // third socket beside it. Every failed authorization
                        // left one more, each with its own subscription, and
                        // the board counted every arrival once per live socket.
                        if (generation !== socketGeneration) {
                            return;
                        }

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
