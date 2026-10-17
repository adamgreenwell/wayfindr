@if (isset($copilotPolicy) && ($copilotPolicy->explicit || $copilotSummary === null || $copilotSummary->preparation_fingerprint !== null))
    @include('agent.conversations.partials.ai-assist-task', ['assistPurpose' => 'conversation_summary'])
@else
@php
    $copilotSummaryState = $copilotSummary?->displayStatus();
    $copilotSummaryIsStale = $copilotSummary?->isStaleComparedTo($latestSummarizableMessageId) ?? false;
    $copilotSummaryRoute = ['supportCode' => $conversation->support_code] + $conversationReturnQuery;
    $assistProvenance = app(\App\Support\Ai\ConversationAssistPresentation::class)->provenance($conversation, $copilotSummaryState === 'ready' ? $copilotSummary : null);
@endphp

<section
    class="section"
    id="copilot-summary"
    aria-labelledby="copilot-summary-heading"
    data-copilot-summary
    data-generation="{{ $copilotSummary?->generation }}" data-policy-revision="{{ $copilotSummary?->policy_revision }}"
    data-state="{{ $copilotSummaryState ?? 'empty' }}"
    data-source-position="{{ $assistProvenance['source_position'] ?? '' }}" data-source-ordinal="{{ $assistProvenance['source_ordinal'] ?? '' }}"
    data-source-message-count="{{ $assistProvenance['source_message_count'] ?? '' }}" data-source-url="{{ $assistProvenance['source_url'] ?? '' }}"
    data-status-url="{{ route('dashboard.conversations.copilot-summary.show', $copilotSummaryRoute) }}"
>
    <div class="section-header">
        <h2 id="copilot-summary-heading">{{ $assistHeading ?? __('ai_assist.rail.sections.summary') }}</h2>
        <span class="readiness-status" data-status="manual">{{ __('conversations.detail.copilot.suggested') }}</span>
    </div>

    @if ($copilotSummaryState === \App\Models\ConversationCopilotSummary::STATUS_PENDING)
        <div class="live-update" data-state="pending">
            <div>
                <strong>{{ __('conversations.detail.copilot.pending') }}</strong>
                <p class="lede">{{ __('conversations.detail.copilot.pending_detail') }}</p>
            </div>
        </div>
    @elseif ($copilotSummaryState === \App\Models\ConversationCopilotSummary::STATUS_READY)
        <div
            class="live-update"
            data-state="stale"
            data-copilot-summary-stale
            @unless ($copilotSummaryIsStale) hidden @endunless
        >
            <div>
                <strong>{{ __('conversations.detail.copilot.stale') }}</strong>
                <p class="lede">{{ __('conversations.detail.copilot.stale_detail') }}</p>
            </div>
        </div>

        <p class="message-body" lang="">{{ $copilotSummary->summary }}</p>
        <p class="lede">
            {{ __('conversations.detail.copilot.generated', ['elapsed' => $copilotSummary->completed_at?->diffForHumans()]) }}
            {{ trans_choice('conversations.detail.copilot.source_count', $copilotSummary->source_message_count, ['count' => \App\Support\ReaderNumber::count($copilotSummary->source_message_count)]) }}
        </p>

        <form class="section-form" method="POST" action="{{ route('dashboard.conversations.copilot-summary.store', $copilotSummaryRoute) }}">
            @csrf
            <button class="button secondary" type="submit">{{ __('conversations.detail.copilot.regenerate') }}</button>
        </form>
    @elseif ($copilotSummaryState === \App\Models\ConversationCopilotSummary::STATUS_FAILED)
        <div class="live-update" data-state="attention">
            <div>
                <strong>{{ __('conversations.detail.copilot.failed') }}</strong>
                <p class="lede">{{ __('conversations.detail.copilot.failed_detail') }}</p>
            </div>
        </div>

        <form class="section-form" method="POST" action="{{ route('dashboard.conversations.copilot-summary.store', $copilotSummaryRoute) }}">
            @csrf
            <button class="button secondary" type="submit">{{ __('conversations.detail.copilot.retry') }}</button>
        </form>
    @else
        <p class="lede">{{ __('conversations.detail.copilot.lede') }}</p>
        <form class="section-form" method="POST" action="{{ route('dashboard.conversations.copilot-summary.store', $copilotSummaryRoute) }}">
            @csrf
            <button class="button secondary" type="submit">{{ __('conversations.detail.copilot.generate') }}</button>
        </form>
    @endif

    @if ($assistProvenance !== null)
        <p class="field-help" data-assist-card-position><a href="#conversation-message-{{ $assistProvenance['source_position'] }}">{{ __('ai_assist.rail.position', ['position' => \App\Support\ReaderNumber::count($assistProvenance['source_ordinal']), 'count' => \App\Support\ReaderNumber::count($assistProvenance['source_message_count'])]) }}</a></p>
    @endif
    <p class="field-help">{{ __('conversations.detail.copilot.privacy') }}</p>
</section>

@endif
