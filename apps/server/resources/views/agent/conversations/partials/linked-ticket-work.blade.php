@foreach ($tickets as $ticket)
    @php
        $ticketActivityPreview = $ticket->queueActivityPreview();
        $ticketTiming = $ticket->queueTimingContext();
        $ticketNextAction = $ticket->nextAction();
        // The model answers with a state; the words come from here.
        $ticketNextActionKey = $ticket->nextActionKey();
        $ticketReplyVisibility = $ticket->replyVisibility();
        $ticketStatusActionReadiness = $ticket->statusActionReadiness();
        $ticketStatusReadinessKey = $ticket->statusActionReadinessKey();
        $ticketNextActionHref = $ticketNextAction['href'] === '#ticket-reply'
            ? '#reply-heading'
            : route('dashboard.tickets.show', $ticket).$ticketNextAction['href'];
    @endphp

    <article class="notice-copy notice-copy-bordered" aria-labelledby="ticket-{{ $ticket->id }}-work-heading">
        <div class="section-header">
            <div>
                <span class="meta-label">{{ __('conversations.detail.ticket.work') }}</span>
                {{-- The stored subject: copied from the conversation, or typed by an
                     agent on the ticket page. Either way it is not the language this
                     agent reads the dashboard in.

                     A PERSON'S NAME is deliberately not marked, here or anywhere
                     else. Authored text has a language; a name is a name in any
                     of them, and marking every agent name would be a much wider
                     change for no benefit a screen reader can use. --}}
                <h3 id="ticket-{{ $ticket->id }}-work-heading" lang="">{{ $ticket->subject }}</h3>
            </div>
            <div class="section-actions">
                <span class="readiness-status" data-status="{{ $ticket->attentionState() === 'needs_reply' ? 'attention' : 'manual' }}">
                    {{ __('tickets.row.'.$ticket->attentionLabelKey()) }}
                </span>
                <a class="button secondary" href="{{ route('dashboard.tickets.show', $ticket) }}">{{ __('conversations.detail.ticket.open') }}</a>
            </div>
        </div>

        <div class="meta-grid">
            <div class="meta-item">
                <span class="meta-label">{{ __('conversations.detail.context.status') }}</span>
                <span class="meta-value">{{ __('tickets.statuses.'.$ticket->status) }}</span>
                <span class="lede">{{ __('tickets.row.'.$ticket->attentionDescriptionKey()) }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">{{ __('conversations.detail.context.owner') }}</span>
                <span class="meta-value">{{ $ticket->assignee?->name ?? __('conversations.detail.context.unassigned') }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">{{ __('conversations.detail.ticket.priority') }}</span>
                <span class="meta-value">{{ __('tickets.priorities.'.$ticket->priority) }}</span>
                <span class="lede">{{ $ticket->category ? __('tickets.categories.'.$ticket->category) : __('tickets.filters.category_uncategorized') }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">{{ __('conversations.detail.context.timing') }}</span>
                <span class="meta-value">{{ __('tickets.row.opened', ['elapsed' => $ticketTiming['opened_at']->diffForHumans()]) }}</span>
                <span class="lede">{{ $ticketTiming['wait_since']
                    ? __('tickets.row.'.$ticketTiming['wait_key'], [
                        'elapsed' => $ticketTiming['wait_key'] === 'closed'
                            ? $ticketTiming['wait_since']->diffForHumans()
                            : $ticket->elapsedWaitFrom($ticketTiming['wait_since']),
                    ])
                    : __('tickets.row.'.$ticketTiming['wait_key']) }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">{{ __('conversations.detail.context.latest_activity') }}</span>
                <span class="meta-value">{{ __('tickets.row.'.$ticketActivityPreview['label_key']) }}</span>
                {{-- The body is the visitor's or agent's own words unless there
                     are none, in which case it is copy and has a key. --}}
                <span class="lede">@if ($ticketActivityPreview['body_key']){{ __('tickets.row.'.$ticketActivityPreview['body_key']) }}@else<span lang="">{{ $ticketActivityPreview['body'] }}</span>@endif</span>
                @if ($ticketActivityPreview['occurred_at'])
                    <span class="table-note">{{ $ticketActivityPreview['occurred_at']->diffForHumans() }}</span>
                @endif
            </div>
            <div class="meta-item">
                <span class="meta-label">{{ __('conversations.detail.reply.visibility') }}</span>
                <span class="meta-value">{{ $ticketReplyVisibility['cue']
                    ? __('tickets.read_state.'.$ticketReplyVisibility['cue']['key'])
                    : __('conversations.detail.ticket.none') }}</span>
                <span class="readiness-status" data-status="{{ $ticketReplyVisibility['tone'] }}">{{ __('conversations.detail.tones.'.$ticketReplyVisibility['tone']) }}</span>
                <span class="lede">{{ $ticketReplyVisibility['cue']
                    ? ($ticketReplyVisibility['cue']['seen_at']
                        ? __('tickets.read_state.detail_seen', ['elapsed' => $ticketReplyVisibility['cue']['seen_at']->diffForHumans()])
                        : __('tickets.read_state.'.$ticketReplyVisibility['cue']['detail_key']))
                    : __('conversations.detail.reply.visibility_none') }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">{{ __('conversations.detail.next_action.heading') }}</span>
                <span class="meta-value">{{ __('tickets.next_action.'.$ticketNextActionKey.'.title') }}</span>
                <span class="lede">{{ __('tickets.next_action.'.$ticketNextActionKey.'.body') }}</span>
                <a class="text-link health-action" href="{{ $ticketNextActionHref }}">{{ __('tickets.next_action.'.$ticketNextActionKey.'.cta') }}</a>
            </div>
            <div class="meta-item">
                <span class="meta-label">{{ __('conversations.detail.cobrowse.status_safety') }}</span>
                <span class="meta-value">{{ __('tickets.status_readiness.'.$ticketStatusReadinessKey.'.title') }}</span>
                <span class="lede">{{ __('tickets.status_readiness.'.$ticketStatusReadinessKey.'.detail') }}</span>
            </div>
        </div>

        <div class="section-header">
            <strong>{{ __('conversations.detail.ticket.actions') }}</strong>
            <span class="lede">{{ __('conversations.detail.ticket.lede') }}</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('dashboard.tickets.assignee.update', $ticket) }}">
            @csrf
            @method('PUT')

            <div class="field">
                <label for="ticket_{{ $ticket->id }}_assignee">{{ __('conversations.detail.ticket.assign') }}</label>
                <select id="ticket_{{ $ticket->id }}_assignee" name="assignee_id">
                    <option value="">{{ __('conversations.detail.context.unassigned') }}</option>
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

            <button class="button secondary" type="submit">{{ __('conversations.detail.ticket.assign') }}</button>
        </form>

        <div class="section-actions">
            @if ($ticket->status === 'open')
                <form method="POST" action="{{ route('dashboard.tickets.pending', $ticket) }}">
                    @csrf
                    <button class="button secondary" type="submit">{{ __('conversations.detail.ticket.pending') }}</button>
                </form>
            @endif

            @if (in_array($ticket->status, ['closed', 'pending'], true))
                <form method="POST" action="{{ route('dashboard.tickets.reopen', $ticket) }}">
                    @csrf
                    <button class="button secondary" type="submit">{{ __('conversations.detail.ticket.reopen') }}</button>
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
                    <label for="ticket_{{ $ticket->id }}_resolution_note">{{ __('conversations.detail.ticket.resolution_note') }}</label>
                    <textarea id="ticket_{{ $ticket->id }}_resolution_note" name="resolution_note" rows="2" placeholder="{{ __('conversations.detail.ticket.resolution_hint') }}">{{ $isSubmittedCloseForm ? old('resolution_note') : '' }}</textarea>
                    @if ($isSubmittedCloseForm)
                        @error('resolution_note')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    @endif
                </div>
                <button class="button secondary" type="submit">{{ __('conversations.detail.ticket.close') }}</button>
            </form>
        @endif
    </article>
@endforeach
