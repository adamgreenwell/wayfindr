@props([
    'summary' => 'Details',
])

{{-- A native, zero-JS collapsible for situational information: content the
     agent occasionally needs (session diagnostics, provenance) but should not
     carry as ambient load on task surfaces. Collapsed by default. --}}
<details {{ $attributes->merge(['class' => 'details-disclosure']) }}>
    <summary class="details-disclosure__summary">{{ $summary }}</summary>
    <div class="details-disclosure__body">
        {{ $slot }}
    </div>
</details>
