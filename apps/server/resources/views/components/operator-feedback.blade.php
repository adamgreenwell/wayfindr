@props(['feedback'])

@php
    // Operator settings actions flash either a plain catalogue key or a key
    // with runtime parameters. Those parameters are transport names, socket
    // addresses, exception details, email addresses, and similar data rather
    // than Wayfindr prose, so each one resets the inherited page language.
    $feedbackKey = is_array($feedback) ? ($feedback['key'] ?? '') : (string) $feedback;
    $feedbackParameters = collect(is_array($feedback) ? ($feedback['parameters'] ?? []) : [])
        ->mapWithKeys(fn ($value, $key) => [$key => '<span lang="">'.e((string) $value).'</span>'])
        ->all();
    $localizedParameters = collect(is_array($feedback) ? ($feedback['localized_parameters'] ?? []) : [])
        ->mapWithKeys(fn ($value, $key) => [$key => e((string) $value)])
        ->all();
@endphp

@if (is_array($feedback) && array_key_exists('raw', $feedback))
    <span lang="">{{ $feedback['raw'] }}</span>
@else
    {!! __($feedbackKey, [...$feedbackParameters, ...$localizedParameters]) !!}
@endif
