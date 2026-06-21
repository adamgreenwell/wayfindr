@props([
    'code',
    'href' => null,
])

<span class="support-reference">
    @if ($href)
        <a class="text-link" href="{{ $href }}" aria-label="Open support record {{ $code }}">
            <code>{{ $code }}</code>
        </a>
    @else
        <code>{{ $code }}</code>
    @endif
    <button
        class="support-reference-copy"
        type="button"
        data-copy-value="{{ $code }}"
        data-copy-default-label="Copy"
        data-copy-success-label="Copied"
        aria-label="Copy support code {{ $code }}"
        title="Copy support code"
    >Copy</button>
</span>
