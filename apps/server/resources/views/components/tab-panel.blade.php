@props([
    'id',
    'active' => false,
])

{{-- `$attributes` is merged so a panel can declare its own `lang`: a panel
     whose content is a recorded translation exception has to say so, and
     without this the attribute is silently dropped (#749). --}}
<div
    {{ $attributes->merge([
        'class' => 'tab-panel',
        'role' => 'tabpanel',
        'id' => 'tab-panel-'.$id,
        'aria-labelledby' => 'tab-'.$id,
        'data-tab-panel' => $id,
        'tabindex' => '0',
    ]) }}
    @unless ($active) hidden @endunless
>
    {{ $slot }}
</div>
