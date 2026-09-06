@php
    $copilotKnowledgeState = $copilotKnowledgeSuggestion?->displayStatus();
    $copilotKnowledgeIsStale = $copilotKnowledgeSuggestion?->isStaleComparedTo($latestConversationMessageId) ?? false;
    $copilotKnowledgeRoute = ['supportCode' => $conversation->support_code] + $conversationReturnQuery;
@endphp

<div
    class="reply-template-preview"
    data-copilot-knowledge-suggestion
    data-state="{{ $copilotKnowledgeState ?? 'empty' }}"
    data-status-url="{{ route('dashboard.conversations.copilot-knowledge-suggestion.show', $copilotKnowledgeRoute) }}"
>
    <div class="reply-copilot-heading">
        <strong>{{ __('conversations.detail.knowledge_copilot.heading') }}</strong>
        <span class="readiness-status" data-status="manual">{{ __('conversations.detail.knowledge_copilot.suggested') }}</span>
    </div>

    @if ($copilotKnowledgeState === \App\Models\ConversationCopilotKnowledgeSuggestion::STATUS_PENDING)
        <p role="status"><strong>{{ __('conversations.detail.knowledge_copilot.pending') }}</strong></p>
        <p class="lede">{{ __('conversations.detail.knowledge_copilot.pending_detail') }}</p>
    @elseif ($copilotKnowledgeState === \App\Models\ConversationCopilotKnowledgeSuggestion::STATUS_READY)
        <div
            class="live-update"
            data-state="stale"
            data-copilot-knowledge-suggestion-stale
            @unless ($copilotKnowledgeIsStale) hidden @endunless
        >
            <div>
                <strong>{{ __('conversations.detail.knowledge_copilot.stale') }}</strong>
                <p class="lede">{{ __('conversations.detail.knowledge_copilot.stale_detail') }}</p>
            </div>
        </div>

        @forelse ($suggestedKnowledgeArticles as $suggestedArticle)
            <article class="notice-copy notice-copy-bordered" data-copilot-knowledge-article>
                <strong lang="">{{ $suggestedArticle['article']->title }}</strong>
                <p data-copilot-knowledge-snippet lang="">{{ $suggestedArticle['snippet'] }}</p>
                <button
                    class="button secondary"
                    type="button"
                    data-copilot-knowledge-use
                    @disabled($copilotKnowledgeIsStale)
                >{{ __('conversations.detail.knowledge_copilot.use') }}</button>
            </article>
        @empty
            <p class="lede">{{ __('conversations.detail.knowledge_copilot.no_matches') }}</p>
        @endforelse

        <p class="lede">
            {{ __('conversations.detail.knowledge_copilot.generated', ['elapsed' => $copilotKnowledgeSuggestion->completed_at?->diffForHumans()]) }}
            {{ trans_choice('conversations.detail.knowledge_copilot.source_count', $copilotKnowledgeSuggestion->source_message_count, ['count' => \App\Support\ReaderNumber::count($copilotKnowledgeSuggestion->source_message_count)]) }}
        </p>

        <form method="POST" action="{{ route('dashboard.conversations.copilot-knowledge-suggestion.store', $copilotKnowledgeRoute) }}">
            @csrf
            <button class="button secondary" type="submit">{{ __('conversations.detail.knowledge_copilot.regenerate') }}</button>
        </form>
    @elseif ($copilotKnowledgeState === \App\Models\ConversationCopilotKnowledgeSuggestion::STATUS_FAILED)
        <p><strong>{{ __('conversations.detail.knowledge_copilot.failed') }}</strong></p>
        <p class="lede">{{ __('conversations.detail.knowledge_copilot.failed_detail') }}</p>

        <form method="POST" action="{{ route('dashboard.conversations.copilot-knowledge-suggestion.store', $copilotKnowledgeRoute) }}">
            @csrf
            <button class="button secondary" type="submit">{{ __('conversations.detail.knowledge_copilot.retry') }}</button>
        </form>
    @else
        <p class="lede">{{ __('conversations.detail.knowledge_copilot.lede') }}</p>
        <form method="POST" action="{{ route('dashboard.conversations.copilot-knowledge-suggestion.store', $copilotKnowledgeRoute) }}">
            @csrf
            <button class="button secondary" type="submit">{{ __('conversations.detail.knowledge_copilot.generate') }}</button>
        </form>
    @endif

    <p class="field-help">{{ __('conversations.detail.knowledge_copilot.privacy') }}</p>
    <p class="sr-only" data-copilot-knowledge-status aria-live="polite"></p>
</div>
