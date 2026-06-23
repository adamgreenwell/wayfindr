@foreach ($tickets as $ticket)
    @php
        $ticketActivityPreview = $ticket->queueActivityPreview();
        $ticketTiming = $ticket->queueTimingContext();
        $ticketNextAction = $ticket->nextAction();
        $ticketReplyVisibility = $ticket->replyVisibility();
        $ticketStatusActionReadiness = $ticket->statusActionReadiness();
        $ticketNextActionHref = $ticketNextAction['href'] === '#ticket-reply'
            ? '#reply-heading'
            : route('dashboard.tickets.show', $ticket).$ticketNextAction['href'];
    @endphp

    <article class="notice-copy notice-copy-bordered" aria-labelledby="ticket-{{ $ticket->id }}-work-heading">
        <div class="section-header">
            <div>
                <span class="meta-label">Linked ticket work</span>
                <h3 id="ticket-{{ $ticket->id }}-work-heading">{{ $ticket->subject }}</h3>
            </div>
            <div class="section-actions">
                <span class="readiness-status" data-status="{{ $ticket->attentionState() === 'needs_reply' ? 'attention' : 'manual' }}">
                    {{ $ticket->attentionLabel() }}
                </span>
                <a class="button secondary" href="{{ route('dashboard.tickets.show', $ticket) }}">Open ticket</a>
            </div>
        </div>

        <div class="meta-grid">
            <div class="meta-item">
                <span class="meta-label">Status</span>
                <span class="meta-value">{{ ucfirst($ticket->status) }}</span>
                <span class="lede">{{ $ticket->attentionDescription() }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Owner</span>
                <span class="meta-value">{{ $ticket->assignee?->name ?? 'Unassigned' }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Priority</span>
                <span class="meta-value">{{ ucfirst($ticket->priority) }}</span>
                <span class="lede">{{ $ticket->categoryLabel() }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Timing</span>
                <span class="meta-value">{{ $ticketTiming['opened_label'] }}</span>
                <span class="lede">{{ $ticketTiming['wait_label'] }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Latest activity</span>
                <span class="meta-value">{{ $ticketActivityPreview['label'] }}</span>
                <span class="lede">{{ $ticketActivityPreview['body'] }}</span>
                @if ($ticketActivityPreview['occurred_at'])
                    <span class="table-note">{{ $ticketActivityPreview['occurred_at']->diffForHumans() }}</span>
                @endif
            </div>
            <div class="meta-item">
                <span class="meta-label">Reply visibility</span>
                <span class="meta-value">{{ $ticketReplyVisibility['label'] }}</span>
                <span class="readiness-status" data-status="{{ $ticketReplyVisibility['tone'] }}">{{ ucfirst($ticketReplyVisibility['tone']) }}</span>
                <span class="lede">{{ $ticketReplyVisibility['detail'] }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Next action</span>
                <span class="meta-value">{{ $ticketNextAction['title'] }}</span>
                <span class="lede">{{ $ticketNextAction['body'] }}</span>
                <a class="text-link health-action" href="{{ $ticketNextActionHref }}">{{ $ticketNextAction['cta'] }}</a>
            </div>
            <div class="meta-item">
                <span class="meta-label">Status safety</span>
                <span class="meta-value">{{ $ticketStatusActionReadiness['title'] }}</span>
                <span class="lede">{{ $ticketStatusActionReadiness['detail'] }}</span>
            </div>
        </div>

        <div class="section-header">
            <strong>Ticket actions</strong>
            <span class="lede">Keep ownership and lifecycle close to the conversation.</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('dashboard.tickets.assignee.update', $ticket) }}">
            @csrf
            @method('PUT')

            <div class="field">
                <label for="ticket_{{ $ticket->id }}_assignee">Assign ticket</label>
                <select id="ticket_{{ $ticket->id }}_assignee" name="assignee_id">
                    <option value="">Unassigned</option>
                    @foreach ($accountAgents as $accountAgent)
                        <option value="{{ $accountAgent->id }}" @selected((int) $ticket->assignee_id === $accountAgent->id)>
                            {{ $accountAgent->name }}
                        </option>
                    @endforeach
                </select>
                @error('assignee_id')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <button class="button secondary" type="submit">Assign ticket</button>
        </form>

        <div class="section-actions">
            @if ($ticket->status === 'open')
                <form method="POST" action="{{ route('dashboard.tickets.pending', $ticket) }}">
                    @csrf
                    <button class="button secondary" type="submit">Mark pending</button>
                </form>
            @endif

            @if (in_array($ticket->status, ['closed', 'pending'], true))
                <form method="POST" action="{{ route('dashboard.tickets.reopen', $ticket) }}">
                    @csrf
                    <button class="button secondary" type="submit">Reopen ticket</button>
                </form>
            @endif
        </div>

        @if ($ticket->status !== 'closed')
            <form class="section-form" method="POST" action="{{ route('dashboard.tickets.close', $ticket) }}">
                @csrf
                <input type="hidden" name="_ticket_close_id" value="{{ $ticket->id }}">
                @php
                    $isSubmittedCloseForm = (int) old('_ticket_close_id') === $ticket->id;
                @endphp
                <div class="field">
                    <label for="ticket_{{ $ticket->id }}_resolution_note">Resolution note</label>
                    <textarea id="ticket_{{ $ticket->id }}_resolution_note" name="resolution_note" rows="2" placeholder="What changed or why this can be closed.">{{ $isSubmittedCloseForm ? old('resolution_note') : '' }}</textarea>
                    @if ($isSubmittedCloseForm)
                        @error('resolution_note')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    @endif
                </div>
                <button class="button secondary" type="submit">Close ticket</button>
            </form>
        @endif
    </article>
@endforeach
