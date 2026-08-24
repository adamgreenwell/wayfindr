@props([
    'summary' => 'Details',
    // A disclosure whose summary is translated while its body is not -- a
    // half-extracted surface -- needs to say so on the summary alone. Marking
    // the whole <details> would claim the body too.
    'summaryLang' => null,
])

{{-- A native, zero-JS collapsible for situational information: content the
     agent occasionally needs (session diagnostics, provenance) but should not
     carry as ambient load on task surfaces. Collapsed by default. --}}
<details {{ $attributes->merge(['class' => 'details-disclosure']) }}>
    <summary class="details-disclosure__summary" @if ($summaryLang) lang="{{ str_replace('_', '-', $summaryLang) }}" @endif>{{ $summary }}</summary>
    <div class="details-disclosure__body">
        {{ $slot }}
    </div>
</details>
