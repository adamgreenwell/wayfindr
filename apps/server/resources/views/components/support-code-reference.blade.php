@props([
    'code',
    'href' => null,
])

<span class="support-reference">
    @if ($href)
        <a class="text-link" href="{{ $href }}" aria-label="{{ __('support.open_record', ['code' => $code]) }}">
            <code>{{ $code }}</code>
        </a>
    @else
        <code>{{ $code }}</code>
    @endif
    <button
        class="support-reference-copy"
        type="button"
        data-copy-value="{{ $code }}"
        data-copy-default-label="{{ __('support.copy') }}"
        data-copy-success-label="{{ __('support.copied') }}"
        aria-label="{{ __('support.copy_code_for', ['code' => $code]) }}"
        title="{{ __('support.copy_code') }}"
    >{{ __('support.copy') }}</button>
</span>
