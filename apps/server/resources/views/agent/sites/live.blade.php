<x-layouts.app :title="__('sites_live.document_title')" :agent="$agent" :account="$account">
    {{-- The heading MIXES our words with the account's: the site's name is
         theirs, in whatever language they named it, and the rest is ours. The
         catalogue keeps the word order, and the one fragment that is not ours
         carries `lang=""` -- HTML's "unknown".

         A SLOT rather than an attribute, because what goes in is an element and
         an element cannot live in an attribute value. --}}
    <x-page-header :subtitle="__('sites_live.subtitle')">
        <x-slot:title-content>
            {!! __('sites_live.heading', ['site' => '<span lang="">'.e($site->name).'</span>']) !!}
        </x-slot:title-content>
    </x-page-header>

    <section class="section" aria-labelledby="live-board-heading">
        <div class="section-header">
            <h2 id="live-board-heading">{{ __('sites_live.board.heading') }}</h2>
            {{-- The server's clock, so the board can measure against the same
                 one that stamped the rows. Refreshed by every resync. --}}
            {{-- Two values, deliberately. The TEXT is grouped for the reader -- `1.000`
                 for a German agent -- and the script must never parse it back:
                 `Number('1.000')` is 1 and `Number('1,000')` is NaN, so reading
                 the rendered text collapsed the total on the next socket event.
                 `data-live-total` is the raw integer, for the script alone. --}}
            <span class="lede" data-live-count data-live-total="{{ $presentCount }}" data-server-now="{{ now()->toJSON() }}">{{ \App\Support\ReaderNumber::count($presentCount) }}</span>
        </div>

        @if (! $reporting->enabled)
            {{-- Not an empty board. An operator looking at nothing deserves to
                 know whether nobody is here or nothing is being recorded. --}}
            <div class="notice-copy">
                <p>{{ __('sites_live.disabled.body') }}</p>

                @if ($canUpdatePrivacy)
                    {{-- One sentence with the link as a parameter, so a language
                         that puts the clause first can still do so. --}}
                    <p>{!! __('sites_live.disabled.turn_on', [
                        'link' => '<a href="'.e(route('dashboard.sites.show', $site).'#presence-settings-heading').'">'.e(__('sites_live.disabled.turn_on_link')).'</a>',
                    ]) !!}</p>
                @else
                    <p>{{ __('sites_live.disabled.ask_admin') }}</p>
                @endif
            </div>
        @else
            <p class="field-help">
                {{ trans_choice('sites_live.board.note', $presentMinutes, ['count' => \App\Support\ReaderNumber::count($presentMinutes)]) }}
            </p>

            <div class="table-scroll">
                <table class="table" data-live-board>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('sites_live.board.column_visitor') }}</th>
                            <th scope="col">{{ __('sites_live.board.column_page') }}</th>
                            <th scope="col">{{ __('sites_live.board.column_duration') }}</th>
                            <th scope="col">{{ __('sites_live.board.column_presence') }}</th>
                        </tr>
                    </thead>
                    <tbody data-live-rows>
                        @forelse ($visitors as $visitor)
                            <tr data-visitor-id="{{ $visitor['id'] }}" data-last-seen="{{ $visitor['last_web_seen_at'] }}">
                                <td>
                                    @if ($visitor['made_contact'])
                                        {{-- A name or address the VISITOR gave, so it is
                                             their words and not the agent's language. The
                                             `Visitor 41` fallback is ours and is not marked. --}}
                                        <a href="{{ $visitor['profile_url'] }}">@if ($visitor['name'] ?? $visitor['email'])<span lang="">{{ $visitor['name'] ?? $visitor['email'] }}</span>@else{{ __('sites_live.board.unnamed', ['id' => $visitor['id']]) }}@endif</a>
                                        @if ($canViewConversationCounts)
                                            <span class="lede" data-live-conversation-count data-count="{{ $visitor['conversations_count'] }}">{{ trans_choice('sites_live.board.conversations', $visitor['conversations_count'], ['count' => \App\Support\ReaderNumber::count($visitor['conversations_count'])]) }}</span>
                                        @endif
                                    @else
                                        {{-- No link: there is nothing on the other side of it yet, and
                                             a name we were never told is not one to invent. --}}
                                        <span>{{ __('sites_live.board.stranger') }}</span>
                                    @endif
                                </td>
                                <td data-live-page>
                                    @if ($visitor['page_url'])
                                        <code lang="">{{ $visitor['page_url'] }}</code>
                                    @else
                                        <span class="empty">{{ __('sites_live.board.no_page') }}</span>
                                    @endif
                                </td>
                                <td data-live-duration data-started="{{ $visitor['visit_started_at'] }}">
                                    {{ $visitor['visit_started_at'] ? \Carbon\CarbonImmutable::parse($visitor['visit_started_at'])->diffForHumans(null, true) : __('sites_live.duration.unknown') }}
                                </td>
                                <td>
                                    <span class="readiness-status" data-live-state data-status="{{ $visitor['state'] === 'active' ? 'ready' : 'manual' }}">
                                        {{-- Translated at the CALL SITE. The support class
                                             stays English because it can be reached where no
                                             request has scoped a locale. --}}
                                        {{ __('presence.'.$visitor['state']) }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr data-live-empty>
                                <td colspan="4"><span class="empty">{{ __('sites_live.board.empty') }}</span></td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <p class="field-help" data-live-status role="status" aria-live="polite">
                @if ($realtime)
                    {{ __('sites_live.status.live') }}
                @else
                    {{ __('sites_live.status.no_realtime') }}
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
                        statusEl.textContent = @json(__('sites_live.status.unavailable'));
                    }

                    return;
                }

                var labels = @json($presenceLabels);
                var showConversationCounts = Boolean(config.showConversationCounts);

                // Copy for the script, from the same catalogue the markup above
                // used. `@json(__(...))` is the pattern the reply composer
                // already uses -- Blade renders the script, so the words are
                // chosen per request in the language the agent asked for, and
                // there is no second mechanism to keep in step.
                @php
                    // Assigned in a @php block rather than written inline in
                    // `@json([...])`: Blade's directive parser reads to the
                    // first `)` and a multi-line array with nested `__()` calls
                    // ends it early, which compiles to a PHP parse error rather
                    // than to anything you can see in the browser.
                    $liveCopy = [
                    'unavailable' => __('sites_live.status.unavailable'),
                    'presence_off' => __('sites_live.status.presence_off'),
                    'reconnecting' => __('sites_live.status.reconnecting'),
                    'live' => __('sites_live.status.live'),
                    'no_access' => __('sites_live.status.no_access'),
                    'stranger' => __('sites_live.board.stranger'),
                    'no_page' => __('sites_live.board.no_page'),
                    'unnamed' => __('sites_live.board.unnamed', ['id' => ':id']),
                    'unknown_duration' => __('sites_live.duration.unknown'),
                    'seconds' => __('sites_live.duration.seconds', ['count' => ':count']),
                    'minutes' => __('sites_live.duration.minutes', ['count' => ':count']),
                    'hours' => __('sites_live.duration.hours', ['count' => ':count', 'minutes' => ':minutes']),
                    // Both plural branches, selected below by the count the
                    // socket reports. Rendered here rather than in the script
                    // because the SELECTOR is Laravel's and the browser has no
                    // access to it.
                    'conversations_one' => trans_choice('sites_live.board.conversations', 1, ['count' => ':count']),
                    'conversations_many' => trans_choice('sites_live.board.conversations', 2, ['count' => ':count']),
                    ];
                @endphp

                var copy = @json($liveCopy);

                // The reader's locale, for grouping numbers the way the server
                // already groups the ones it rendered.
                var readerLocale = document.documentElement.lang || 'en';

                function readerNumber(value) {
                    try {
                        return Number(value).toLocaleString(readerLocale);
                    } catch (error) {
                        return String(value);
                    }
                }

                // Two branches only, which is right for every language this
                // dashboard ships and NOT right in general: Polish and Arabic
                // have more. A language pack that needs a third form has to
                // extend this, and the comment is here so it is found.
                function conversationsLabel(total) {
                    var form = total === 1 ? copy.conversations_one : copy.conversations_many;

                    return form.replace(':count', readerNumber(total));
                }

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
                // From the data attribute, never from the rendered text. The
                // text is grouped for the reader and is not a number any more.
                var presentTotal = countEl ? Number(countEl.getAttribute('data-live-total')) || 0 : 0;

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
                        countEl.setAttribute('data-live-total', String(presentTotal));
                        countEl.textContent = readerNumber(presentTotal);
                    }

                    var empty = emptyRow();

                    if (empty) {
                        empty.hidden = present > 0;
                    }
                }

                // The agent's clock is not the one that stamped these rows.
                //
                // Every timestamp on this board is server-stamped, and two of
                // the things it does with them -- how long somebody has been
                // here, and whether they have gone -- compared them against
                // the WORKSTATION's clock. A machine running more than
                // `presentMinutes` ahead therefore read every fresh heartbeat
                // as already expired: the resync restored the rows and the
                // fifteen-second sweep removed them again, so a busy site
                // showed an empty board for most of every minute.
                //
                // Skew that large is not exotic -- a laptop resumed from
                // suspend, a VM with no working time sync -- and the board
                // gave no hint of what was wrong.
                var clockOffset = 0;

                function adoptServerClock(el) {
                    var stamped = el && el.dataset ? el.dataset.serverNow : null;
                    var at = stamped ? Date.parse(stamped) : NaN;

                    if (!isNaN(at)) {
                        clockOffset = at - Date.now();
                    }
                }

                function serverNow() {
                    return Date.now() + clockOffset;
                }

                function durationFrom(startedAt) {
                    if (!startedAt) {
                        return copy.unknown_duration;
                    }

                    var started = Date.parse(startedAt);

                    if (isNaN(started)) {
                        return copy.unknown_duration;
                    }

                    var seconds = Math.max(0, Math.round((serverNow() - started) / 1000));

                    if (seconds < 60) {
                        return copy.seconds.replace(':count', readerNumber(seconds));
                    }

                    var minutes = Math.floor(seconds / 60);

                    if (minutes < 60) {
                        return copy.minutes.replace(':count', readerNumber(minutes));
                    }

                    return copy.hours
                        .replace(':count', readerNumber(Math.floor(minutes / 60)))
                        .replace(':minutes', readerNumber(minutes % 60));
                }

                function textCell(value, fallback) {
                    var cell = document.createElement('td');

                    if (value) {
                        var code = document.createElement('code');

                        // textContent, never innerHTML. The page address is
                        // reported by a public endpoint, so it is attacker
                        // controlled, and this is an agent's browser.
                        // The page address is the visitor's, not words in the
                        // agent's language.
                        code.setAttribute('lang', '');
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

                        // A name or address the VISITOR gave is their words; the
                        // numbered fallback is ours, so only the first is marked.
                        if (visitor.name || visitor.email) {
                            link.setAttribute('lang', '');
                            link.textContent = visitor.name || visitor.email;
                        } else {
                            link.removeAttribute('lang');
                            link.textContent = copy.unnamed.replace(':id', visitor.id);
                        }

                        if (visitor.profile_url) {
                            link.href = visitor.profile_url;
                            who.appendChild(link);
                        } else {
                            who.appendChild(document.createTextNode(link.textContent));
                        }

                        if (showConversationCounts && Object.prototype.hasOwnProperty.call(visitor, 'conversations_count')) {
                            var count = document.createElement('span');
                            var total = Number(visitor.conversations_count) || 0;

                            count.className = 'lede';
                            count.setAttribute('data-live-conversation-count', '');
                            count.dataset.count = String(total);
                            count.textContent = conversationsLabel(total);
                            who.appendChild(count);
                        }
                    } else {
                        var stranger = document.createElement('span');

                        stranger.textContent = copy.stranger;
                        who.appendChild(stranger);
                    }

                    row.appendChild(who);

                    var page = textCell(visitor.page_url, copy.no_page);

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

                    // The shared socket payload cannot contain a conversation
                    // count because ticket-only subscribers use the same
                    // channel. Preserve an authorized reader's private
                    // snapshot value while replacing an existing row; a new
                    // arrival receives its count at the next page resync.
                    if (showConversationCounts
                        && existing
                        && !Object.prototype.hasOwnProperty.call(visitor, 'conversations_count')) {
                        var existingCount = existing.querySelector('[data-live-conversation-count]');

                        if (existingCount) {
                            visitor = Object.assign({}, visitor, {
                                conversations_count: Number(existingCount.dataset.count) || 0
                            });
                        }
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
                function clearBoard(reason) {
                    stopKeepalive();

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
                        statusEl.textContent = reason || copy.presence_off;
                    }
                }

                // The revocation has been undone: come back to life.
                //
                // Clearing latches on purpose -- a message queued for the
                // closed socket must not repopulate a board the operator just
                // emptied -- and the latch outlived its reason. An operator
                // switching presence off and back on left every open board a
                // zombie: rows redrawn once a minute by the snapshot, every
                // socket message ignored, the socket closed and barred from
                // reconnecting, and the status line still saying presence was
                // off for a site that was collecting again.
                //
                // A snapshot that HAS a rows element is that signal read the
                // other way round, since its absence is what cleared the board.
                function restoreBoard() {
                    if (!boardCleared) {
                        return;
                    }

                    boardCleared = false;
                    pageClosing = false;
                    reconnectDelay = 1000;

                    if (statusEl) {
                        statusEl.textContent = copy.reconnecting;
                    }

                    // The old socket was closed on the way in here, so this
                    // opens a new one; its generation retires anything still
                    // answering for the old.
                    connect();
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
                    var cutoff = serverNow() - (config.presentMinutes * 60 * 1000);

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
                        // A terminal answer, not a bad moment.
                        //
                        // Channel authorisation is checked when the socket
                        // SUBSCRIBES and never again, so an operator removing
                        // this agent from the site does nothing to a board they
                        // already have open -- the subscription stays live and
                        // goes on delivering visitor identities and page
                        // addresses for as long as the tab is. The resync is
                        // the only thing that finds out, and treating its 404
                        // as transient meant it found out and carried on.
                        //
                        // 403 and 404 both mean the answer is no. Anything else
                        // is the server having a bad moment, where keeping the
                        // rows is right: the board is missing somebody, which
                        // is what it was a moment ago.
                        if (response.status === 403 || response.status === 404) {
                            // The newest answer acts. An overtaken one asks
                            // again rather than doing either obvious thing,
                            // because both obvious things are wrong.
                            //
                            // DROPPING it is the hole this branch exists to
                            // close: if the request that overtook it never
                            // answers, nothing acts on the denial and the
                            // subscription goes on delivering visitor
                            // identities to somebody removed from the site,
                            // for as long as their tab is open. Channel
                            // authorisation is checked at subscribe and never
                            // again, so this is the only thing that finds out.
                            //
                            // OBEYING it blindly is the opposite mistake.
                            // Access can be removed and restored while two
                            // resyncs are in flight, and then a stale 404
                            // shuts down a board that is now entitled to be
                            // open.
                            //
                            // So it re-asks, once at a time: the delay lets the
                            // request that overtook it land first, and the flag
                            // keeps two denials from becoming two re-asks.
                            if (seq === resyncSequence) {
                                clearBoard(copy.no_access);
                            } else if (!denialRecheckPending) {
                                denialRecheckPending = true;

                                window.setTimeout(function () {
                                    denialRecheckPending = false;
                                    resyncBoard();
                                }, DENIAL_RECHECK_MS);
                            }

                            return null;
                        }

                        if (!response.ok) {
                            throw new Error('resync');
                        }

                        return response.text();
                    }).then(function (html) {
                        if (html === null) {
                            return;
                        }

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

                        // Rows are present, so this site is collecting. If this
                        // board was cleared by an earlier revocation, that
                        // revocation has been undone.
                        restoreBoard();

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

                        // Re-read on every snapshot, so a clock that drifts
                        // during a long session is corrected rather than fixed
                        // at whatever it was on page load.
                        adoptServerClock(freshCount);

                        // The ATTRIBUTE, for the same reason as at start-up:
                        // the fetched snapshot's count is grouped for its
                        // reader too, so parsing its text turns `1.200` into 1
                        // on every resync rather than only on page load.
                        refreshCount(freshCount ? Number(freshCount.getAttribute('data-live-total')) || 0 : undefined);

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

                // At most one outstanding re-ask for an overtaken denial, so a
                // burst of them cannot become a burst of requests.
                var denialRecheckPending = false;

                // Long enough for the request that overtook a denial to land
                // first, so the re-ask usually finds a settled answer rather
                // than racing the same pair again.
                var DENIAL_RECHECK_MS = 2000;

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


                // Our own keepalive.
                //
                // The pusher protocol expects the CLIENT to speak on an
                // otherwise silent connection: the server declares an
                // `activity_timeout` when the connection is established, and a
                // client that says nothing for that long is disconnected. This
                // board answered the server's ping and never sent one of its own.
                //
                // It matters more than the protocol makes it sound, because
                // anything between the browser and Reverb is also counting. On
                // a default nginx the websocket location inherits
                // `proxy_read_timeout 60s`, so an idle socket is torn down at
                // sixty seconds with no close frame -- measured on our own
                // staging deploy, where a silent socket died at exactly 60s
                // (code 1006) while one sending a ping every 25s stayed up.
                // Reverb's own ping is also on a sixty-second interval, so it
                // never got the chance to arrive first.
                var keepaliveTimer = null;

                function stopKeepalive() {
                    if (keepaliveTimer) {
                        window.clearInterval(keepaliveTimer);
                        keepaliveTimer = null;
                    }
                }

                function startKeepalive(activeSocket, activityTimeoutSeconds) {
                    stopKeepalive();

                    // Half the window the server gave us, so a single lost frame
                    // is not a disconnection, and never longer than 25 seconds --
                    // proxies in front of us have their own idea of idle and do
                    // not tell us what it is.
                    var declared = Number(activityTimeoutSeconds);
                    var every = Math.max(5, Math.min(25, (declared > 0 ? declared : 30) / 2));

                    keepaliveTimer = window.setInterval(function () {
                        if (activeSocket.readyState !== 1) {
                            stopKeepalive();

                            return;
                        }

                        try {
                            activeSocket.send(JSON.stringify({ event: 'pusher:ping', data: {} }));
                        } catch (error) {
                            stopKeepalive();
                        }
                    }, every * 1000);
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
                            statusEl.textContent = copy.live;
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
                            statusEl.textContent = copy.reconnecting;
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

                        startKeepalive(message.target, established.activity_timeout);
                        authorize(message.target, established.socket_id);

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

                        // AFTER the guard. `keepaliveTimer` is one variable for
                        // the page, so a close arriving from a socket that has
                        // already been replaced would otherwise stop the
                        // REPLACEMENT's keepalive -- and a failed authorization
                        // closes its own socket and schedules a reconnect in the
                        // same breath, so the successor is routinely alive
                        // before its predecessor's close event lands.
                        stopKeepalive();

                        if (statusEl && !pageClosing) {
                            statusEl.textContent = copy.reconnecting;
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

                adoptServerClock(countEl);

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
