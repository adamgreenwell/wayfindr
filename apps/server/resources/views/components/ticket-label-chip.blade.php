@props([
    'label',
    'ticketStatus' => null,
])

@php
    $ticketQuery = ['ticket_label' => $label->slug];

    if (is_string($ticketStatus) && $ticketStatus !== '' && $ticketStatus !== 'open') {
        $ticketQuery = [
            'ticket_status' => $ticketStatus,
            'ticket_label' => $label->slug,
        ];
    }
@endphp

<a class="filter-chip ticket-label-chip" href="{{ route('dashboard', $ticketQuery) }}#tickets">
    {{ $label->name }}
</a>
