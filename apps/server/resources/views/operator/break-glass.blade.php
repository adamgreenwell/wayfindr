<x-layouts.operator :title="__('operator_break_glass.document_title')">
    @php
        // Account names, scope identifiers, reasons and people are customer
        // data rather than catalogue copy. Escape them before inserting them
        // into trusted translation markup and mark their language unknown.
        $unknownLanguage = static fn (mixed $value): string => '<span lang="">'.e((string) $value).'</span>';
        $scopeHtml = static function (array $scope) use ($unknownLanguage): string {
            $html = e($scope['label']);

            if ($scope['value'] !== null && $scope['value'] !== '') {
                $html .= ' '.$unknownLanguage($scope['value']);
            }

            return $html;
        };
        $statusHtml = static fn (array $status): string => $status['language'] === ''
            ? $unknownLanguage($status['label'])
            : e($status['label']);
        $peopleHtml = static function (array $names) use ($unknownLanguage): string {
            return collect($names)->map($unknownLanguage)->implode(', ');
        };
    @endphp

    <h1>{{ __('operator_break_glass.title') }}</h1>
    <p class="lede">{{ __('operator_break_glass.introduction') }}</p>

    @if ($flashStatus)
        <p class="status-message">
            @if ($flashStatus['scope'] !== null)
                {!! __($flashStatus['key'], [
                    'scope' => $scopeHtml($flashStatus['scope']),
                    'until' => e($flashStatus['until'] ?? ''),
                ]) !!}
            @else
                {{ __($flashStatus['key']) }}
            @endif
        </p>
    @endif

    <section class="section" aria-labelledby="break-glass-request-heading">
        <div class="section-header">
            <h2 id="break-glass-request-heading">{{ __('operator_break_glass.request.heading') }}</h2>
            <span class="lede">{{ __('operator_break_glass.request.subtitle') }}</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('operator.break-glass.store') }}">
            @csrf
            <div class="meta-grid">
                <div class="meta-item">
                    <label class="meta-label" for="scope_type">{{ __('operator_break_glass.request.scope.label') }}</label>
                    <select id="scope_type" name="scope_type" required>
                        <option value="conversation" @selected(old('scope_type', 'conversation') === 'conversation')>{{ __('operator_break_glass.request.scope.options.conversation') }}</option>
                        <option value="site" @selected(old('scope_type') === 'site')>{{ __('operator_break_glass.request.scope.options.site') }}</option>
                        <option value="account" @selected(old('scope_type') === 'account')>{{ __('operator_break_glass.request.scope.options.account') }}</option>
                    </select>
                    @error('scope_type')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>
                <div class="meta-item">
                    <label class="meta-label" for="support_code">{{ __('operator_break_glass.request.support_code.label') }}</label>
                    <input id="support_code" name="support_code" type="text" value="{{ old('support_code') }}" placeholder="WF-XXXXXX" lang="">
                    <p class="field-help">{{ __('operator_break_glass.request.support_code.help') }}</p>
                    @error('support_code')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>
                <div class="meta-item">
                    <label class="meta-label" for="site_id">{{ __('operator_break_glass.request.site.label') }}</label>
                    <select id="site_id" name="site_id">
                        <option value="">{{ __('operator_break_glass.request.site.choose') }}</option>
                        @foreach ($sites as $site)
                            <option lang="" value="{{ $site->id }}" @selected((int) old('site_id') === $site->id)>{{ $site->name }} — {{ $site->account?->name }}</option>
                        @endforeach
                    </select>
                    <p class="field-help">{{ __('operator_break_glass.request.site.help') }}</p>
                    @error('site_id')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>
                <div class="meta-item">
                    <label class="meta-label" for="account_id">{{ __('operator_break_glass.request.account.label') }}</label>
                    <select id="account_id" name="account_id">
                        <option value="">{{ __('operator_break_glass.request.account.choose') }}</option>
                        @foreach ($accounts as $account)
                            <option lang="" value="{{ $account->id }}" @selected((int) old('account_id') === $account->id)>{{ $account->name }}</option>
                        @endforeach
                    </select>
                    <p class="field-help">{{ __('operator_break_glass.request.account.help') }}</p>
                    @error('account_id')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>
                <div class="meta-item">
                    <label class="meta-label" for="requested_minutes">{{ __('operator_break_glass.request.duration.label') }}</label>
                    <select id="requested_minutes" name="requested_minutes" required>
                        @foreach ($durationChoices as $minutes => $label)
                            <option value="{{ $minutes }}" @selected((int) old('requested_minutes', $defaultMinutes) === $minutes)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('requested_minutes')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="meta-item">
                <label class="meta-label" for="reason">{{ __('operator_break_glass.request.reason.label') }}</label>
                <textarea id="reason" name="reason" rows="3" required maxlength="1000" placeholder="{{ __('operator_break_glass.request.reason.placeholder') }}" @if (old('reason') !== null) lang="" @endif>{{ old('reason') }}</textarea>
                @error('reason')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <button class="button" type="submit">{{ __('operator_break_glass.request.submit') }}</button>
        </form>
    </section>

    <section class="section" aria-labelledby="break-glass-grants-heading">
        <div class="section-header">
            <h2 id="break-glass-grants-heading">{{ __('operator_break_glass.requests.heading') }}</h2>
            <span class="lede">{{ trans_choice('operator_break_glass.requests.count', $ownGrants->count(), ['count' => \App\Support\ReaderNumber::count($ownGrants->count())]) }}</span>
        </div>

        @if ($ownGrants->isEmpty())
            <div class="notice-copy">
                <p>{{ __('operator_break_glass.requests.empty') }}</p>
            </div>
        @else
            <div class="management-list">
                @foreach ($ownGrants as $item)
                    @php
                        $grant = $item['grant'];
                        $hint = $approvalHints->get($grant->id);
                    @endphp
                    <div class="management-link">
                        <span>
                            <strong>{!! __('operator_break_glass.requests.scope_status', [
                                'scope' => $scopeHtml($item['scope']),
                                'status' => $statusHtml($item['status']),
                            ]) !!}</strong>
                            <span class="lede"><span lang="">{{ $grant->account?->name }}</span> · <span lang="">{{ $grant->reason }}</span></span>
                            <span class="lede">
                                {{ __('operator_break_glass.requests.requested', ['elapsed' => $item['requested_at']]) }}
                                @if ($grant->isActive())
                                    · {{ __('operator_break_glass.requests.expires', ['elapsed' => $item['expires_at']]) }}
                                @elseif ($grant->status === \App\Models\BreakGlassGrant::STATUS_REQUESTED && $hint && ! $hint['can_self_approve'])
                                    · {!! $hint['waiting_on'] !== []
                                        ? __('operator_break_glass.requests.waiting_on', ['people' => $peopleHtml($hint['waiting_on'])])
                                        : e(__('operator_break_glass.requests.waiting_on_fallback')) !!}
                                @endif
                            </span>
                        </span>
                        <span class="compact-actions">
                            @if ($hint && $hint['can_self_approve'])
                                <form class="compact-form" method="POST" action="{{ route('operator.break-glass.approve', $grant) }}">
                                    @csrf
                                    <button class="button secondary" type="submit">{{ __('operator_break_glass.requests.self_approve') }}</button>
                                </form>
                            @endif
                            @if ($grant->isActive())
                                <a class="button" href="{{ route('operator.break-glass.show', $grant) }}">{{ __('operator_break_glass.requests.open') }}</a>
                                <form class="compact-form" method="POST" action="{{ route('operator.break-glass.close', $grant) }}">
                                    @csrf
                                    <button class="button secondary" type="submit">{{ __('operator_break_glass.requests.close') }}</button>
                                </form>
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</x-layouts.operator>
