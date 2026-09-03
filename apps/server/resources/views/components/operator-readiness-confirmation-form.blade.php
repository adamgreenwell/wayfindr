@props([
    'action' => null,
    'idPrefix',
    'item',
    'returnTo' => null,
])

@if ($action && ($item['confirmable'] ?? false))
    @php($noteId = $idPrefix.'-readiness-confirmation-note-'.$item['confirmation_key'])
    <form class="compact-form" method="POST" action="{{ $action }}">
        @csrf
        <input type="hidden" name="key" value="{{ $item['confirmation_key'] }}">
        @if ($returnTo)
            <input type="hidden" name="redirect_to" value="{{ $returnTo }}">
        @endif
        <label class="sr-only" for="{{ $noteId }}">
            {{ __('operator.readiness.confirmation.note_for', ['label' => $item['label']]) }}
        </label>
        <input
            id="{{ $noteId }}"
            name="note"
            type="text"
            lang=""
            maxlength="500"
            placeholder="{{ __('operator.readiness.confirmation.optional_note') }}"
            value="{{ old('note', '') }}"
        >
        <button class="button secondary" type="submit">
            {{ $item['confirmation'] ? __('operator.readiness.confirmation.refresh') : __('operator.readiness.confirmation.mark') }}
        </button>
    </form>
@endif
