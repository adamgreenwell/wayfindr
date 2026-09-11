@props([
    'summary' => 'Details',
    // A disclosure whose summary is translated while its body is not -- a
    // half-extracted surface -- needs to say so on the summary alone. Marking
    // the whole <details> would claim the body too.
    'summaryLang' => null,
    // Collapsed is the right default for situational content, but a disclosure
    // that CONTAINS A FORM must open itself when that form has errors. A
    // validation redirect re-renders the page with the messages inside a
    // closed <details>, so the agent sees their change silently not save and
    // no reason why. Pass the form's own error state, not a constant.
    'open' => false,
])

{{-- A native, zero-JS collapsible for situational information: content the
     agent occasionally needs (session diagnostics, provenance) but should not
     carry as ambient load on task surfaces. Collapsed by default. --}}
<details @if ($open) open @endif {{ $attributes->merge(['class' => 'details-disclosure']) }}>
    <summary class="details-disclosure__summary" @if ($summaryLang) lang="{{ str_replace('_', '-', $summaryLang) }}" @endif>{{ $summary }}</summary>
    <div class="details-disclosure__body">
        {{ $slot }}
    </div>
</details>
