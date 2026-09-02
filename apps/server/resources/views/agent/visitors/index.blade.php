<x-layouts.app :title="__('visitors.document_title')" :crumb="__('visitors.title')">
    <x-page-header
        :title="__('visitors.title')"
        {{-- The heading makes the same claim the empty state does, above every
             result rather than only the empty ones -- so where presence is on
             the rows themselves contradict it. --}}
        :subtitle="$listsBrowsers
            ? __('visitors.subtitle.browsers')
            : __('visitors.subtitle.contacts')"
    />

    <section class="section" aria-labelledby="visitor-filters-heading">
        <div class="section-header">
            <div>
                <h2 id="visitor-filters-heading">{{ __('visitors.filters.heading') }}</h2>
                <p class="lede">{{ __('visitors.filters.hint') }}</p>
            </div>
        </div>

        <form class="section-form" method="GET" action="{{ route('dashboard.visitors.index') }}">
            <div class="meta-grid">
                <div class="field">
                    <label for="search">{{ __('visitors.filters.search') }}</label>
                    <input id="search" name="search" type="search" value="{{ $search }}" lang="" placeholder="{{ __('visitors.filters.placeholder') }}">
                </div>

                <div class="field">
                    <label for="site">{{ __('visitors.filters.site') }}</label>
                    <select id="site" name="site">
                        <option value="">{{ __('visitors.filters.any_site') }}</option>
                        @foreach ($sites as $option)
                            <option value="{{ $option->id }}" lang="" @selected($siteId === $option->id)>{{ $option->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="presence">{{ __('visitors.filters.last_seen') }}</label>
                    <select id="presence" name="presence">
                        <option value="all" @selected($presence === 'all')>{{ __('visitors.filters.any_time') }}</option>
                        @foreach (\App\Support\Visitors\VisitorPresence::states() as $state)
                            <option value="{{ $state }}" @selected($presence === $state)>
                                {{ __('presence.'.$state) }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="section-actions">
                <button class="button" type="submit">{{ __('visitors.filters.submit') }}</button>
                <a class="button secondary" href="{{ route('dashboard.visitors.index') }}">{{ __('visitors.filters.clear') }}</a>
            </div>
        </form>
    </section>

    <section class="section" aria-labelledby="visitor-list-heading">
        <div class="section-header">
            <h2 id="visitor-list-heading">{{ __('visitors.list.heading') }}</h2>
            <span class="lede">{{ trans_choice('visitors.counts.visitors', $visitors->total(), ['count' => \App\Support\ReaderNumber::count($visitors->total())]) }}</span>
        </div>

        @if ($visitors->isEmpty())
            <div class="notice-copy">
                <p>{{ $listsBrowsers ? __('visitors.empty.browsers') : __('visitors.empty.contacts') }}</p>
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('visitors.list.columns.visitor') }}</th>
                            <th>{{ __('visitors.list.columns.site') }}</th>
                            <th>{{ __('visitors.list.columns.last_seen') }}</th>
                            <th>{{ __('visitors.list.columns.conversations') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($visitors as $visitor)
                            @php($presenceCue = $visitor->presenceCue())
                            <tr>
                                <td>
                                    <a class="text-link" lang="" href="{{ route('dashboard.visitors.show', $visitor) }}">
                                        {{ $visitor->name ?: $visitor->email ?: $visitor->external_id ?: $visitor->anonymous_id }}
                                    </a>
                                </td>
                                <td>
                                    <span class="wf-site-dot" data-site-color="{{ $visitor->site?->resolvedColor()->value }}"></span>
                                    @if ($visitor->site)
                                        <span lang="">{{ $visitor->site->name }}</span>
                                    @else
                                        {{ __('visitors.list.unknown_site') }}
                                    @endif
                                </td>
                                <td>
                                    <span class="readiness-status" data-status="{{ $visitor->presenceState() === 'active' ? 'ready' : 'manual' }}">
                                        {{ __('presence.'.($visitor->presenceState() === 'unknown' ? 'not_reported' : $visitor->presenceState())) }}
                                    </span>
                                    <span class="lede">
                                        {{ $presenceCue['seen_at']
                                            ? __('visitors.presence.seen_at', ['elapsed' => $presenceCue['seen_at']->diffForHumans()])
                                            : __('visitors.presence.'.$presenceCue['key']) }}
                                    </span>
                                </td>
                                <td>{{ trans_choice('visitors.counts.conversations', $visitor->conversations_count, ['count' => \App\Support\ReaderNumber::count($visitor->conversations_count)]) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $visitors->links() }}
        @endif
    </section>
</x-layouts.app>
