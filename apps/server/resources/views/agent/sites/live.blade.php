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
                            <tr data-visitor-id="{{ $visitor['id'] }}">
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
</x-layouts.app>
