<x-layouts.app :title="__('account_audit.document_title')" :agent="$agent" :account="$account">
            <x-page-header :title="__('account_audit.title')" :subtitle="__('account_audit.subtitle')" :back-href="route('dashboard.account.show')" :back-label="__('account_audit.back')">
                <x-slot:actions>
                    <span class="lede">{{ trans_choice('account_audit.shown', $auditEvents->count(), ['count' => \App\Support\ReaderNumber::count($auditEvents->count())]) }}</span>
                    <a class="button secondary" href="{{ route('dashboard.account.audit.export', $auditQuery) }}">{{ __('account_audit.export_csv') }}</a>
                </x-slot:actions>
            </x-page-header>

            <section class="section" aria-labelledby="audit-responsibility-heading">
                <div class="section-header">
                    <h2 id="audit-responsibility-heading">{{ __('account_audit.boundary.heading') }}</h2>
                    <span class="lede">{{ __('account_audit.boundary.private') }}</span>
                </div>
                <div class="notice-copy">
                    <p>{{ __('account_audit.boundary.fields') }}</p>
                    <p>{{ __('account_audit.boundary.scope') }}</p>
                </div>
            </section>

            {{-- The house filter bar (ADR 0014), as on alerts, conversations and
                 tickets: it sits on the page above the list it filters rather
                 than in a card of its own. It used to be built from the
                 metadata DISPLAY grid, which needed a fourth cell for the
                 buttons and filled it with a label that labelled nothing. --}}
            <form class="wf-filters" method="GET" action="{{ route('dashboard.account.audit.index') }}" aria-label="{{ __('account_audit.filters.region') }}">
                <div class="wf-filter">
                    <label for="audit_action">{{ __('account_audit.filters.action') }}</label>
                    <select id="audit_action" name="audit_action">
                        <option value="">{{ __('account_audit.filters.any_action') }}</option>
                        @foreach ($auditActions as $actionValue => $actionOption)
                            <option value="{{ $actionValue }}" @if ($actionOption['language'] !== null) lang="{{ $actionOption['language'] }}" @endif @selected($auditAction === $actionValue)>{{ $actionOption['label'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="wf-filter">
                    <label for="audit_site">{{ __('account_audit.filters.site') }}</label>
                    <select id="audit_site" name="audit_site">
                        <option value="">{{ __('account_audit.filters.any_site') }}</option>
                        @foreach ($auditSites as $site)
                            <option value="{{ $site->id }}" lang="" @selected($auditSiteId === $site->id)>{{ $site->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="wf-filter wf-filter-search">
                    <label for="audit_search">{{ __('account_audit.filters.search') }}</label>
                    <input id="audit_search" name="audit_search" type="search" value="{{ $auditSearch }}" @if ($auditSearch !== '') lang="" @endif placeholder="{{ __('account_audit.filters.search_placeholder') }}">
                </div>

                <div class="wf-filter-actions">
                    <button class="button" type="submit">{{ __('account_audit.filters.apply') }}</button>
                    @if ($auditAction !== '' || $auditSearch !== '' || $auditSiteId !== null)
                        <a class="button secondary" href="{{ route('dashboard.account.audit.index') }}">{{ __('account_audit.filters.clear') }}</a>
                    @endif
                    <span class="wf-filter-help">{{ $auditAction !== '' || $auditSearch !== '' || $auditSiteId !== null ? __('account_audit.filters.filtered') : __('account_audit.filters.all') }}</span>
                </div>
            </form>

            <section class="section" aria-labelledby="audit-events-heading">
                <div class="section-header">
                    <h2 id="audit-events-heading">{{ __('account_audit.events.heading') }}</h2>
                    <span class="lede">{{ trans_choice('account_audit.shown', $auditEvents->count(), ['count' => \App\Support\ReaderNumber::count($auditEvents->count())]) }}</span>
                </div>

                @if ($auditEvents->isEmpty())
                    <p class="empty">{{ __('account_audit.events.empty') }}</p>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('account_audit.events.when') }}</th>
                                    <th scope="col">{{ __('account_audit.events.action') }}</th>
                                    <th scope="col">{{ __('account_audit.events.actor') }}</th>
                                    <th scope="col">{{ __('account_audit.events.subject') }}</th>
                                    <th scope="col">{{ __('account_audit.events.site') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($auditEvents as $event)
                                    <tr>
                                        <td>{{ $event['occurred_at'] }}</td>
                                        <td>
                                            <strong>{{ $event['label'] }}</strong>
                                            <span class="lede" lang="">{{ $event['action'] }}</span>
                                        </td>
                                        <td>
                                            @if ($event['actor']['prefix'])
                                                {{ $event['actor']['prefix'] }}
                                            @endif
                                            @if ($event['actor']['value'])
                                                <span lang="">{{ $event['actor']['value'] }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($event['subject']['prefix'])
                                                {{ $event['subject']['prefix'] }}
                                            @endif
                                            @if ($event['subject']['value'])
                                                <span lang="">{{ $event['subject']['value'] }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($event['site']['prefix'])
                                                {{ $event['site']['prefix'] }}
                                            @endif
                                            @if ($event['site']['value'])
                                                <span lang="">{{ $event['site']['value'] }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
</x-layouts.app>
