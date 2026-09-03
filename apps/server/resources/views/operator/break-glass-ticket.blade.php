<x-layouts.operator :title="__('operator_break_glass.ticket.document_title')">
    @php
        $unknownLanguage = static fn (mixed $value): string => '<span lang="">'.e((string) $value).'</span>';
        $valueHtml = static fn (array $value): string => $value['language'] === ''
            ? $unknownLanguage($value['label'])
            : e($value['label']);
    @endphp

    <p><a class="text-link" href="{{ route('operator.break-glass.show', $grant) }}">{{ __('operator_break_glass.ticket.back') }}</a></p>
    <h1>{!! __('operator_break_glass.ticket.heading', [
        'id' => e(\App\Support\ReaderNumber::count($ticket->id)),
        'subject' => $unknownLanguage($ticket->subject),
    ]) !!}</h1>
    <p class="lede">{!! __('operator_break_glass.ticket.summary', [
        'site' => $unknownLanguage($ticket->site?->name),
        'elapsed' => e($grant->expires_at->diffForHumans()),
    ]) !!}</p>

    <section class="section" aria-labelledby="break-glass-ticket-heading">
        <div class="section-header">
            <h2 id="break-glass-ticket-heading">{{ __('operator_break_glass.ticket.record.heading') }}</h2>
            <span class="lede">{!! $valueHtml($status) !!}</span>
        </div>

        <div class="meta-grid">
            <div class="meta-item">
                <span class="meta-label">{{ __('operator_break_glass.ticket.record.status') }}</span>
                <span class="meta-value">{!! $valueHtml($status) !!}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">{{ __('operator_break_glass.ticket.record.priority') }}</span>
                <span class="meta-value">{!! $valueHtml($priority) !!}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">{{ __('operator_break_glass.ticket.record.category') }}</span>
                <span class="meta-value">{!! $valueHtml($category) !!}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">{{ __('operator_break_glass.ticket.record.opened') }}</span>
                <span class="meta-value">{{ \App\Support\ReaderClock::dateTime($ticket->created_at) }}</span>
            </div>
            @if ($ticket->conversation)
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator_break_glass.ticket.record.conversation') }}</span>
                    <span class="meta-value">
                        @if ($grant->coversConversation($ticket->conversation))
                            <a class="text-link" lang="" href="{{ route('operator.break-glass.conversations.show', [$grant, $ticket->conversation]) }}">{{ $ticket->conversation->support_code }}</a>
                        @else
                            {{-- An uncovered conversation is never NAMED, only acknowledged. --}}
                            {{ __('operator_break_glass.ticket.record.out_of_scope') }}
                        @endif
                    </span>
                </div>
            @endif
        </div>

        @if (filled($ticket->description))
            <div class="notice-copy">
                <p lang="">{{ $ticket->description }}</p>
            </div>
        @endif
    </section>
</x-layouts.operator>
