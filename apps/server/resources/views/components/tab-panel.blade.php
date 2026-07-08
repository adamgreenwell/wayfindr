@props([
    'id',
    'active' => false,
])

<div
    class="tab-panel"
    role="tabpanel"
    id="tab-panel-{{ $id }}"
    aria-labelledby="tab-{{ $id }}"
    data-tab-panel="{{ $id }}"
    tabindex="0"
    @unless ($active) hidden @endunless
>
    {{ $slot }}
</div>
