@props([
    'action' => null,
    'item',
])

@if ($action && ($item['confirmable'] ?? false))
    <form class="compact-form" method="POST" action="{{ $action }}">
        @csrf
        <input type="hidden" name="key" value="{{ $item['confirmation_key'] }}">
        <input
            name="note"
            type="text"
            maxlength="500"
            placeholder="Optional note"
            aria-label="Confirmation note for {{ $item['label'] }}"
            value="{{ old('note', '') }}"
        >
        <button class="button secondary" type="submit">
            {{ $item['confirmation'] ? 'Refresh confirmation' : 'Mark confirmed' }}
        </button>
    </form>
@endif
