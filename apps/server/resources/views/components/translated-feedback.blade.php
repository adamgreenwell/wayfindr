@props(['feedback'])

@php
    // Catalogue copy may wrap account-authored names and identifiers. Escape
    // every replacement, and give authored values HTML's explicit
    // unknown-language boundary so only the surrounding sentence inherits the
    // dashboard locale.
    $feedbackParameters = collect($feedback['parameters'] ?? [])
        ->mapWithKeys(fn ($value, $key) => [$key => '<span lang="">'.e((string) $value).'</span>'])
        ->all();
    $localizedParameters = collect($feedback['localized_parameters'] ?? [])
        ->mapWithKeys(fn ($value, $key) => [$key => e((string) $value)])
        ->all();
@endphp

{!! __($feedback['key'], [...$feedbackParameters, ...$localizedParameters]) !!}
