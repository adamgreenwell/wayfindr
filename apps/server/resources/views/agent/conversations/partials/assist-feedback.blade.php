<div data-assist-feedback data-feedback-url="{{ route('dashboard.conversations.assist.feedback', ['supportCode' => $conversation->support_code, 'task' => $feedbackTask]) }}" data-feedback-task="{{ $feedbackTask }}" data-feedback-copy="{{ json_encode(__('assist_observability.feedback'), JSON_THROW_ON_ERROR) }}">
    <div data-feedback-controls hidden>
        <button class="button secondary" type="button" data-feedback-reject>{{ __('assist_observability.feedback.reject') }}</button>
        <label>{{ __('assist_observability.feedback.usefulness') }}
            <select data-feedback-usefulness><option value="">{{ __('assist_observability.feedback.unanswered') }}</option>@foreach(['useful','not_useful','unsure'] as $assessment)<option value="{{ $assessment }}">{{ __('assist_observability.feedback.'.$assessment) }}</option>@endforeach</select>
        </label>
        <label data-feedback-correction-label hidden>{{ __('assist_observability.feedback.correction') }}
            <select data-feedback-correction>@foreach(['unassessed','none','minor','material'] as $effort)<option value="{{ $effort }}">{{ __('assist_observability.feedback.'.$effort) }}</option>@endforeach</select>
        </label>
        <button class="button secondary" type="button" data-feedback-save>{{ __('assist_observability.feedback.save') }}</button>
    </div>
    <p class="field-help" data-feedback-status></p>
</div>
