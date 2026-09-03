<x-layouts.operator :title="__('operator_break_glass.conversation.document_title')">
    @php
        $unknownLanguage = static fn (mixed $value): string => '<span lang="">'.e((string) $value).'</span>';
        $valueHtml = static fn (array $value): string => $value['language'] === ''
            ? $unknownLanguage($value['label'])
            : e($value['label']);
        $senderHtml = static function (array $sender) use ($unknownLanguage): string {
            if ($sender['key'] === 'agent' && $sender['name'] !== null) {
                return __('operator_break_glass.conversation.senders.agent', ['name' => $unknownLanguage($sender['name'])]);
            }

            return e(__('operator_break_glass.conversation.senders.'.$sender['key']));
        };
        $scanHtml = static fn (?string $status): string => $status === null || $status === ''
            ? e(__('operator_break_glass.values.not_available'))
            : $unknownLanguage($status);
    @endphp

    <p><a class="text-link" href="{{ route('operator.break-glass.show', $grant) }}">{{ __('operator_break_glass.conversation.back') }}</a></p>
    <h1 lang="">{{ $conversation->support_code }}</h1>
    <p class="lede">{!! __('operator_break_glass.conversation.summary', [
        'site' => $unknownLanguage($conversation->site?->name),
        'elapsed' => e($grant->expires_at->diffForHumans()),
    ]) !!}</p>

    <section class="section" aria-labelledby="break-glass-transcript-heading">
        <div class="section-header">
            <h2 id="break-glass-transcript-heading">{{ __('operator_break_glass.conversation.transcript.heading') }}</h2>
            <span class="lede">{{ trans_choice('operator_break_glass.conversation.transcript.count', $messages->count(), ['count' => \App\Support\ReaderNumber::count($messages->count())]) }}</span>
        </div>

        @if ($messages->isEmpty())
            <div class="notice-copy">
                <p>{{ __('operator_break_glass.conversation.transcript.empty') }}</p>
            </div>
        @else
            <div class="management-list">
                @foreach ($messages as $message)
                    <div class="management-link">
                        <span>
                            <strong>{!! __('operator_break_glass.conversation.transcript.message_heading', [
                                'sender' => $senderHtml($senders[$message->id]),
                                'time' => e(\App\Support\ReaderClock::dateTime($message->created_at)),
                            ]) !!}</strong>
                            @if (filled($message->body))
                                <span class="lede" lang="">{{ $message->body }}</span>
                            @endif
                            @foreach ($attachmentsByMessage[$message->id] as $attachment)
                                <span class="lede">
                                    {!! __('operator_break_glass.conversation.attachment.summary', [
                                        'filename' => $unknownLanguage($attachment->original_filename),
                                        'mime' => $unknownLanguage($attachment->mime_type),
                                        'size' => e(\App\Support\ReaderNumber::decimal($attachment->size_bytes / 1024, 1)),
                                        'scan' => $scanHtml($attachment->scan_status),
                                    ]) !!}
                                    — {{ __('operator_break_glass.conversation.attachment.boundary') }}
                                </span>
                            @endforeach
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    @if ($tickets->isNotEmpty())
        <section class="section" aria-labelledby="break-glass-conversation-tickets-heading">
            <div class="section-header">
                <h2 id="break-glass-conversation-tickets-heading">{{ __('operator_break_glass.conversation.tickets.heading') }}</h2>
                <span class="lede">{{ trans_choice('operator_break_glass.conversation.tickets.count', $tickets->count(), ['count' => \App\Support\ReaderNumber::count($tickets->count())]) }}</span>
            </div>
            <div class="management-list">
                {{-- References only: the subject renders on the ticket page,
                     where the view is audited per resource. --}}
                @foreach ($tickets as $ticket)
                    <a class="management-link" href="{{ route('operator.break-glass.tickets.show', [$grant, $ticket]) }}">
                        <span>
                            <strong>{{ __('operator_break_glass.ticket.reference', ['id' => \App\Support\ReaderNumber::count($ticket->id)]) }}</strong>
                            <span class="lede">{!! $valueHtml($ticketStatuses[$ticket->id]) !!}</span>
                        </span>
                        <span class="management-action">{{ __('operator_break_glass.conversation.tickets.view') }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</x-layouts.operator>
