@php
    $policyValue = static fn (string $field) => $restorePolicyInput ? old($field, $settings[$field]) : $settings[$field];
    $policyChecked = static fn (string $field): bool => in_array($policyValue($field), [true, 1, '1'], true);
@endphp

<fieldset class="field ai-assist-policy-modes">
    <legend>{{ __('ai_assist.policy.mode_heading') }}</legend>
    @foreach (['off', 'manual', 'prepare'] as $mode)
        <label class="check-row" for="{{ $prefix }}-mode-{{ $mode }}">
            <input id="{{ $prefix }}-mode-{{ $mode }}" name="mode" type="radio" value="{{ $mode }}" @checked($policyValue('mode') === $mode) aria-describedby="{{ $prefix }}-mode-help">
            <span>{{ __('ai_assist.policy.modes.'.$mode) }}</span>
        </label>
    @endforeach
    <p class="field-help" id="{{ $prefix }}-mode-help">{{ __('ai_assist.policy.mode_help') }}</p>
    @if ($restorePolicyInput) @error('mode')<p class="field-error">{{ $message }}</p>@enderror @endif
</fieldset>

<fieldset class="field ai-assist-policy-task-group">
    <legend>{{ __('ai_assist.policy.tasks_heading') }}</legend>
    <div class="ai-assist-policy-tasks">
        @foreach (['summary', 'reply_draft', 'relevant_articles', 'ticket_details'] as $task)
            <div>
                <input name="{{ $task }}" type="hidden" value="0">
                <label class="check-row" for="{{ $prefix }}-{{ $task }}">
                    <input id="{{ $prefix }}-{{ $task }}" name="{{ $task }}" type="checkbox" value="1" @checked($policyChecked($task)) @disabled($task === 'relevant_articles' && ! $articlesPermitted) @if ($task === 'relevant_articles') data-ai-policy-articles aria-describedby="{{ $prefix }}-article-help" @endif>
                    <span>{{ __('ai_assist.policy.tasks.'.($task === 'summary' ? 'conversation_summary' : $task)) }}</span>
                </label>
                @if ($task === 'ticket_details')<p class="field-help ai-assist-policy-task-help">{{ __('ai_assist.policy.ticket_help') }}</p>@endif
                @if ($restorePolicyInput) @error($task)<p class="field-error">{{ $message }}</p>@enderror @endif
            </div>
        @endforeach
    </div>
    <p class="field-help">{{ __('ai_assist.policy.conversation_help') }}</p>
</fieldset>

<div class="ai-assist-policy-article-context" id="{{ $prefix }}-article-help">
    <p class="field-help">{{ __('ai_assist.policy.article_help') }}</p>
    <p class="field-help">{{ __('ai_assist.policy.article_withdrawal') }}</p>
    @unless ($articlesPermitted)<p class="field-help"><strong>{{ __('ai_assist.policy.articles_blocked') }}</strong></p>@endunless
    <input type="hidden" name="article_context_consent" value="0">
    <label class="check-row" for="{{ $prefix }}-article-consent">
        <input id="{{ $prefix }}-article-consent" name="article_context_consent" type="checkbox" value="1" @checked($policyChecked('article_context_consent')) @disabled(! $articlesPermitted) data-ai-policy-consent aria-describedby="{{ $prefix }}-article-consent-help">
        <span>{{ __('ai_assist.policy.article_consent') }}</span>
    </label>
    <p class="field-help" id="{{ $prefix }}-article-consent-help">{{ __('ai_assist.policy.article_consent_help') }}</p>
    @if ($restorePolicyInput) @error('article_context_consent')<p class="field-error">{{ $message }}</p>@enderror @endif
</div>

<div class="field ai-assist-policy-context">
    <label for="{{ $prefix }}-context">{{ __('ai_assist.policy.context_label') }}</label>
    <input id="{{ $prefix }}-context" name="max_context_characters" type="number" min="1000" max="{{ $contextCeiling }}" step="1" inputmode="numeric" placeholder="{{ $contextCeiling }}" value="{{ $policyValue('max_context_characters') }}" aria-describedby="{{ $prefix }}-context-help">
    <p class="field-help" id="{{ $prefix }}-context-help">{{ __('ai_assist.policy.context_help', ['limit' => \App\Support\ReaderNumber::count($contextCeiling)]) }}</p>
    @if ($restorePolicyInput) @error('max_context_characters')<p class="field-error">{{ $message }}</p>@enderror @endif
</div>
