@props(['counts'])

{{--
    The pressure sentence, composed here rather than translated.

    CobrowseTransportPressure::format() builds the English by gluing parts with
    ', ' and an English pluraliser -- the structure is the half that does not
    travel. This takes the counts and lets each language decide its own plural
    and its own list separator.
--}}
@php
    $parts = [];

    if (($counts['dropped_batches'] ?? 0) > 0) {
        $parts[] = trans_choice('cobrowse.pressure.dropped', $counts['dropped_batches'], ['count' => number_format($counts['dropped_batches'])]);
    }

    if (($counts['skipped_mutations'] ?? 0) > 0) {
        $parts[] = trans_choice('cobrowse.pressure.skipped', $counts['skipped_mutations'], ['count' => number_format($counts['skipped_mutations'])]);
    }
@endphp
{{ $parts !== []
    ? implode(__('cobrowse.pressure.separator'), $parts)
    : __(($counts['has_recent_report'] ?? false) ? 'cobrowse.pressure.none_recent' : 'cobrowse.pressure.none') }}
