<x-layouts.app title="Visitors" crumb="Visitors">
    <x-page-header
        title="Visitors"
        {{-- The heading makes the same claim the empty state does, above every
             result rather than only the empty ones -- so where presence is on
             the rows themselves contradict it. --}}
        :subtitle="$listsBrowsers
            ? 'Everyone this desk has seen, whether or not they got in touch, most recently seen first.'
            : 'Everyone this desk has heard from, most recently seen first.'"
    />

    <section class="section" aria-labelledby="visitor-filters-heading">
        <div class="section-header">
            <div>
                <h2 id="visitor-filters-heading">Search</h2>
                <p class="lede">By name, email, or the identifier your site gave them.</p>
            </div>
        </div>

        <form class="section-form" method="GET" action="{{ route('dashboard.visitors.index') }}">
            <div class="meta-grid">
                <div class="field">
                    <label for="search">Search</label>
                    <input id="search" name="search" type="search" value="{{ $search }}" placeholder="Name, email, or ID">
                </div>

                <div class="field">
                    <label for="site">Site</label>
                    <select id="site" name="site">
                        <option value="">Any site</option>
                        @foreach ($sites as $option)
                            <option value="{{ $option->id }}" @selected($siteId === $option->id)>{{ $option->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="presence">Last seen</label>
                    <select id="presence" name="presence">
                        <option value="all" @selected($presence === 'all')>Any time</option>
                        @foreach (\App\Support\Visitors\VisitorPresence::states() as $state)
                            <option value="{{ $state }}" @selected($presence === $state)>
                                {{ \App\Support\Visitors\VisitorPresence::label($state) }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="section-actions">
                <button class="button" type="submit">Search visitors</button>
                <a class="button secondary" href="{{ route('dashboard.visitors.index') }}">Clear</a>
            </div>
        </form>
    </section>

    <section class="section" aria-labelledby="visitor-list-heading">
        <div class="section-header">
            <h2 id="visitor-list-heading">Visitors</h2>
            <span class="lede">{{ $visitors->total() }} {{ Str::plural('visitor', $visitors->total()) }}</span>
        </div>

        @if ($visitors->isEmpty())
            <div class="notice-copy">
                <p>
                    @if ($listsBrowsers)
                        No visitors match this search. On the sites shown here Wayfindr records somebody when they
                        load a page, so this also lists people who were only browsing.
                    @else
                        No visitors match this search. Wayfindr records somebody when they open the chat, not when
                        they load a page, so this lists people who reached out.
                    @endif
                </p>
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Visitor</th>
                            <th>Site</th>
                            <th>Last seen</th>
                            <th>Conversations</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($visitors as $visitor)
                            <tr>
                                <td>
                                    <a class="text-link" href="{{ route('dashboard.visitors.show', $visitor) }}">
                                        {{ $visitor->name ?: $visitor->email ?: $visitor->external_id ?: $visitor->anonymous_id }}
                                    </a>
                                </td>
                                <td>
                                    <span class="wf-site-dot" data-site-color="{{ $visitor->site?->resolvedColor()->value }}"></span>
                                    {{ $visitor->site?->name ?? 'Unknown site' }}
                                </td>
                                <td>
                                    <span class="readiness-status" data-status="{{ $visitor->presenceState() === 'active' ? 'ready' : 'manual' }}">
                                        {{ $visitor->presenceLabel() }}
                                    </span>
                                    <span class="lede">{{ $visitor->presenceDetail() }}</span>
                                </td>
                                <td>{{ $visitor->conversations_count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $visitors->links() }}
        @endif
    </section>
</x-layouts.app>
