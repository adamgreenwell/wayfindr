@if (is_array($value) && ($value['applicable'] ?? true) === false)
    {{ __('assist_observability.report.not_applicable') }}
@elseif (is_array($value))
    {{ \App\Support\ReaderNumber::count($value['numerator']) }} / {{ \App\Support\ReaderNumber::count($value['denominator']) }}
    — {{ $value['rate'] === null ? __('assist_observability.report.unknown') : \App\Support\ReaderNumber::percentage($value['rate'] * 100, 1) }}
@else
    {{ $value === null ? __('assist_observability.report.unknown') : (is_float($value) ? \App\Support\ReaderNumber::decimal($value, 1) : \App\Support\ReaderNumber::count($value)) }}
@endif
