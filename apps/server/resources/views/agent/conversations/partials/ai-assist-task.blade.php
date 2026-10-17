@php
    $assistTask = array_search($assistPurpose, \App\Http\Controllers\AgentConversationAiPreparationController::TASKS, true);
    $assistWorkspace = $assistTask === 'ticket' ? 'conversion' : 'conversation';
    $assistMode = app(\App\Support\Ai\Policy\AiAssistPolicyResolver::class)->forConversation($conversation)->mode;
    try {
        $assistState = app(\App\Support\Ai\Preparation\AiAssistPreparation::class)->read(request()->user(), $conversation, $assistPurpose);
    } catch (\Throwable) {
        $assistState = ['state' => 'unavailable', 'output' => null, 'generation' => null, 'mode' => $assistMode,
            'can_use' => false, 'can_prepare' => false, 'retry_after' => 0];
    }
    $assistParameters = ['supportCode' => $conversation->support_code, 'task' => $assistTask];
    $assistNativeRoute = match ($assistTask) {
        'summary' => 'dashboard.conversations.copilot-summary.store',
        'reply' => 'dashboard.conversations.copilot-reply-draft.store',
        'knowledge' => 'dashboard.conversations.copilot-knowledge-suggestion.store',
        'ticket' => 'dashboard.conversations.copilot-ticket-suggestion.store',
    };
    $assistLegacyCopy = match ($assistTask) {
        'summary' => 'conversations.detail.copilot', 'reply' => 'conversations.detail.reply_copilot',
        'knowledge' => 'conversations.detail.knowledge_copilot', 'ticket' => 'conversations.detail.ticket_copilot',
    };
    $assistCopy = ['grounding' => __('ai_assist.grounding'), 'source_new_tab' => __('ai_assist.rail.source_new_tab'), 'source_one' => trans_choice($assistLegacyCopy.'.source_count', 1, ['count' => ':count']),
        'source_many' => trans_choice($assistLegacyCopy.'.source_count', 2, ['count' => ':count']),
        'no_matches' => __('conversations.detail.knowledge_copilot.no_matches'), 'states' => __('ai_assist.preparation.states'), 'prepare_ticket' => __('ai_assist.preparation.prepare_ticket'), 'prepare' => __('ai_assist.preparation.prepare'), 'refresh' => __('ai_assist.preparation.refresh'),
        'retry' => __('ai_assist.preparation.retry'), 'use' => $assistTask === 'reply' ? __('ai_assist.rail.insert') : __('ai_assist.preparation.use'), 'use_article' => __('ai_assist.preparation.use_article'),
        'apply' => __('ai_assist.preparation.apply'), 'priorities' => __('tickets.priorities'), 'cooldown' => __('ai_assist.preparation.cooldown'), 'blocked' => __('ai_assist.preparation.blocked'), 'used' => __('ai_assist.preparation.used')];
