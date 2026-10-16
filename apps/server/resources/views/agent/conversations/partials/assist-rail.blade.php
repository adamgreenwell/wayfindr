<aside class="conversation-assist-rail" aria-label="{{ __('ai_assist.rail.heading') }}">
    <details data-assist-rail data-preference-key="wayfindr.assist.rail.{{ request()->user()->id }}" open>
        <summary class="conversation-assist-toggle">
            <strong>{{ __('ai_assist.rail.heading') }}</strong>
            <span class="lede">{{ __('ai_assist.rail.private') }}</span>
        </summary>
        <div class="conversation-assist-content">
            @if ($copilotPolicy->mode === 'manual' && ($copilotSummaryAvailable || $copilotReplyDraftAvailable || $copilotKnowledgeSuggestionAvailable))
                <div class="conversation-assist-actions">
                    <button type="button" class="button secondary" data-ai-prepare-all disabled>{{ __('ai_assist.preparation.prepare_all') }}</button>
                </div>
            @endif
            @foreach ([
                'summary' => ['available' => $copilotSummaryAvailable, 'view' => 'copilot-summary'],
                'reply' => ['available' => $copilotReplyDraftAvailable, 'view' => 'copilot-reply-draft'],
                'knowledge' => ['available' => $copilotKnowledgeSuggestionAvailable, 'view' => 'copilot-knowledge-suggestion'],
            ] as $railTask => $railSection)
                <div class="conversation-assist-section" data-assist-section="{{ $railTask }}">
                    @if ($railSection['available'])
                        @include('agent.conversations.partials.'.$railSection['view'], [
                            'assistInRail' => true, 'assistHeading' => __('ai_assist.rail.sections.'.$railTask),
                        ])
                    @else
                        <h3>{{ __('ai_assist.rail.sections.'.$railTask) }}</h3>
                        @if ($copilotPolicy->mode === 'off' || ($railTask !== 'summary' && ! $canReply))
                            <p class="lede">{{ __('ai_assist.rail.disabled') }}</p>
                            <p class="field-help">{{ __('ai_assist.rail.disabled_help') }}</p>
                        @else
                            <p class="lede">{{ __('ai_assist.rail.unavailable') }}</p>
                            <p class="field-help">{{ __('ai_assist.preparation.states.unavailable') }}</p>
                        @endif
                    @endif
                </div>
            @endforeach
            <footer class="conversation-assist-foot">
                <p class="field-help">{{ __('ai_assist.rail.coverage') }}</p>
                <p class="field-help">{{ __('ai_assist.rail.source_limit') }}</p>
            </footer>
        </div>
    </details>
</aside>
