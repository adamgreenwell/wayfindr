<x-layouts.app :title="__('visitors.document_title')" :agent="$agent" :account="$account" :crumb="__('visitors.title')">
    <x-page-header
        :title="__('visitors.title')"
        {{-- The heading makes the same claim the empty state does, above every
             result rather than only the empty ones -- so where presence is on
             the rows themselves contradict it. --}}
        :subtitle="$listsBrowsers
            ? __('visitors.subtitle.browsers')
            : __('visitors.subtitle.contacts')"
    >
        @if ($canManageContacts && ! $attributeFilterInvalid)
            <x-slot:actions>
                <a class="button secondary" href="{{ route('dashboard.visitors.export', $exportQuery) }}">{{ __('visitors.export.csv') }}</a>
            </x-slot:actions>
        @endif
    </x-page-header>

    @if ($canManageContacts)
        <section class="section" aria-labelledby="visitor-export-boundary-heading">
            <div class="section-header">
                <h2 id="visitor-export-boundary-heading">{{ __('visitors.export.boundary_heading') }}</h2>
                <span class="lede">{{ __('visitors.export.boundary_lede') }}</span>
            </div>
            <div class="notice-copy">
                <p>{{ __('visitors.export.boundary_fields') }}</p>
                <p>{{ __('visitors.export.boundary_scope', ['count' => 500]) }}</p>
            </div>
        </section>
    @endif

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
                    <input id="search" name="search" type="search" value="{{ $search }}" @if ($search !== '') lang="" @endif placeholder="{{ __('visitors.filters.placeholder') }}">
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

                <div class="field">
                    <label for="attribute">{{ __('visitor_attributes.filters.attribute') }}</label>
                    <select id="attribute" name="attribute">
                        <option value="">{{ __('visitor_attributes.filters.any_attribute') }}</option>
                        @foreach ($attributeDefinitions as $definition)
                            <option value="{{ $definition->key }}" lang="" @selected($attributeKey === $definition->key)>{{ $definition->label }}</option>
                        @endforeach
                    </select>
                </div>

                @php($selectedAttribute = $attributeDefinitions->firstWhere('key', $attributeKey))
                <div class="field">
                    <label for="attribute-value">{{ __('visitor_attributes.filters.value') }}</label>
                    @if ($selectedAttribute?->type === \App\Enums\VisitorAttributeType::Boolean)
                        <select id="attribute-value" name="attribute_value" aria-describedby="attribute-filter-help @if ($attributeFilterInvalid) attribute-filter-error @endif" @if ($attributeFilterInvalid) aria-invalid="true" @endif>
                            <option value="">—</option>
                            <option value="true" @selected($attributeValue === 'true')>{{ __('visitor_attributes.profile.yes') }}</option>
                            <option value="false" @selected($attributeValue === 'false')>{{ __('visitor_attributes.profile.no') }}</option>
                        </select>
                    @else
                        <input
                            id="attribute-value"
                            name="attribute_value"
                            type="text"
                            @if ($selectedAttribute?->type === \App\Enums\VisitorAttributeType::Number) inputmode="decimal" @endif
                            value="{{ $attributeValue }}"
                            maxlength="160"
                            placeholder="{{ __('visitor_attributes.filters.value_placeholder') }}"
                            aria-describedby="attribute-filter-help @if ($attributeFilterInvalid) attribute-filter-error @endif"
                            @if ($attributeFilterInvalid) aria-invalid="true" @endif
                            lang=""
                        >
                    @endif
                    <p id="attribute-filter-help" class="field-help">{{ __('visitor_attributes.filters.help') }}</p>
                    @if ($attributeFilterInvalid)
                        <p id="attribute-filter-error" class="field-error">{{ __('visitor_attributes.filters.invalid') }}</p>
                    @endif
                </div>
            </div>

            <div class="section-actions">
                <button class="button" type="submit">{{ __('visitors.filters.submit') }}</button>
                <a class="button secondary" href="{{ route('dashboard.visitors.index') }}">{{ __('visitors.filters.clear') }}</a>
                @if ($canManageContacts)
                    <a class="button secondary" href="{{ route('dashboard.account.visitor-attributes.index') }}">{{ __('visitor_attributes.filters.manage') }}</a>
                @endif
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
                            @if ($canViewConversations)
                                <th>{{ __('visitors.list.columns.conversations') }}</th>
                            @endif
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
                                @if ($canViewConversations)
                                    <td>{{ trans_choice('visitors.counts.conversations', $visitor->conversations_count, ['count' => \App\Support\ReaderNumber::count($visitor->conversations_count)]) }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $visitors->links() }}
        @endif
    </section>
</x-layouts.app>