@endphp
<section class="reply-template-preview" aria-labelledby="assist-heading-{{ $assistTask }}"
    data-ai-assist data-state="{{ $assistState['state'] }}" data-task="{{ $assistTask }}" data-workspace="{{ $assistWorkspace }}" data-mode="{{ $assistMode }}"
    data-status-url="{{ route('dashboard.conversations.assist.show', $assistParameters) }}"
    data-ensure-url="{{ route('dashboard.conversations.assist.store', $assistParameters) }}"
    data-use-url="{{ route('dashboard.conversations.assist.use', $assistParameters) }}"
    data-generation="{{ $assistState['generation'] ?? '' }}" data-policy-revision="{{ $assistState['policy_revision'] ?? '' }}"
    data-source-position="{{ $assistState['source_position'] ?? '' }}" data-source-ordinal="{{ $assistState['source_ordinal'] ?? '' }}"
    data-source-message-count="{{ $assistState['source_message_count'] ?? '' }}" data-source-url="{{ $assistState['source_url'] ?? '' }}"
    data-output-generation="{{ $assistState['output_generation'] ?? '' }}"
    data-initial="{{ json_encode($assistState, JSON_THROW_ON_ERROR) }}" data-copy="{{ json_encode($assistCopy, JSON_THROW_ON_ERROR) }}">
    <h3 id="assist-heading-{{ $assistTask }}">{{ $assistHeading ?? ($assistTask === 'ticket' ? __('ai_assist.preparation.tasks.ticket') : __('ai_assist.rail.sections.'.$assistTask)) }}</h3>
    <span class="readiness-status" data-status="manual">{{ __('conversations.detail.copilot.suggested') }}</span>
    <p data-ai-message @if ($assistTask === 'ticket') role="status" @endif>{{ __('ai_assist.preparation.states.'.$assistState['state']) }}</p>
    <div data-ai-output lang="">
        @if (is_string($assistState['output']['text'] ?? null))
            <p>{{ $assistState['output']['text'] }}</p>
            @if (is_array($assistState['output']['grounding'] ?? null))
                <div data-ai-grounding>
                    <p>{{ __('ai_assist.grounding.transcript') }}</p>
                    <p><strong>{{ __('ai_assist.grounding.heading') }}</strong></p>
                    @if ($assistState['output']['grounding']['decision'] === 'supported')
                        <p>{{ __('ai_assist.grounding.supported') }}</p>
                    @endif
                    @if ($assistState['output']['grounding']['limitation'] !== null)
                        <p>{{ $assistState['output']['grounding']['limitation'] }}</p>
                    @endif
                    @if ($assistState['output']['grounding']['missing_information'] !== [])
                        <p><strong>{{ __('ai_assist.grounding.missing') }}</strong></p>
                        @foreach ($assistState['output']['grounding']['missing_information'] as $assistMissing)
                            <p>{{ $assistMissing }}</p>
                        @endforeach
                    @endif
                    @if ($assistState['output']['grounding']['sources'] !== [])
                    <details class="assist-grounding-sources"><summary>{{ __('ai_assist.grounding.sources') }}</summary>
                    @foreach ($assistState['output']['grounding']['sources'] as $assistArticle)
                        <p><a href="{{ $assistArticle['source_url'] }}" target="_blank" rel="noopener" aria-label="{{ $assistArticle['title'] }} — {{ __('ai_assist.rail.source_new_tab') }}">{{ $assistArticle['title'] }}</a></p>
                        <blockquote>{{ $assistArticle['snippet'] }}</blockquote>
                    @endforeach
                    </details>
                    @endif
                </div>
            @endif
            @if ($assistTask === 'reply')
                <button type="button" class="button secondary" data-ai-use disabled>{{ $assistTask === 'reply' ? __('ai_assist.rail.insert') : __('ai_assist.preparation.use') }}</button>
            @endif
        @elseif (is_string($assistState['output']['title'] ?? null))
            <p>{{ $assistState['output']['title'] }}</p>
            <p>{{ __('tickets.priorities.'.$assistState['output']['priority']) }}</p>
            <button type="button" class="button secondary" data-ai-use disabled>{{ __('ai_assist.preparation.apply') }}</button>
        @elseif (is_array($assistState['output']['articles'] ?? null))
            @forelse ($assistState['output']['articles'] as $assistArticle)
                <p><a href="{{ $assistArticle['source_url'] }}" target="_blank" rel="noopener" aria-label="{{ $assistArticle['title'] }} — {{ __('ai_assist.rail.source_new_tab') }}">{{ $assistArticle['title'] }}</a></p><p>{{ $assistArticle['snippet'] }}</p>
                <button type="button" class="button secondary" data-ai-use disabled>{{ __('ai_assist.preparation.use_article') }}</button>
            @empty
                <p>{{ __('conversations.detail.knowledge_copilot.no_matches') }}</p>
            @endforelse
        @endif
    </div>
    <p class="lede" data-ai-source>
        @if (is_int($assistState['source_message_count'] ?? null))
            {{ trans_choice($assistLegacyCopy.'.source_count', $assistState['source_message_count'], ['count' => \App\Support\ReaderNumber::count($assistState['source_message_count'])]) }}
        @endif
    </p>
    @if (is_int($assistState['source_ordinal'] ?? null))
        <p class="field-help" data-assist-card-position><a href="#conversation-message-{{ $assistState['source_position'] }}">{{ __('ai_assist.rail.position', ['position' => \App\Support\ReaderNumber::count($assistState['source_ordinal']), 'count' => \App\Support\ReaderNumber::count($assistState['source_message_count'])]) }}</a></p>
    @endif
    <p class="field-help" data-ai-cooldown></p>
    <form method="POST" action="{{ route($assistNativeRoute, ['supportCode' => $conversation->support_code] + ($conversationReturnQuery ?? [])) }}" data-ai-form>
        @csrf
        <button type="submit" class="button secondary" name="assist_action"
            value="{{ $assistState['generation'] === null ? 'initial' : 'refresh' }}" @disabled(! $assistState['can_prepare']) data-ai-prepare>
            {{ $assistState['generation'] === null ? ($assistTask === 'ticket' ? __('ai_assist.preparation.prepare_ticket') : __('ai_assist.preparation.prepare')) : ($assistState['state'] === 'unavailable' ? __('ai_assist.preparation.retry') : __('ai_assist.preparation.refresh')) }}
        </button>
    </form>
    @if ($assistTask !== 'ticket')
        <details class="assist-task-data-use">
            <summary>{{ __('ai_assist.rail.data_use') }}</summary>
            <p class="field-help">{{ __($assistLegacyCopy.'.privacy') }}</p>
            <p class="field-help">{{ __('ai_assist.preparation.privacy') }}</p>
        </details>
    @else
        <p class="field-help">{{ __($assistLegacyCopy.'.privacy') }}</p>
        <p class="field-help">{{ __('ai_assist.preparation.privacy') }}</p>
    @endif
    @include('agent.conversations.partials.assist-feedback', ['feedbackTask' => $assistTask])
</section>
