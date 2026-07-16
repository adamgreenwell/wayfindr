<x-layouts.app title="Break-glass — Ticket #{{ $ticket->id }}">
    <p><a class="text-link" href="{{ route('operator.break-glass.show', $grant) }}">Back to grant</a></p>
    <h1>Ticket #{{ $ticket->id }} — {{ $ticket->subject }}</h1>
    <p class="lede">
        Read-only · {{ $ticket->site?->name }} · access expires {{ $grant->expires_at->diffForHumans() }}.
    </p>

    <section class="section" aria-labelledby="break-glass-ticket-heading">
        <div class="section-header">
            <h2 id="break-glass-ticket-heading">Ticket record</h2>
            <span class="lede">{{ $ticket->status }}</span>
        </div>

        <div class="meta-grid">
            <div class="meta-item">
                <span class="meta-label">Status</span>
                <span class="meta-value">{{ $ticket->status }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Priority</span>
                <span class="meta-value">{{ $ticket->priority ?? '—' }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Category</span>
                <span class="meta-value">{{ $ticket->category ?? '—' }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Opened</span>
                <span class="meta-value">{{ $ticket->created_at->format('M j, Y H:i') }}</span>
            </div>
            @if ($ticket->conversation)
                <div class="meta-item">
                    <span class="meta-label">Conversation</span>
                    <span class="meta-value">
                        @if ($grant->coversConversation($ticket->conversation))
                            <a class="text-link" href="{{ route('operator.break-glass.conversations.show', [$grant, $ticket->conversation]) }}">{{ $ticket->conversation->support_code }}</a>
                        @else
                            {{-- An uncovered conversation is never NAMED, only acknowledged. --}}
                            (out of scope)
                        @endif
                    </span>
                </div>
            @endif
        </div>

        @if (filled($ticket->description))
            <div class="notice-copy">
                <p>{{ $ticket->description }}</p>
            </div>
        @endif
    </section>
</x-layouts.app>
