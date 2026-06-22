@props([
    'ariaLabel' => null,
    'class' => 'support-reference-copy',
    'label' => 'Copy',
    'successLabel' => 'Copied',
    'title' => null,
    'value',
])

<button
    class="{{ $class }}"
    type="button"
    data-copy-value="{{ $value }}"
    data-copy-default-label="{{ $label }}"
    data-copy-success-label="{{ $successLabel }}"
    aria-label="{{ $ariaLabel ?? $label }}"
    title="{{ $title ?? $label }}"
>{{ $label }}</button>
