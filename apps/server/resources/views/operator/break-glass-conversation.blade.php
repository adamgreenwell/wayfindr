<x-layouts.app title="Break-glass — {{ $conversation->support_code }}">
    <p><a class="text-link" href="{{ route('operator.break-glass.show', $grant) }}">Back to grant</a></p>
    <h1>{{ $conversation->support_code }}</h1>
    <p class="lede">
        Read-only transcript · {{ $conversation->site?->name }} · access expires {{ $grant->expires_at->diffForHumans() }}.
    </p>

    <section class="section" aria-labelledby="break-glass-transcript-heading">
        <div class="section-header">
            <h2 id="break-glass-transcript-heading">Transcript</h2>
            <span class="lede">{{ $messages->count() }} {{ \Illuminate\Support\Str::plural('message', $messages->count()) }}</span>
        </div>

        @if ($messages->isEmpty())
            <div class="notice-copy">
                <p>No messages in this conversation.</p>
            </div>
        @else
            <div class="management-list">
                @foreach ($messages as $message)
                    <div class="management-link">
                        <span>
                            <strong>{{ $senderLabels[$message->id] }} · {{ $message->created_at->format('M j, H:i') }}</strong>
                            @if (filled($message->body))
                                <span class="lede">{{ $message->body }}</span>
                            @endif
                            @foreach ($attachmentsByMessage[$message->id] as $attachment)
                                <span class="lede">
                                    Attachment: {{ $attachment->original_filename }}
                                    ({{ $attachment->mime_type }}, {{ number_format($attachment->size_bytes / 1024, 1) }} KB, scan: {{ $attachment->scan_status ?? 'n/a' }})
                                    — metadata only; break-glass access does not open files.
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
                <h2 id="break-glass-conversation-tickets-heading">Tickets from this conversation</h2>
                <span class="lede">{{ $tickets->count() }} linked</span>
            </div>
            <div class="management-list">
                {{-- References only: the subject renders on the ticket page,
                     where the view is audited per resource. --}}
                @foreach ($tickets as $ticket)
                    <a class="management-link" href="{{ route('operator.break-glass.tickets.show', [$grant, $ticket]) }}">
                        <span>
                            <strong>Ticket #{{ $ticket->id }}</strong>
                            <span class="lede">{{ $ticket->status }}</span>
                        </span>
                        <span class="management-action">View</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</x-layouts.app>
