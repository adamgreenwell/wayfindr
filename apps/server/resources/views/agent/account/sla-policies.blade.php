<x-layouts.app :title="__('sla.document_title')" :agent="$agent" :account="$account">
    <x-page-header :title="__('sla.title')" :subtitle="__('sla.subtitle')" :back-href="route('dashboard.account.show')" :back-label="__('sla.back')" />

    @if (session('status'))
        <p class="status-message">{{ __(session('status')) }}</p>
    @endif

    <section class="section" aria-labelledby="sla-policy-heading">
        <div class="section-header">
            <div>
                <h2 id="sla-policy-heading">{{ __('sla.policy.heading') }}</h2>
                <p class="lede">{{ __('sla.policy.lede') }}</p>
            </div>
            <span class="readiness-status" data-status="{{ $policies->isEmpty() ? 'manual' : 'ready' }}">
                {{ $policies->isEmpty() ? __('sla.policy.not_configured') : __('sla.policy.configured') }}
            </span>
        </div>

        <div class="notice-copy notice-copy-bordered">
            <p>{{ __('sla.policy.clock') }}</p>
            <p>{{ __('sla.policy.warning', ['percent' => \App\Models\SlaClock::WARNING_PERCENT]) }}</p>
            <p>{{ __('sla.policy.blank') }}</p>
        </div>

        <form class="section-form" method="POST" action="{{ route('dashboard.account.sla-policies.update') }}">
            @csrf
            @method('PUT')

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('sla.policy.columns.priority') }}</th>
                            <th scope="col">{{ __('sla.policy.columns.first_response') }}</th>
                            <th scope="col">{{ __('sla.policy.columns.resolution') }}</th>
                            <th scope="col">{{ __('sla.policy.columns.guidance') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($priorities as $priority => $guidance)
                            @php $policy = $policies->get($priority); @endphp
                            <tr>
                                <td><strong>{{ __('tickets.priorities.'.$priority) }}</strong></td>
                                <td>
                                    <label class="sr-only" for="sla-{{ $priority }}-response">{{ __('sla.policy.response_for', ['priority' => __('tickets.priorities.'.$priority)]) }}</label>
                                    <input
                                        id="sla-{{ $priority }}-response"
                                        name="policies[{{ $priority }}][first_response_minutes]"
                                        type="number"
                                        min="5"
                                        max="43200"
                                        step="1"
                                        value="{{ old('policies.'.$priority.'.first_response_minutes', $policy?->first_response_minutes) }}"
                                        inputmode="numeric"
                                    >
                                    <span class="field-help">{{ __('sla.policy.minutes') }}</span>
                                </td>
                                <td>
                                    <label class="sr-only" for="sla-{{ $priority }}-resolution">{{ __('sla.policy.resolution_for', ['priority' => __('tickets.priorities.'.$priority)]) }}</label>
                                    <input
                                        id="sla-{{ $priority }}-resolution"
                                        name="policies[{{ $priority }}][resolution_minutes]"
                                        type="number"
                                        min="5"
                                        max="43200"
                                        step="1"
                                        value="{{ old('policies.'.$priority.'.resolution_minutes', $policy?->resolution_minutes) }}"
                                        inputmode="numeric"
                                    >
                                    <span class="field-help">{{ __('sla.policy.minutes') }}</span>
                                </td>
                                <td>
                                    <span>{{ __('sla.policy.guidance.'.$priority.'.description') }}</span>
                                    <span class="field-help">{{ __('sla.policy.guidance.'.$priority.'.action') }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @error('policies')<p class="field-error">{{ $message }}</p>@enderror
            @foreach ($priorities as $priority => $guidance)
                @error('policies.'.$priority.'.first_response_minutes')<p class="field-error">{{ $message }}</p>@enderror
                @error('policies.'.$priority.'.resolution_minutes')<p class="field-error">{{ $message }}</p>@enderror
            @endforeach

            <button class="button" type="submit">{{ __('sla.policy.save') }}</button>
        </form>
    </section>
</x-layouts.app>
