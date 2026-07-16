<x-layouts.app title="Break-glass access — {{ $grant->scopeLabel() }}">
    <p><a class="text-link" href="{{ route('operator.break-glass.index') }}">Back to break-glass</a></p>
    <h1>{{ $grant->scopeLabel() }}</h1>
    <p class="lede">
        Read-only access until {{ $grant->expires_at->format('H:i T') }} ({{ $grant->expires_at->diffForHumans() }}).
        Every view is recorded and visible to {{ $grant->account?->name }}.
    </p>

    <section class="section" aria-labelledby="break-glass-grant-conversations-heading">
        <div class="section-header">
            <h2 id="break-glass-grant-conversations-heading">Conversations</h2>
            <span class="lede">{{ $coveredConversations->count() }} covered</span>
        </div>

        @if ($coveredConversations->isEmpty())
            <div class="notice-copy">
                <p>No conversations in scope.</p>
            </div>
        @else
            <div class="management-list">
                @foreach ($coveredConversations as $conversation)
                    <a class="management-link" href="{{ route('operator.break-glass.conversations.show', [$grant, $conversation]) }}">
                        <span>
                            <strong>{{ $conversation->support_code }}</strong>
                            <span class="lede">{{ $conversation->site?->name }} · started {{ $conversation->created_at->diffForHumans() }}</span>
                        </span>
                        <span class="management-action">View transcript</span>
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    <section class="section" aria-labelledby="break-glass-grant-tickets-heading">
        <div class="section-header">
            <h2 id="break-glass-grant-tickets-heading">Tickets</h2>
            <span class="lede">{{ $coveredTickets->count() }} covered</span>
        </div>

        @if ($coveredTickets->isEmpty())
            <div class="notice-copy">
                <p>No tickets in scope.</p>
            </div>
        @else
            <div class="management-list">
                {{-- References only: subjects are customer content and render
                     on the ticket page, where the view is audited per resource. --}}
                @foreach ($coveredTickets as $ticket)
                    <a class="management-link" href="{{ route('operator.break-glass.tickets.show', [$grant, $ticket]) }}">
                        <span>
                            <strong>Ticket #{{ $ticket->id }}</strong>
                            <span class="lede">{{ $ticket->status }} · opened {{ $ticket->created_at->diffForHumans() }}</span>
                        </span>
                        <span class="management-action">View</span>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
</x-layouts.app>
