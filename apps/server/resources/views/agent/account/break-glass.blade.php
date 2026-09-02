<x-layouts.app :title="__('operator_access.document_title')" :agent="$agent" :account="$account">
            @php
                // Scope identifiers, people and reasons belong to the account,
                // not to this catalogue. Each replacement is escaped before it
                // enters trusted catalogue markup, then marked as language-
                // neutral so assistive technology does not pronounce it as the
                // surrounding German or Italian by assumption.
                $unknownLanguage = static fn (mixed $value): string => '<span lang="">'.e((string) $value).'</span>';
                $scopeHtml = static function (array $scope) use ($unknownLanguage): string {
                    $html = e($scope['label']);

                    if ($scope['value'] !== null && $scope['value'] !== '') {
                        $html .= ' '.$unknownLanguage($scope['value']);
                    }

                    return $html;
                };
                $personHtml = static fn (?string $name, string $fallback): string => $name !== null
                    ? $unknownLanguage($name)
                    : e(__($fallback));
                $statusHtml = static fn (array $status): string => $status['language'] === ''
                    ? $unknownLanguage($status['label'])
                    : e($status['label']);
            @endphp

            <x-page-header :title="__('operator_access.title')" :subtitle="__('operator_access.subtitle')" :back-href="route('dashboard.account.show')" :back-label="__('operator_access.back')">
                <x-slot:actions>
                    <span class="lede">{{ trans_choice('operator_access.counts.active', $activeGrants->count(), ['count' => \App\Support\ReaderNumber::count($activeGrants->count())]) }}</span>
                </x-slot:actions>
            </x-page-header>

            @if ($flashStatus)
                <p class="status-message">
                    @if ($flashStatus['scope'] !== null && $flashStatus['until'] !== null)
                        {!! __($flashStatus['key'], [
                            'scope' => $scopeHtml($flashStatus['scope']),
                            'until' => e($flashStatus['until']),
                        ]) !!}
                    @else
                        {{ __($flashStatus['key']) }}
                    @endif
                </p>
            @endif

            <section class="section" aria-labelledby="break-glass-pending-heading">
                <div class="section-header">
                    <h2 id="break-glass-pending-heading">{{ __('operator_access.pending.heading') }}</h2>
                    <span class="lede">{{ trans_choice('operator_access.counts.pending', $pendingGrants->count(), ['count' => \App\Support\ReaderNumber::count($pendingGrants->count())]) }}</span>
                </div>

                @if ($pendingGrants->isEmpty())
                    <div class="notice-copy">
                        <p>{{ __('operator_access.pending.empty') }}</p>
                    </div>
                @else
                    <div class="management-list">
                        @foreach ($pendingGrants as $item)
                            <div class="management-link">
                                <span>
                                    <strong>{!! __('operator_access.grant.pending_summary', [
                                        'scope' => $scopeHtml($item['scope']),
                                        'duration' => e(trans_choice('operator_access.grant.minutes', $item['requested_minutes'], [
                                            'count' => \App\Support\ReaderNumber::count($item['requested_minutes']),
                                        ])),
                                    ]) !!}</strong>
                                    <span class="lede">{!! __('operator_access.grant.requester_reason', [
                                        'requester' => $personHtml($item['requester'], 'operator_access.people.former_operator'),
                                        'reason' => $unknownLanguage($item['reason']),
                                    ]) !!}</span>
                                    <span class="lede">{{ __('operator_access.grant.requested', ['elapsed' => $item['requested_at']]) }}</span>
                                </span>
                                <span class="compact-actions">
                                    <form class="compact-form" method="POST" action="{{ route('dashboard.account.break-glass.approve', $item['grant']) }}">
                                        @csrf
                                        <button class="button" type="submit">{{ __('operator_access.pending.approve') }}</button>
                                    </form>
                                    <form class="compact-form" method="POST" action="{{ route('dashboard.account.break-glass.deny', $item['grant']) }}">
                                        @csrf
                                        <button class="button secondary" type="submit">{{ __('operator_access.pending.deny') }}</button>
                                    </form>
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="section" aria-labelledby="break-glass-active-heading">
                <div class="section-header">
                    <h2 id="break-glass-active-heading">{{ __('operator_access.active.heading') }}</h2>
                    <span class="lede">{{ trans_choice('operator_access.counts.open', $activeGrants->count(), ['count' => \App\Support\ReaderNumber::count($activeGrants->count())]) }}</span>
                </div>

                @if ($activeGrants->isEmpty())
                    <div class="notice-copy">
                        <p>{{ __('operator_access.active.empty') }}</p>
                    </div>
                @else
                    <div class="management-list">
                        @foreach ($activeGrants as $item)
                            <div class="management-link">
                                <span>
                                    <strong>{!! __('operator_access.grant.active_summary', [
                                        'scope' => $scopeHtml($item['scope']),
                                        'elapsed' => e($item['expires_at']),
                                    ]) !!}</strong>
                                    <span class="lede">{!! __('operator_access.grant.requester_reason', [
                                        'requester' => $personHtml($item['requester'], 'operator_access.people.former_operator'),
                                        'reason' => $unknownLanguage($item['reason']),
                                    ]) !!}</span>
                                    <span class="lede">
                                        @if ($item['self_approved'])
                                            {{ $item['approved_at'] !== null
                                                ? __('operator_access.grant.self_approved_at', ['elapsed' => $item['approved_at']])
                                                : __('operator_access.grant.self_approved') }}
                                        @else
                                            {!! __($item['approved_at'] !== null
                                                ? 'operator_access.grant.approved_by_at'
                                                : 'operator_access.grant.approved_by', [
                                                'approver' => $personHtml($item['approver'], 'operator_access.people.former_admin'),
                                                'elapsed' => e($item['approved_at'] ?? ''),
                                            ]) !!}
                                        @endif
                                    </span>
                                </span>
                                <span class="compact-actions">
                                    <form class="compact-form" method="POST" action="{{ route('dashboard.account.break-glass.close', $item['grant']) }}">
                                        @csrf
                                        <button class="button secondary" type="submit">{{ __('operator_access.active.revoke') }}</button>
                                    </form>
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="section" aria-labelledby="break-glass-history-heading">
                <div class="section-header">
                    <h2 id="break-glass-history-heading">{{ __('operator_access.history.heading') }}</h2>
                    <span class="lede">{{ trans_choice('operator_access.counts.shown', $pastGrants->count(), ['count' => \App\Support\ReaderNumber::count($pastGrants->count())]) }}</span>
                </div>

                @if ($pastGrants->isEmpty())
                    <div class="notice-copy">
                        <p>{{ __('operator_access.history.empty') }}</p>
                    </div>
                @else
                    <div class="management-list">
                        @foreach ($pastGrants as $item)
                            <div class="management-link">
                                <span>
                                    <strong>{!! __('operator_access.grant.past_summary', [
                                        'scope' => $scopeHtml($item['scope']),
                                        'status' => $statusHtml($item['status']),
                                    ]) !!}</strong>
                                    <span class="lede">{!! __('operator_access.grant.requester_reason', [
                                        'requester' => $personHtml($item['requester'], 'operator_access.people.former_operator'),
                                        'reason' => $unknownLanguage($item['reason']),
                                    ]) !!}</span>
                                    <span class="lede">{{ $item['self_approved']
                                        ? __('operator_access.grant.requested_self_approved', ['elapsed' => $item['requested_at']])
                                        : __('operator_access.grant.requested', ['elapsed' => $item['requested_at']]) }}</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
</x-layouts.app>
