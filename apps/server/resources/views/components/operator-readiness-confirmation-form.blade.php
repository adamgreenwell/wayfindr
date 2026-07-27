@props([
    'action' => null,
    'item',
    'returnTo' => null,
])

@if ($action && ($item['confirmable'] ?? false))
    <form class="compact-form" method="POST" action="{{ $action }}">
        @csrf
        <input type="hidden" name="key" value="{{ $item['confirmation_key'] }}">
        @if ($returnTo)
            <input type="hidden" name="redirect_to" value="{{ $returnTo }}">
        @endif
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
