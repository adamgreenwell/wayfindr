@php
    $railProcessor = app(\App\Support\Ai\ConversationAssistPresentation::class)->processor($conversation);
    $railCopy = ['tasks' => __('ai_assist.rail.sections'), 'states' => ['disabled' => __('ai_assist.rail.disabled')] + __('ai_assist.preparation.states'),
        'position' => __('ai_assist.rail.position'), 'no_position' => __('ai_assist.rail.no_position')];
@endphp
<aside data-assist-copy="{{ json_encode($railCopy, JSON_THROW_ON_ERROR) }}" class="conversation-assist-rail" aria-label="{{ __('ai_assist.rail.heading') }}">
    <p class="sr-only" data-assist-live role="status" aria-live="polite" aria-atomic="true"></p>
    <details data-assist-rail data-preference-key="wayfindr.assist.rail.{{ request()->user()->id }}" open>
        <summary class="conversation-assist-toggle">
            <strong>{{ __('ai_assist.rail.heading') }}</strong>
            <span class="lede">{{ __('ai_assist.rail.private') }}</span>
        </summary>
        <div class="conversation-assist-content">
            @if ($copilotPolicy?->mode === 'manual' && ($copilotSummaryAvailable || $copilotReplyDraftAvailable || $copilotKnowledgeSuggestionAvailable))
                <div class="conversation-assist-actions">
                    <button type="button" class="button secondary" data-ai-prepare-all disabled>{{ __('ai_assist.preparation.prepare_all') }}</button>
                </div>
            @endif
            @foreach ([
                'summary' => ['purpose' => 'conversation_summary', 'available' => $copilotSummaryAvailable, 'view' => 'copilot-summary'],
                'reply' => ['purpose' => 'conversation_reply_draft', 'available' => $copilotReplyDraftAvailable, 'view' => 'copilot-reply-draft'],
                'knowledge' => ['purpose' => 'conversation_knowledge_suggestion', 'available' => $copilotKnowledgeSuggestionAvailable, 'view' => 'copilot-knowledge-suggestion'],
            ] as $railTask => $railSection)
                @php($railDisabled = ($copilotPolicy !== null && ! $copilotPolicy->allows($railSection['purpose'])) || ($railTask !== 'summary' && ! $canReply))
                <div class="conversation-assist-section" data-assist-section="{{ $railTask }}" data-assist-resting-state="{{ $railDisabled ? 'disabled' : 'unavailable' }}">
                    <div data-assist-resting @if ($railSection['available']) hidden @endif>
                        <h3>{{ __('ai_assist.rail.sections.'.$railTask) }}</h3>
                        @if ($railDisabled)
                            <p class="lede">{{ __('ai_assist.rail.disabled') }}</p>
                            <p class="field-help">{{ __('ai_assist.rail.disabled_help') }}</p>
                        @else
                            <p class="lede">{{ __('ai_assist.rail.unavailable') }}</p>
                            <p class="field-help">{{ __('ai_assist.preparation.states.unavailable') }}</p>
                        @endif
                    </div>
                    @if ($railSection['available'])
                        @include('agent.conversations.partials.'.$railSection['view'], [
                            'assistHeading' => __('ai_assist.rail.sections.'.$railTask),
                        ])
                    @endif
                </div>
            @endforeach
            <section class="conversation-assist-section assist-draft-review" data-assist-draft-review data-copy="{{ json_encode(__('ai_assist.rail.review'), JSON_THROW_ON_ERROR) }}" aria-labelledby="assist-review-heading" hidden>
                <h3 id="assist-review-heading" tabindex="-1" data-assist-review-heading>{{ __('ai_assist.rail.review.heading') }}</h3>
                <h4 id="assist-review-original-heading">{{ __('ai_assist.rail.review.original') }}</h4><pre data-assist-review-original lang="" tabindex="0" role="region" aria-labelledby="assist-review-original-heading"></pre>
                <h4 id="assist-review-candidate-heading">{{ __('ai_assist.rail.review.candidate') }}</h4><pre data-assist-review-candidate lang="" tabindex="0" role="region" aria-labelledby="assist-review-candidate-heading"></pre>
                <p class="field-help" data-assist-review-status></p>
                <div class="section-actions">
                    <button type="button" class="button" data-assist-review-apply>{{ __('ai_assist.rail.review.apply') }}</button>
                    <button type="button" class="button secondary" data-assist-review-cancel>{{ __('ai_assist.rail.review.cancel') }}</button>
                </div>
            </section>
            <footer class="conversation-assist-foot">
                @foreach (['summary', 'reply', 'knowledge'] as $railTask)
                    <p class="assist-freshness"><strong>{{ __('ai_assist.rail.sections.'.$railTask) }}</strong><br>
                        <span data-assist-freshness-task="{{ $railTask }}">{{ __('ai_assist.rail.no_position') }}</span>
                        <a data-assist-position-task="{{ $railTask }}" hidden></a>
                    </p>
                @endforeach
                <p class="field-help">{{ __('ai_assist.rail.coverage') }}</p>
                <p class="field-help">{{ __('ai_assist.rail.source_limit') }}</p>
                <details class="assist-task-data-use">
                    <summary>{{ __('ai_assist.rail.how') }}</summary>
                    @if ($railProcessor !== null)
                        <p class="field-help">{{ __('ai_assist.rail.processor', ['provider' => $railProcessor['provider'], 'model' => $railProcessor['model']]) }}</p>
                        <p class="field-help">{{ __('ai_assist.source.heading') }}: {{ $railProcessor['source'] === 'installation' ? __('ai_assist.source.installation') : __('ai_assist.source.own') }}</p>
                        @if ($railProcessor['upstream'] !== null)<p class="field-help">{{ __('ai_assist.fields.openrouter_provider') }}: {{ $railProcessor['upstream'] }}</p>@endif
                        <p class="field-help">{{ __('ai_assist.rail.context_limit', ['count' => \App\Support\ReaderNumber::count($railProcessor['max_characters'])]) }}</p>
                    @else
                        <p class="field-help">{{ __('ai_assist.rail.processor_unavailable') }}</p>
                    @endif
                    <p class="field-help">{{ __('ai_assist.rail.context') }}</p>
                    @if ($railProcessor !== null)
                        <p class="field-help">{{ __('ai_assist.grounding.'.($railProcessor['article_context_allowed'] ? 'disclosure_on' : 'disclosure_off')) }}</p>
                    @endif
                    <p class="field-help">{{ __('ai_assist.rail.refinement') }}</p>
                    @if (request()->user()->hasAccountPermission(\App\Enums\AccountPermission::ManageAiConnections))
                        <p><a href="{{ route('dashboard.account.ai-assist.show') }}">{{ __('ai_assist.rail.manage_connection') }}</a></p>
                    @endif
                </details>
            </footer>
        </div>
    </details>
</aside>
