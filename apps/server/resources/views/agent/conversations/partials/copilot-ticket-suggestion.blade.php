@php
    $copilotTicketSuggestionState = $copilotTicketSuggestion?->displayStatus();
    $copilotTicketSuggestionIsStale = $copilotTicketSuggestion?->isStaleComparedTo($latestConversationMessageId) ?? false;
    $copilotTicketSuggestionRoute = ['supportCode' => $conversation->support_code] + $conversationReturnQuery;
@endphp

<div
    class="reply-template-preview"
    data-copilot-ticket-suggestion
    data-state="{{ $copilotTicketSuggestionState ?? 'empty' }}"
    data-status-url="{{ route('dashboard.conversations.copilot-ticket-suggestion.show', $copilotTicketSuggestionRoute) }}"
>
    <div class="reply-copilot-heading">
        <strong>{{ __('conversations.detail.ticket_copilot.heading') }}</strong>
        <span class="readiness-status" data-status="manual">{{ __('conversations.detail.ticket_copilot.suggested') }}</span>
    </div>

    @if ($copilotTicketSuggestionState === \App\Models\ConversationCopilotTicketSuggestion::STATUS_PENDING)
        <p role="status"><strong>{{ __('conversations.detail.ticket_copilot.pending') }}</strong></p>
        <p class="lede">{{ __('conversations.detail.ticket_copilot.pending_detail') }}</p>
    @elseif ($copilotTicketSuggestionState === \App\Models\ConversationCopilotTicketSuggestion::STATUS_READY)
        <div
            class="live-update"
            data-state="stale"
            data-copilot-ticket-suggestion-stale
            @unless ($copilotTicketSuggestionIsStale) hidden @endunless
        >
            <div>
                <strong>{{ __('conversations.detail.ticket_copilot.stale') }}</strong>
                <p class="lede">{{ __('conversations.detail.ticket_copilot.stale_detail') }}</p>
            </div>
        </div>

        <dl class="meta-grid">
            <div>
                <dt>{{ __('conversations.detail.ticket_copilot.title') }}</dt>
                <dd data-copilot-ticket-title lang="">{{ $copilotTicketSuggestion->title }}</dd>
            </div>
            <div>
                <dt>{{ __('conversations.detail.ticket_copilot.priority') }}</dt>
                <dd data-copilot-ticket-priority data-value="{{ $copilotTicketSuggestion->priority }}">{{ __('tickets.priorities.'.$copilotTicketSuggestion->priority) }}</dd>
            </div>
            <div>
                <dt>{{ __('conversations.detail.ticket_copilot.labels') }}</dt>
                <dd>
                    @forelse ($suggestedTicketLabels as $label)
                        <span data-copilot-ticket-label-id="{{ $label->id }}"><x-ticket-label-chip :label="$label" ticket-status="open" /></span>
                    @empty
                        <span>{{ __('conversations.detail.ticket_copilot.no_labels') }}</span>
                    @endforelse
                </dd>
            </div>
        </dl>
        <p class="lede">
            {{ __('conversations.detail.ticket_copilot.generated', ['elapsed' => $copilotTicketSuggestion->completed_at?->diffForHumans()]) }}
            {{ trans_choice('conversations.detail.ticket_copilot.source_count', $copilotTicketSuggestion->source_message_count, ['count' => \App\Support\ReaderNumber::count($copilotTicketSuggestion->source_message_count)]) }}
        </p>

        <div class="section-actions">
            <button
                class="button"
                type="button"
                data-copilot-ticket-suggestion-use
                @disabled($copilotTicketSuggestionIsStale)
            >{{ __('conversations.detail.ticket_copilot.use') }}</button>

            <form method="POST" action="{{ route('dashboard.conversations.copilot-ticket-suggestion.store', $copilotTicketSuggestionRoute) }}">
                @csrf
                <button class="button secondary" type="submit">{{ __('conversations.detail.ticket_copilot.regenerate') }}</button>
            </form>
        </div>
    @elseif ($copilotTicketSuggestionState === \App\Models\ConversationCopilotTicketSuggestion::STATUS_FAILED)
        <p><strong>{{ __('conversations.detail.ticket_copilot.failed') }}</strong></p>
        <p class="lede">{{ __('conversations.detail.ticket_copilot.failed_detail') }}</p>

        <form method="POST" action="{{ route('dashboard.conversations.copilot-ticket-suggestion.store', $copilotTicketSuggestionRoute) }}">
            @csrf
            <button class="button secondary" type="submit">{{ __('conversations.detail.ticket_copilot.retry') }}</button>
        </form>
    @else
        <p class="lede">{{ __('conversations.detail.ticket_copilot.lede') }}</p>
        <form method="POST" action="{{ route('dashboard.conversations.copilot-ticket-suggestion.store', $copilotTicketSuggestionRoute) }}">
            @csrf
            <button class="button secondary" type="submit">{{ __('conversations.detail.ticket_copilot.generate') }}</button>
        </form>
    @endif

    <p class="field-help">{{ __('conversations.detail.ticket_copilot.privacy') }}</p>
    <p class="sr-only" role="status" aria-live="polite" data-copilot-ticket-suggestion-status></p>
</div>
