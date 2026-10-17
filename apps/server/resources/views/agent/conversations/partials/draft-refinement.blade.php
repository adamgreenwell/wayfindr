@php
    $refinementProcessor = app(\App\Support\Ai\ConversationAssistPresentation::class)->processor($conversation);
    $refinementAllowed = $copilotPolicy?->allows('conversation_draft_refinement') === true && $refinementProcessor !== null;
@endphp
<section class="draft-refinement-controls" data-draft-refinement data-available="{{ $refinementAllowed ? 'true' : 'false' }}"
    data-authorize-url="{{ route('dashboard.conversations.draft-refinement.authorize', $conversation->support_code) }}"
    data-request-url="{{ route('dashboard.conversations.draft-refinement.store', $conversation->support_code) }}"
    data-status-url="{{ route('dashboard.conversations.draft-refinement.show', $conversation->support_code) }}"
    data-apply-url="{{ route('dashboard.conversations.draft-refinement.apply', $conversation->support_code) }}"
    data-cancel-url="{{ route('dashboard.conversations.draft-refinement.cancel', $conversation->support_code) }}"
    data-copy="{{ json_encode(__('ai_assist.refinement'), JSON_THROW_ON_ERROR) }}" aria-labelledby="draft-refinement-heading">
    <h3 id="draft-refinement-heading">{{ __('ai_assist.refinement.heading') }}</h3>
    <p class="field-help" id="draft-refinement-disclosure">{{ __('ai_assist.refinement.disclosure') }}</p>
    @if ($refinementProcessor !== null)
        <p class="field-help">{{ __('ai_assist.rail.processor', ['provider' => $refinementProcessor['provider'], 'model' => $refinementProcessor['model']]) }}</p>
        <p class="field-help">{{ __('ai_assist.grounding.'.($refinementProcessor['article_context_allowed'] ? 'disclosure_on' : 'disclosure_off')) }}</p>
    @endif
    <div class="section-actions">
        <div class="field">
            <label for="draft-refinement-action">{{ __('ai_assist.refinement.action') }}</label>
            <select id="draft-refinement-action" data-refinement-action @disabled(! $refinementAllowed)>
                @foreach (['clarify', 'shorten', 'tone', 'translate'] as $action)
                    <option value="{{ $action }}">{{ __('ai_assist.refinement.actions.'.$action) }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" data-refinement-tone-field hidden>
            <label for="draft-refinement-tone">{{ __('ai_assist.refinement.tone') }}</label>
            <select id="draft-refinement-tone" data-refinement-tone>
                @foreach (['calm', 'friendly', 'professional'] as $tone)
                    <option value="{{ $tone }}">{{ __('ai_assist.refinement.tones.'.$tone) }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" data-refinement-language-field hidden>
            <label for="draft-refinement-language">{{ __('ai_assist.refinement.language') }}</label>
            <select id="draft-refinement-language" data-refinement-language>
                @foreach (['en' => 'English', 'de' => 'Deutsch', 'it' => 'Italiano'] as $language => $label)
                    <option value="{{ $language }}" lang="{{ $language }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="button" class="button secondary" data-refinement-request aria-describedby="draft-refinement-disclosure draft-refinement-status" disabled>{{ __('ai_assist.refinement.preview') }}</button>
        <button type="button" class="button secondary" data-refinement-cancel hidden>{{ __('ai_assist.refinement.cancel') }}</button>
    </div>
    <p class="field-help" data-refinement-status id="draft-refinement-status">{{ $refinementAllowed ? __('ai_assist.refinement.review_help') : __('ai_assist.refinement.unavailable') }}</p>
</section>
