@props(['label'])

<a class="filter-chip ticket-label-chip" href="{{ route('dashboard', ['ticket_label' => $label->slug]) }}#tickets">
    {{ $label->name }}
</a>
