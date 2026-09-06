<x-layouts.app :title="__('proactive_messages.title')" :agent="$agent" :account="$account">
    <x-page-header :back-href="route('dashboard.account.automation-rules.index')" :back-label="__('proactive_messages.back')">
        <x-slot:titleContent>{{ __('proactive_messages.title_for') }} <span lang="">{{ $site->name }}</span></x-slot:titleContent>
        <x-slot:subtitleContent>{{ __('proactive_messages.subtitle') }}</x-slot:subtitleContent>
        <x-slot:actions>
            <a class="button" href="{{ route('dashboard.sites.proactive-messages.create', $site) }}">{{ __('proactive_messages.create.action') }}</a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <p class="status-message">{{ __(session('status')) }}</p>
    @endif

    <section class="section" aria-labelledby="proactive-safety-heading">
        <div class="section-header">
            <h2 id="proactive-safety-heading">{{ __('proactive_messages.safety.heading') }}</h2>
            <span class="readiness-status" data-status="{{ $presenceEnabled ? 'ready' : 'attention' }}">
                {{ $presenceEnabled ? __('proactive_messages.safety.presence_on') : __('proactive_messages.safety.presence_off') }}
            </span>
        </div>
        <div class="notice-copy">
            <p>{{ __('proactive_messages.safety.inert') }}</p>
            <p>{{ __('proactive_messages.safety.browser_matching') }}</p>
            <p>{{ __('proactive_messages.safety.hours') }}</p>
        </div>
    </section>

    <section class="section" aria-labelledby="proactive-rules-heading">
        <div class="section-header">
            <h2 id="proactive-rules-heading">{{ __('proactive_messages.list.heading') }}</h2>
            <span class="lede">{{ trans_choice('proactive_messages.list.count', $rules->count(), ['count' => \App\Support\ReaderNumber::count($rules->count())]) }}</span>
        </div>

        @if ($rules->isEmpty())
            <div class="empty empty-state">
                <strong>{{ __('proactive_messages.list.empty_heading') }}</strong>
                <p>{{ __('proactive_messages.list.empty_body') }}</p>
                <a class="button" href="{{ route('dashboard.sites.proactive-messages.create', $site) }}">{{ __('proactive_messages.create.first') }}</a>
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('proactive_messages.list.columns.rule') }}</th>
                            <th scope="col">{{ __('proactive_messages.list.columns.when') }}</th>
                            <th scope="col">{{ __('proactive_messages.list.columns.caps') }}</th>
                            <th scope="col">{{ __('proactive_messages.list.columns.status') }}</th>
                            <th scope="col">{{ __('proactive_messages.list.columns.action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rules as $rule)
                            <tr>
                                <td>
                                    <strong lang="">{{ $rule->name }}</strong>
                                    <span class="table-note" lang="">{{ $rule->message }}</span>
                                </td>
                                <td>
                                    {{ __('proactive_messages.list.delay', ['seconds' => \App\Support\ReaderNumber::count($rule->delay_seconds)]) }}
                                    <span class="table-note">{{ trans_choice('proactive_messages.list.visits', $rule->minimum_visit_count, ['count' => \App\Support\ReaderNumber::count($rule->minimum_visit_count)]) }}</span>
                                    @if ($rule->url_contains)
                                        <span class="table-note">{{ __('proactive_messages.list.page_contains') }} <span lang="">{{ $rule->url_contains }}</span></span>
                                    @endif
                                    @if ($rule->referrer_contains)
                                        <span class="table-note">{{ __('proactive_messages.list.referrer_contains') }} <span lang="">{{ $rule->referrer_contains }}</span></span>
                                    @endif
                                </td>
                                <td>
                                    {{ __('proactive_messages.list.frequency', ['hours' => \App\Support\ReaderNumber::count(intdiv($rule->frequency_cap_minutes, 60))]) }}
                                    <span class="table-note">{{ __('proactive_messages.list.dismissal', ['days' => \App\Support\ReaderNumber::count(intdiv($rule->dismissal_snooze_minutes, 1440))]) }}</span>
                                </td>
                                <td>
                                    <span class="readiness-status" data-status="{{ $rule->is_enabled ? 'ready' : 'manual' }}">
                                        {{ $rule->is_enabled ? __('proactive_messages.status.enabled') : __('proactive_messages.status.draft') }}
                                    </span>
                                    @if ($rule->requires_available_agent)
                                        <span class="table-note">{{ __('proactive_messages.status.agent_required') }}</span>
                                    @endif
                                </td>
                                <td><a class="button secondary" href="{{ route('dashboard.sites.proactive-messages.edit', [$site, $rule]) }}">{{ __('proactive_messages.list.edit') }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-layouts.app>
