@php
    $copilotReplyDraftState = $copilotReplyDraft?->displayStatus();
    $copilotReplyDraftIsStale = $copilotReplyDraft?->isStaleComparedTo($latestSummarizableMessageId) ?? false;
    $copilotReplyDraftRoute = ['supportCode' => $conversation->support_code] + $conversationReturnQuery;
@endphp

<div
    class="reply-template-preview"
    data-copilot-reply-draft
    data-state="{{ $copilotReplyDraftState ?? 'empty' }}"
    data-status-url="{{ route('dashboard.conversations.copilot-reply-draft.show', $copilotReplyDraftRoute) }}"
>
    <div class="reply-copilot-heading">
        <strong>{{ __('conversations.detail.reply_copilot.heading') }}</strong>
        <span class="readiness-status" data-status="manual">{{ __('conversations.detail.reply_copilot.suggested') }}</span>
    </div>

    @if ($copilotReplyDraftState === \App\Models\ConversationCopilotReplyDraft::STATUS_PENDING)
        <p role="status"><strong>{{ __('conversations.detail.reply_copilot.pending') }}</strong></p>
        <p class="lede">{{ __('conversations.detail.reply_copilot.pending_detail') }}</p>
    @elseif ($copilotReplyDraftState === \App\Models\ConversationCopilotReplyDraft::STATUS_READY)
        <div
            class="live-update"
            data-state="stale"
            data-copilot-reply-draft-stale
            @unless ($copilotReplyDraftIsStale) hidden @endunless
        >
            <div>
                <strong>{{ __('conversations.detail.reply_copilot.stale') }}</strong>
                <p class="lede">{{ __('conversations.detail.reply_copilot.stale_detail') }}</p>
            </div>
        </div>

        <p data-copilot-reply-draft-content lang="">{{ $copilotReplyDraft->draft }}</p>
        <p class="lede">
            {{ __('conversations.detail.reply_copilot.generated', ['elapsed' => $copilotReplyDraft->completed_at?->diffForHumans()]) }}
            {{ trans_choice('conversations.detail.reply_copilot.source_count', $copilotReplyDraft->source_message_count, ['count' => \App\Support\ReaderNumber::count($copilotReplyDraft->source_message_count)]) }}
        </p>

        <div class="section-actions">
            <button
                class="button"
                type="button"
                data-copilot-reply-draft-use
                @disabled($copilotReplyDraftIsStale)
            >{{ __('conversations.detail.reply_copilot.use') }}</button>

            <form method="POST" action="{{ route('dashboard.conversations.copilot-reply-draft.store', $copilotReplyDraftRoute) }}">
                @csrf
                <button class="button secondary" type="submit">{{ __('conversations.detail.reply_copilot.regenerate') }}</button>
            </form>
        </div>
    @elseif ($copilotReplyDraftState === \App\Models\ConversationCopilotReplyDraft::STATUS_FAILED)
        <p><strong>{{ __('conversations.detail.reply_copilot.failed') }}</strong></p>
        <p class="lede">{{ __('conversations.detail.reply_copilot.failed_detail') }}</p>

        <form method="POST" action="{{ route('dashboard.conversations.copilot-reply-draft.store', $copilotReplyDraftRoute) }}">
            @csrf
            <button class="button secondary" type="submit">{{ __('conversations.detail.reply_copilot.retry') }}</button>
        </form>
    @else
        <p class="lede">{{ __('conversations.detail.reply_copilot.lede') }}</p>
        <form method="POST" action="{{ route('dashboard.conversations.copilot-reply-draft.store', $copilotReplyDraftRoute) }}">
            @csrf
            <button class="button secondary" type="submit">{{ __('conversations.detail.reply_copilot.generate') }}</button>
        </form>
    @endif

    <p class="field-help">{{ __('conversations.detail.reply_copilot.privacy') }}</p>
</div>
