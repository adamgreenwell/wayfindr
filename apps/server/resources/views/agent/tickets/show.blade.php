<x-layouts.app :title="__('ticket_detail.document_title', ['id' => $ticket->id])" :agent="$agent" :account="$account">
            <x-page-header :title="$ticket->subject" title-lang="" :back-href="$ticketReturnLink['href']" :back-label="$ticketReturnLink['label']">
                <p class="lede">
                    {{ __('ticket_detail.reference', ['id' => $ticket->id]) }}
                    @if ($ticket->conversation)
                        <span aria-hidden="true">-</span>
                        <x-support-code-reference
                            :code="$ticket->conversation->support_code"
                            :href="route('dashboard.conversations.show', $ticket->conversation->support_code)"
                        />
                    @endif
                </p>
            </x-page-header>

            @if (session('status'))
                {{-- Ticket actions flash a key because the same write can land
                     on this page or the linked conversation panel. Each
                     destination translates it for its own reader. --}}
                <p class="status-message">{{ __(session('status')) }}</p>
            @endif

            @php
                $ticketTiming = $ticket->queueTimingContext();
                $ticketReplyVisibility = $ticket->replyVisibility();
                $ticketLifecycleNote = $ticket->latestLifecycleNote();
                $requesterReference = $ticket->requester?->email
                    ?? $ticket->requester?->name
                    ?? $ticket->requester?->anonymous_id
                    ?? __('ticket_detail.common.not_linked');
                $requesterReferenceIsAuthored = $ticket->requester?->email !== null
                    || $ticket->requester?->name !== null
                    || $ticket->requester?->anonymous_id !== null;
                $hasVisitorContext = $visitorContext['has_visitor']
                    || $visitorContext['last_page_url']
                    || $visitorContext['started_page_url']
                    || $visitorContext['host_context'] !== [];
            @endphp
            <section class="section agent-brief" aria-labelledby="ticket-agent-brief-heading">
                <div class="section-header">
                    <div>
                        <h2 id="ticket-agent-brief-heading">{{ __('ticket_detail.brief.heading') }}</h2>
                        <p class="lede" lang="">{{ $ticket->subject }}</p>
                    </div>
                    <span class="readiness-status" data-status="{{ $ticketReplyVisibility['tone'] }}">
                        {{ __('tickets.row.'.$ticket->attentionLabelKey()) }}
                    </span>
                </div>

                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="meta-label">{{ __('ticket_detail.common.owner') }}</span>
                        <span class="meta-value" @if ($ticket->assignee?->name !== null) lang="" @endif>{{ $ticket->assignee?->name ?? __('ticket_detail.common.unassigned') }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('ticket_detail.common.priority') }}</span>
                        <span class="meta-value">{{ __('tickets.priorities.'.$ticket->priority) }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('ticket_detail.common.category') }}</span>
                        <span class="meta-value">{{ $ticket->category ? __('tickets.categories.'.$ticket->category) : __('tickets.filters.category_uncategorized') }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('ticket_detail.common.reference') }}</span>
                        <span class="meta-value">
                            @if ($ticket->conversation)
                                <x-support-code-reference
                                    :code="$ticket->conversation->support_code"
                                    :href="route('dashboard.conversations.show', $ticket->conversation->support_code)"
                                />
                            @else
                                {{ __('ticket_detail.reference', ['id' => $ticket->id]) }}
                            @endif
                        </span>
                    </div>
                </div>

                @if ($ticket->conversation)
                    <div class="section-form-row">
                        <a class="button secondary" href="{{ route('dashboard.conversations.show', $ticket->conversation->support_code) }}">
                            {{ __('ticket_detail.brief.open_conversation') }}
                        </a>
                    </div>
                @endif
            </section>
            <x-tabs
                id="ticket-workspace"
                :label="__('ticket_detail.tabs.label')"
                :tabs="[
                    ['id' => 'work', 'label' => __('ticket_detail.tabs.work')],
                    ['id' => 'conversation', 'label' => __('ticket_detail.tabs.conversation'), 'badge' => $ticket->conversation?->support_code, 'badge_lang' => ''],
                    ['id' => 'external', 'label' => __('ticket_detail.tabs.external')],
                    ['id' => 'details', 'label' => __('ticket_detail.tabs.details')],
                    ['id' => 'activity', 'label' => __('ticket_detail.tabs.activity')],
                ]"
            >
                <x-tab-panel id="work" active>
            <section class="section" aria-labelledby="ticket-work-state-heading">
                <div class="section-header">
                    <h2 id="ticket-work-state-heading">{{ __('ticket_detail.work.heading') }}</h2>
                    <span class="lede">{{ __('tickets.row.'.$ticket->attentionLabelKey()) }}</span>
                </div>

                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="meta-label">{{ __('ticket_detail.common.status') }}</span>
                        <span class="meta-value">{{ __('tickets.statuses.'.$ticket->status) }}</span>
                    </div>
                    @if ($ticketLifecycleNote)
                        <div class="meta-item">
                            <span class="meta-label">{{ __('ticket_detail.work.lifecycle_note') }}</span>
                            <span class="meta-value">{{ __('tickets.lifecycle.'.$ticketLifecycleNote['label_key']) }}</span>
                            <span class="lede" lang="">{{ $ticketLifecycleNote['body'] }}</span>
                            <span class="table-note">
                                @if ($ticketLifecycleNote['actor_key'])
                                    {{ __('tickets.row.'.$ticketLifecycleNote['actor_key']) }}
                                @else
                                    <span lang="">{{ $ticketLifecycleNote['actor'] }}</span>
                                @endif
                                - {{ $ticketLifecycleNote['occurred_at']->diffForHumans() }}
                            </span>
                        </div>
                    @endif
                    <div class="meta-item">
                        <span class="meta-label">{{ __('ticket_detail.common.timing') }}</span>
                        <span class="meta-value">{{ __('tickets.row.opened', ['elapsed' => $ticketTiming['opened_at']->diffForHumans()]) }}</span>
                        <span class="lede">{{ $ticketTiming['wait_since']
                            ? __('tickets.row.'.$ticketTiming['wait_key'], [
                                'elapsed' => $ticketTiming['wait_key'] === 'closed'
                                    ? $ticketTiming['wait_since']->diffForHumans()
                                    : $ticket->elapsedWaitFrom($ticketTiming['wait_since']),
                            ])
                            : __('tickets.row.'.$ticketTiming['wait_key']) }}</span>
                    </div>
                </div>
            </section>

            @if ($latestTicketEscalation)
                @php
                    $escalationActor = $latestTicketEscalation->actor?->name ?? __('ticket_detail.common.agent');
                    $escalationTarget = data_get($latestTicketEscalation->metadata, 'target_agent_name')
                        ?? data_get($latestTicketEscalation->metadata, 'new_assignee_name')
                        ?? $ticket->assignee?->name
                        ?? __('ticket_detail.common.unassigned');
                    $escalationActorIsAuthored = $latestTicketEscalation->actor?->name !== null;
                    $escalationTargetIsAuthored = data_get($latestTicketEscalation->metadata, 'target_agent_name') !== null
                        || data_get($latestTicketEscalation->metadata, 'new_assignee_name') !== null
                        || $ticket->assignee?->name !== null;
                    $escalationFeedback = [
                        'key' => 'ticket_detail.work.escalated',
                        'parameters' => array_filter([
                            'actor' => $escalationActorIsAuthored ? $escalationActor : null,
                            'target' => $escalationTargetIsAuthored ? $escalationTarget : null,
                        ], fn ($value) => $value !== null),
                        'localized_parameters' => array_filter([
                            'actor' => $escalationActorIsAuthored ? null : $escalationActor,
                            'target' => $escalationTargetIsAuthored ? null : $escalationTarget,
                        ], fn ($value) => $value !== null),
                    ];
                    $escalationReason = data_get($latestTicketEscalation->metadata, 'reason');
                @endphp
                <section class="section" aria-labelledby="ticket-escalation-heading">
                    <div class="section-header">
                        <h2 id="ticket-escalation-heading">{{ __('ticket_detail.work.escalation') }}</h2>
                        <span class="lede">{{ __('tickets.row.'.$ticket->escalationAudienceKeyFor($agent)) }}</span>
                    </div>

                    <div class="notice-copy">
                        <p><strong><x-translated-feedback :feedback="$escalationFeedback" /></strong></p>
                        @if ($escalationReason)
                            <p lang="">{{ $escalationReason }}</p>
                        @endif
                    </div>
                </section>
            @endif

            <section class="section" aria-labelledby="ticket-actions-heading">
                <div class="section-header">
                    <h2 id="ticket-actions-heading">{{ __('ticket_detail.actions.heading') }}</h2>
                    <span class="lede" @if ($ticket->assignee?->name !== null) lang="" @endif>{{ $ticket->assignee?->name ?? __('ticket_detail.common.unassigned') }}</span>
                </div>

                @if ($canAssignTickets)
                @php
                    $escalationAgents = $accountAgents->reject(fn ($accountAgent) => $accountAgent->is($agent))->values();
                @endphp

                <form class="section-form" method="POST" action="{{ route('dashboard.tickets.assignee.update', $ticket) }}">
                    @csrf
                    @method('PUT')
                    @include('agent.tickets.partials.return-query-fields')

                    <div class="field">
                        <label for="assignee_id">{{ __('ticket_detail.actions.assign') }}</label>
                        <select id="assignee_id" name="assignee_id">
                            <option value="">{{ __('ticket_detail.common.unassigned') }}</option>
                            @foreach ($accountAgents as $accountAgent)
                                <option lang="" value="{{ $accountAgent->id }}" @selected((int) $ticket->assignee_id === $accountAgent->id)>
                                    {{ $accountAgent->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('assignee_id')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <button class="button secondary" type="submit">{{ __('ticket_detail.actions.assign') }}</button>
                </form>

                <div class="section-form">
                    <strong>{{ __('ticket_detail.actions.escalate') }}</strong>

                    @if ($escalationAgents->isEmpty())
                        <p class="empty">{{ __('ticket_detail.actions.no_escalation_agents') }}</p>
                    @else
                        <form method="POST" action="{{ route('dashboard.tickets.escalations.store', $ticket) }}">
                            @csrf
                            @include('agent.tickets.partials.return-query-fields')

                            <div class="field">
                                <label for="target_agent_id">{{ __('ticket_detail.actions.escalate_to') }}</label>
                                <select id="target_agent_id" name="target_agent_id">
                                    <option value="">{{ __('ticket_detail.actions.choose_agent') }}</option>
                                    @foreach ($escalationAgents as $escalationAgent)
                                        <option lang="" value="{{ $escalationAgent->id }}" @selected((int) old('target_agent_id') === $escalationAgent->id)>
                                            {{ $escalationAgent->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('target_agent_id')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="field">
                                <label for="escalation_reason">{{ __('ticket_detail.actions.reason') }}</label>
                                <textarea id="escalation_reason" name="reason" rows="3" placeholder="{{ __('ticket_detail.actions.reason_placeholder') }}" @if (old('reason') !== null) lang="" @endif>{{ old('reason') }}</textarea>
                                @error('reason')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <button class="button" type="submit">{{ __('ticket_detail.actions.escalate') }}</button>
                        </form>
                    @endif
                </div>
                @endif

                @if ($ticket->status === 'open')
                    <form class="section-form" method="POST" action="{{ route('dashboard.tickets.pending', $ticket) }}">
                        @csrf
                        @include('agent.tickets.partials.return-query-fields')
                        <div class="field">
                            <label for="pending_note">{{ __('ticket_detail.actions.pending_note') }}</label>
                            <textarea id="pending_note" name="pending_note" rows="3" placeholder="{{ __('ticket_detail.actions.pending_placeholder') }}" @if (old('pending_note') !== null) lang="" @endif>{{ old('pending_note') }}</textarea>
                            @error('pending_note')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <button class="button secondary" type="submit">{{ __('ticket_detail.actions.mark_pending') }}</button>
                    </form>
                @endif

                @if (in_array($ticket->status, ['closed', 'pending'], true))
                    <form class="section-form" method="POST" action="{{ route('dashboard.tickets.reopen', $ticket) }}">
                        @csrf
                        @include('agent.tickets.partials.return-query-fields')
                        <div class="field">
                            <label for="reopen_note">{{ __('ticket_detail.actions.reopen_note') }}</label>
                            <textarea id="reopen_note" name="reopen_note" rows="3" placeholder="{{ __('ticket_detail.actions.reopen_placeholder') }}" @if (old('reopen_note') !== null) lang="" @endif>{{ old('reopen_note') }}</textarea>
                            @error('reopen_note')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <button class="button secondary" type="submit">{{ __('ticket_detail.actions.reopen') }}</button>
                    </form>
                @endif

                @if ($ticket->status !== 'closed')
                    <form class="section-form" method="POST" action="{{ route('dashboard.tickets.close', $ticket) }}">
                        @csrf
                        @include('agent.tickets.partials.return-query-fields')
                        <div class="field">
                            <label for="resolution_note">{{ __('ticket_detail.actions.resolution_note') }}</label>
                            <textarea id="resolution_note" name="resolution_note" rows="3" placeholder="{{ __('ticket_detail.actions.resolution_placeholder') }}" @if (old('resolution_note') !== null) lang="" @endif>{{ old('resolution_note') }}</textarea>
                            @error('resolution_note')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <button class="button secondary" type="submit">{{ __('ticket_detail.actions.close') }}</button>
                    </form>
                @endif
            </section>

            <section class="section" aria-labelledby="ticket-notes-heading">
                <div class="section-header">
                    <h2 id="ticket-notes-heading">{{ __('ticket_detail.notes.heading') }}</h2>
                    <span class="lede">{{ trans_choice('ticket_detail.counts.total', $ticket->auditEvents->count(), ['count' => $ticket->auditEvents->count()]) }}</span>
                </div>

                <div class="message-list">
                    @forelse ($ticket->auditEvents as $note)
                        <article class="message-card agent-message">
                            <div class="message-meta">
                                <strong @if ($note->actor?->name !== null) lang="" @endif>{{ $note->actor?->name ?? __('ticket_detail.common.unknown_agent') }}</strong>
                                <span>{{ $note->occurred_at->diffForHumans() }}</span>
                            </div>
                            <p lang="">{{ data_get($note->metadata, 'body') }}</p>
                        </article>
                    @empty
                        <div class="empty-state">
                            <strong>{{ __('ticket_detail.notes.empty') }}</strong>
                            <p class="lede">{{ __('ticket_detail.notes.empty_detail') }}</p>
                        </div>
                    @endforelse
                </div>

                @php
                    $oldNoteTemplate = old('note_template', '');
                    $selectedNoteTemplate = is_string($oldNoteTemplate) ? $oldNoteTemplate : '';
                @endphp

                <div class="reply-workspace" data-reply-shell>
                    <form class="section-form" method="POST" action="{{ route('dashboard.tickets.notes.store', $ticket) }}">
                        @csrf
                        @include('agent.tickets.partials.return-query-fields')

                        <div class="field">
                            <label for="note_template">{{ __('ticket_detail.notes.helper') }}</label>
                            <select id="note_template" name="note_template" data-template-picker data-target="#body">
                                <option value="">{{ __('ticket_detail.notes.custom') }}</option>
                                @foreach ($noteTemplates as $noteTemplateKey => $noteTemplate)
                                    <option
                                        value="{{ $noteTemplateKey }}"
                                        data-body="{{ $noteTemplate['body'] }}"
                                        data-body-lang="{{ str_replace('_', '-', $noteTemplate['body_language']) }}"
                                        @selected($selectedNoteTemplate === $noteTemplateKey)
                                    >
                                        {{ $noteTemplate['label'] }}
                                    </option>
                                @endforeach
                            </select>
                            @error('note_template')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="field">
                            <label for="body">{{ __('ticket_detail.notes.add_label') }}</label>
                            <textarea id="body" name="body" rows="4" placeholder="{{ __('ticket_detail.notes.placeholder') }}" lang="">{{ old('body') }}</textarea>
                            @error('body')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        @if ($canPostNoteToExternalIssue)
                            <label class="check-row" for="post_to_external">
                                <input id="post_to_external" name="post_to_external" type="checkbox" value="1" @checked(old('post_to_external'))>
                                <span>{{ __('ticket_detail.notes.post_external') }}</span>
                            </label>
                            <p class="lede">{{ __('ticket_detail.notes.post_external_detail') }}</p>
                        @endif

                        <button class="button" type="submit">{{ __('ticket_detail.notes.add') }}</button>
                    </form>

                    <aside class="reply-assist" aria-labelledby="ticket-note-assist-heading">
                        <h3 id="ticket-note-assist-heading">{{ __('ticket_detail.notes.assist') }}</h3>

                        <div class="reply-template-preview" data-template-preview>
                            <div data-template-preview-empty @if ($selectedNoteTemplate !== '') hidden @endif>
                                <strong>{{ __('ticket_detail.notes.no_helper') }}</strong>
                                <p class="lede">{{ __('ticket_detail.notes.custom_detail') }}</p>
                            </div>

                            @foreach ($noteTemplates as $noteTemplateKey => $noteTemplate)
                                <article data-template-preview-item="{{ $noteTemplateKey }}" @if ($selectedNoteTemplate !== $noteTemplateKey) hidden @endif>
                                    <strong>{{ $noteTemplate['label'] }}</strong>
                                    <p lang="{{ str_replace('_', '-', $noteTemplate['body_language']) }}">{{ $noteTemplate['body'] }}</p>
                                </article>
                            @endforeach
                        </div>

                        <div class="notice-list">
                            <p>{{ __('ticket_detail.notes.private') }}</p>
                            <p>{{ __('ticket_detail.notes.sensitive') }}</p>
                        </div>
                    </aside>
                </div>
            </section>
                </x-tab-panel>

                <x-tab-panel id="conversation">
            @if ($ticket->conversation)
                <section class="section" aria-labelledby="linked-conversation-heading">
                    <div class="section-header">
                        <h2 id="linked-conversation-heading">{{ __('ticket_detail.conversation.heading') }}</h2>
                        <span class="lede">{{ __('tickets.statuses.'.$ticket->conversation->status) }}</span>
                    </div>

                    <div class="notice-copy">
                        <p>@if (filled($ticket->conversation->subject))<span lang="">{{ $ticket->conversation->subject }}</span>@else{{ __('ticket_detail.conversation.untitled') }}@endif</p>
                        <p>
                            <a class="button secondary" href="{{ route('dashboard.conversations.show', $ticket->conversation->support_code) }}">
                                {{ __('ticket_detail.conversation.view') }}
                            </a>
                        </p>
                    </div>

                    <div class="section-header">
                        <strong>{{ __('ticket_detail.conversation.recent') }}</strong>
                        <span class="lede">{{ trans_choice('ticket_detail.counts.shown', $linkedConversationMessages->count(), ['count' => $linkedConversationMessages->count()]) }}</span>
                    </div>

                    @include('agent.conversations.partials.message-list', [
                        'emptyMessage' => __('ticket_detail.conversation.empty'),
                        'transcriptMessages' => $linkedConversationMessages,
                        'supportCode' => $linkedConversationSupportCode,
                        'transcriptSiteColor' => $ticket->site->resolvedColor()->cssVariable(),
                    ])

                    @php
                        $oldReplyTemplate = old('reply_template', '');
                        $selectedReplyTemplate = is_string($oldReplyTemplate) ? $oldReplyTemplate : '';
                    @endphp

                    <div class="reply-workspace" data-reply-shell>
                        <form
                            id="ticket-reply"
                            class="section-form"
                            method="POST"
                            action="{{ route('dashboard.tickets.replies.store', $ticket) }}"
                            data-reply-composer
                            data-submitting-label="{{ __('composer.sending_visitor_reply') }}"
                        >
                            @csrf
                            @include('agent.tickets.partials.return-query-fields')

                            <div class="field">
                                <label for="reply_template">{{ __('ticket_detail.conversation.reply_helper') }}</label>
                                <select id="reply_template" name="reply_template" data-reply-template data-template-picker data-target="#message">
                                    <option value="">{{ __('ticket_detail.conversation.custom_reply') }}</option>
                                    @foreach ($replyTemplates as $replyTemplateKey => $replyTemplate)
                                        <option
                                            value="{{ $replyTemplateKey }}"
                                            data-body="{{ $replyTemplate['body'] }}"
                                            data-body-lang="{{ str_replace('_', '-', $replyTemplate['body_language'] ?? \App\Support\DashboardLanguage::FALLBACK) }}"
                                            @isset($replyTemplate['label_language']) lang="{{ $replyTemplate['label_language'] }}" @endisset
                                            @selected($selectedReplyTemplate === $replyTemplateKey)
                                        >
                                            {{ $replyTemplate['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('reply_template')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="field">
                                <label for="message">{{ __('ticket_detail.conversation.visitor_reply') }}</label>
                                <textarea
                                    id="message"
                                    name="message"
                                    rows="4"
                                    placeholder="{{ __('ticket_detail.conversation.reply_placeholder') }}"
                                    aria-describedby="ticket-reply-shortcut-help"
                                    data-reply-body
                                    data-shortcut-submit
                                    lang=""
                                >{{ old('message') }}</textarea>
                                <p id="ticket-reply-shortcut-help" class="sr-only">{{ __('ticket_detail.conversation.shortcut') }}</p>
                                @error('message')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <p class="sr-only" data-reply-status aria-live="polite"></p>

                            <button class="button" type="submit" data-reply-submit>{{ __('ticket_detail.conversation.send') }}</button>
                        </form>

                        <aside class="reply-assist" aria-labelledby="ticket-reply-assist-heading">
                            <h3 id="ticket-reply-assist-heading">{{ __('ticket_detail.conversation.assist') }}</h3>

                            <div class="reply-template-preview" data-template-preview>
                                <div data-template-preview-empty @if ($selectedReplyTemplate !== '') hidden @endif>
                                    <strong>{{ __('ticket_detail.conversation.writing') }}</strong>
                                    <p class="lede">{{ __('ticket_detail.conversation.writing_detail') }}</p>
                                </div>

                                @foreach ($replyTemplates as $replyTemplateKey => $replyTemplate)
                                    <article data-template-preview-item="{{ $replyTemplateKey }}" @if ($selectedReplyTemplate !== $replyTemplateKey) hidden @endif>
                                        <strong @isset($replyTemplate['label_language']) lang="{{ $replyTemplate['label_language'] }}" @endisset>{{ $replyTemplate['label'] }}</strong>
                                        <p lang="{{ str_replace('_', '-', $replyTemplate['body_language'] ?? \App\Support\DashboardLanguage::FALLBACK) }}">{{ $replyTemplate['body'] }}</p>
                                    </article>
                                @endforeach
                            </div>

                            <div class="notice-list">
                                <p>{{ __('ticket_detail.conversation.sensitive') }}</p>
                                <p>{{ __('ticket_detail.conversation.use') }}</p>
                            </div>
                        </aside>
                    </div>
                </section>
            @endif
                </x-tab-panel>

                <x-tab-panel id="external">
            <section class="section" aria-labelledby="external-links-heading">
                <div class="section-header">
                    <h2 id="external-links-heading">{{ __('ticket_detail.external.heading') }}</h2>
                    <span class="lede">{{ trans_choice('ticket_detail.counts.total', $ticketExternalIssueHealth['total'], ['count' => $ticketExternalIssueHealth['total']]) }}</span>
                </div>

                <x-details-disclosure
                    id="ticket-external-handoff-readiness"
                    :summary="__('ticket_detail.external.handoff_summary', ['state' => $ticketExternalIssueHandoffReadiness['label']])"
                    aria-labelledby="ticket-external-handoff-readiness-heading"
                >
                    <div class="section-header">
                        <h2 id="ticket-external-handoff-readiness-heading">{{ __('ticket_detail.external.handoff_heading') }}</h2>
                        <span class="readiness-status" data-status="{{ $ticketExternalIssueHandoffReadiness['tone'] }}">{{ $ticketExternalIssueHandoffReadiness['label'] }}</span>
                    </div>

                    <div class="meta-grid">
                        <div class="meta-item">
                            <span class="meta-label">{{ __('ticket_detail.external.issue_creation') }}</span>
                            <span class="meta-value">{{ $ticketExternalIssueHandoffReadiness['summary'] }}</span>
                            <span class="lede">{{ $ticketExternalIssueHandoffReadiness['detail'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">{{ __('ticket_detail.external.data_boundary') }}</span>
                            <span class="meta-value">{{ __('ticket_detail.external.safe_summary') }}</span>
                            <span class="lede">{{ __('ticket_detail.external.boundary_detail') }}</span>
                        </div>
                    </div>

                    @if ($ticketExternalIssueHandoffReadiness['projects']->isEmpty())
                        <p class="empty">{{ __('ticket_detail.external.no_projects') }}</p>
                    @else
                        <div class="message-list">
                            @foreach ($ticketExternalIssueHandoffReadiness['projects'] as $project)
                                <article class="message-card">
                                    <div class="message-meta">
                                        <strong @if ($project['provider_name_is_authored']) lang="" @endif>{{ $project['provider_name'] }}</strong>
                                        <span @if ($project['provider_is_brand']) lang="" @endif>{{ $project['provider_label'] }}</span>
                                    </div>
                                    <p>
                                        <span lang="">{{ $project['project_key'] }}</span>
                                        <span class="readiness-status" data-status="{{ $project['state']['tone'] }}">{{ $project['state']['label'] }}</span>
                                    </p>
                                    <p class="lede">{{ $project['state']['detail'] }}</p>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </x-details-disclosure>

                <x-details-disclosure
                    id="ticket-external-issue-health"
                    :summary="__('ticket_detail.external.health_summary', ['state' => $ticketExternalIssueHealth['label']])"
                    aria-labelledby="ticket-external-issue-health-heading"
                >
                    <div class="section-header">
                        <h2 id="ticket-external-issue-health-heading">{{ __('ticket_detail.external.health_heading') }}</h2>
                        <span class="readiness-status" data-status="{{ $ticketExternalIssueHealth['tone'] }}">{{ $ticketExternalIssueHealth['label'] }}</span>
                    </div>

                    <div class="meta-grid">
                        @foreach ($ticketExternalIssueHealth['status_counts'] as $statusCount)
                            <div class="meta-item">
                                <span class="meta-label">{{ $statusCount['label'] }}</span>
                                <span class="meta-value">{{ trans_choice('ticket_detail.counts.status', $statusCount['count'], ['count' => $statusCount['count'], 'status' => mb_strtolower($statusCount['label'])]) }}</span>
                            </div>
                        @endforeach
                        <div class="meta-item">
                            <span class="meta-label">{{ __('ticket_detail.external.last_attempt') }}</span>
                            <span class="meta-value"><x-translated-feedback :feedback="$ticketExternalIssueHealth['latest_attempt']['label_feedback']" /></span>
                            <span class="lede"><x-translated-feedback :feedback="$ticketExternalIssueHealth['latest_attempt']['body_feedback']" /></span>
                            @if ($ticketExternalIssueHealth['latest_attempt']['occurred_at'])
                                <span class="table-note">{{ $ticketExternalIssueHealth['latest_attempt']['occurred_at']->diffForHumans() }}</span>
                            @endif
                        </div>
                    </div>

                    @if ($ticketExternalIssueHealth['total'] === 0 && $ticketExternalIssueHealth['failures']->isEmpty())
                        <p class="empty">{{ __('ticket_detail.external.none') }}</p>
                    @elseif ($ticketExternalIssueHealth['failures']->isEmpty())
                        <p class="empty">{{ __('ticket_detail.external.healthy') }}</p>
                    @else
                        <div class="timeline-list">
                            @foreach ($ticketExternalIssueHealth['failures'] as $failure)
                                <article class="timeline-item internal-note">
                                    <div class="timeline-content">
                                        <strong>{{ $loop->first ? __('ticket_detail.external.last_failure') : __('ticket_detail.external.earlier_failure') }}</strong>
                                        <p class="message-body"><x-translated-feedback :feedback="$failure['feedback']" /></p>
                                        <div class="timeline-meta">
                                            @if ($failure['occurred_at'])
                                                <span>{{ $failure['occurred_at']->diffForHumans() }}</span>
                                            @endif
                                            <span>{{ __('ticket_detail.external.details_withheld') }}</span>
                                        </div>
                                        @if ($failure['retry'])
                                            <form class="compact-form external-issue-retry-form" method="POST" action="{{ $failure['retry']['route'] }}">
                                                @csrf
                                                <input type="hidden" name="site_external_issue_project_id" value="{{ $failure['retry']['site_external_issue_project_id'] }}">
                                                <button class="button secondary" type="submit"><x-translated-feedback :feedback="$failure['retry']['label_feedback']" /></button>
                                                <span class="lede">{{ __('ticket_detail.external.retry_detail') }}</span>
                                            </form>
                                        @else
                                            <p class="lede">
                                                <strong>{{ __('ticket_detail.external.retry_unavailable') }}</strong><br>
                                                {{ __('ticket_detail.external.retry_unavailable_detail') }}
                                            </p>
                                        @endif
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </x-details-disclosure>

                @if ($githubIssueProjects->isNotEmpty() || $gitlabIssueProjects->isNotEmpty() || $jiraIssueProjects->isNotEmpty())
                    <div class="section-form">
                        <strong>{{ __('ticket_detail.external.actions') }}</strong>
                        <p class="lede">{{ __('ticket_detail.external.actions_detail') }}</p>

                        @error('external_issue')
                            <p class="field-error">{{ $message }}</p>
                        @enderror

                        <div class="external-issue-export-preview" data-external-issue-export-preview>
                            <div class="notice-copy notice-copy-bordered">
                                <p><strong>{{ __('ticket_detail.external.preview') }}</strong></p>
                                <p>{{ __('ticket_detail.external.preview_detail') }}</p>
                            </div>

                            <div class="meta-grid">
                                <div class="meta-item">
                                    <span class="meta-label">{{ __('ticket_detail.external.issue_title') }}</span>
                                    <span class="meta-value" lang="">{{ $externalIssueExportPreview['title'] }}</span>
                                </div>
                                <div class="meta-item">
                                    <span class="meta-label">{{ __('ticket_detail.external.data_boundary') }}</span>
                                    <span class="meta-value">{{ __('ticket_detail.external.safe_summary') }}</span>
                                    <span class="lede">{{ __('ticket_detail.external.preview_boundary_detail') }}</span>
                                </div>
                            </div>

                            <div class="section-header">
                                <strong>{{ __('ticket_detail.external.summary_sent') }}</strong>
                                <span class="lede">{{ __('ticket_detail.external.summary_detail') }}</span>
                            </div>
                            <pre class="code-block"><code lang="">{{ $externalIssueExportPreview['body'] }}</code></pre>
                        </div>

                        @foreach ($githubIssueProjects as $githubIssueProject)
                            <form method="POST" action="{{ route('dashboard.tickets.external-issues.github.store', $ticket) }}">
                                @csrf
                                <input type="hidden" name="site_external_issue_project_id" value="{{ $githubIssueProject->id }}">
                                <button class="button" type="submit">{{ __('ticket_detail.external.create_github') }}</button>
                                <span class="lede" lang="">{{ $githubIssueProject->project_key }}</span>
                            </form>
                        @endforeach

                        @foreach ($gitlabIssueProjects as $gitlabIssueProject)
                            <form method="POST" action="{{ route('dashboard.tickets.external-issues.gitlab.store', $ticket) }}">
                                @csrf
                                <input type="hidden" name="site_external_issue_project_id" value="{{ $gitlabIssueProject->id }}">
                                <button class="button" type="submit">{{ __('ticket_detail.external.create_gitlab') }}</button>
                                <span class="lede" lang="">{{ $gitlabIssueProject->project_key }}</span>
                            </form>
                        @endforeach

                        @foreach ($jiraIssueProjects as $jiraIssueProject)
                            <form method="POST" action="{{ route('dashboard.tickets.external-issues.jira.store', $ticket) }}">
                                @csrf
                                <input type="hidden" name="site_external_issue_project_id" value="{{ $jiraIssueProject->id }}">
                                <button class="button" type="submit">{{ __('ticket_detail.external.create_jira') }}</button>
                                <span class="lede" lang="">{{ $jiraIssueProject->project_key }}</span>
                            </form>
                        @endforeach
                    </div>
                @endif

                <div class="message-list">
                    @forelse ($ticket->externalLinks as $externalLink)
                        <article class="message-card">
                            <div class="message-meta">
                                <strong @if ($externalLink->provider !== 'other' && array_key_exists($externalLink->provider, $externalIssueProviders)) lang="" @endif>{{ $externalIssueProviders[$externalLink->provider] ?? __('ticket_detail.external.provider_unknown') }}</strong>
                                @php
                                    $externalIssueSyncStatus = $externalIssueSyncStatuses[$externalLink->sync_status] ?? null;
                                @endphp
                                <span @if ($externalIssueSyncStatus === null) lang="" @endif>{{ $externalIssueSyncStatus ?? $externalLink->syncStatusLabel() }}</span>
                            </div>
                            <p>
                                <span @if ($externalLink->external_key !== null || $externalLink->external_id !== null) lang="" @endif>{{ $externalLink->external_key ?? $externalLink->external_id ?? __('ticket_detail.common.external_record') }}</span>
                                <span class="lede" lang="">{{ $externalLink->project_key }}</span>
                            </p>
                            @php
                                $externalState = data_get($externalLink->metadata, 'external_state');
                                $externalStateLabel = $externalState === 'closed'
                                    ? __('ticket_detail.external.provider_state_closed')
                                    : __('ticket_detail.external.provider_state_open');
                                $externalSyncedAt = $externalLink->last_synced_at
                                    ? __('ticket_detail.external.provider_synced', ['elapsed' => $externalLink->last_synced_at->diffForHumans()])
                                    : '';
                            @endphp
                            @if ($externalState)
                                <p class="lede">{{ __('ticket_detail.external.provider_state', ['state' => $externalStateLabel, 'sync' => $externalSyncedAt]) }}</p>
                            @endif
                            <p>
                                <a class="text-link" lang="" href="{{ $externalLink->url }}" rel="noopener noreferrer" target="_blank">
                                    {{ $externalLink->url }}
                                </a>
                            </p>

                            <form method="POST" action="{{ route('dashboard.tickets.external-links.destroy', [$ticket, $externalLink]) }}">
                                @csrf
                                @method('DELETE')
                                <button class="button secondary" type="submit">{{ __('ticket_detail.external.remove') }}</button>
                            </form>
                        </article>
                    @empty
                        <div class="empty-state">
                            <strong>{{ __('ticket_detail.external.empty') }}</strong>
                            <p class="lede">{{ __('ticket_detail.external.empty_detail') }}</p>
                        </div>
                    @endforelse
                </div>

                <div class="notice-copy notice-copy-bordered">
                    <p><strong>{{ __('ticket_detail.external.manual') }}</strong></p>
                    <p>{{ __('ticket_detail.external.manual_owner') }}</p>
                    <p>{{ __('ticket_detail.external.manual_stable') }}</p>
                    <p>{{ __('ticket_detail.external.manual_boundary') }}</p>
                    <p>{{ __('ticket_detail.external.manual_no_push') }}</p>
                </div>

                <form class="section-form" method="POST" action="{{ route('dashboard.tickets.external-links.store', $ticket) }}">
                    @csrf

                    <div class="field">
                        <label for="provider">{{ __('ticket_detail.external.provider') }}</label>
                        <select id="provider" name="provider">
                            @foreach ($externalIssueProviders as $value => $label)
                                <option @if ($value !== 'other') lang="" @endif value="{{ $value }}" @selected(old('provider', 'github') === $value)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                        @error('provider')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="project_key">{{ __('ticket_detail.external.project') }}</label>
                        <input id="project_key" name="project_key" type="text" value="{{ old('project_key') }}" placeholder="{{ __('ticket_detail.external.project_placeholder') }}" lang="">
                        @error('project_key')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="external_id">{{ __('ticket_detail.external.external_id') }}</label>
                        <input id="external_id" name="external_id" type="text" value="{{ old('external_id') }}" placeholder="123" lang="">
                        @error('external_id')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="external_key">{{ __('ticket_detail.external.external_key') }}</label>
                        <input id="external_key" name="external_key" type="text" value="{{ old('external_key') }}" placeholder="#123 or PROJ-123" lang="">
                        @error('external_key')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="url">{{ __('ticket_detail.external.url') }}</label>
                        <input id="url" name="url" type="url" value="{{ old('url') }}" placeholder="https://example.test/issues/123" lang="">
                        @error('url')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="sync_status">{{ __('ticket_detail.external.sync_status') }}</label>
                        <select id="sync_status" name="sync_status">
                            @foreach ($externalIssueSyncStatuses as $value => $label)
                                <option value="{{ $value }}" @selected(old('sync_status', 'linked') === $value)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                        @error('sync_status')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <button class="button" type="submit">{{ __('ticket_detail.external.add') }}</button>
                </form>
            </section>
                </x-tab-panel>

                <x-tab-panel id="details">
            <section class="section" aria-labelledby="ticket-reference-heading">
                <div class="section-header">
                    <h2 id="ticket-reference-heading">{{ __('ticket_detail.details.support_reference') }}</h2>
                    <div class="section-actions">
                        @if ($ticket->requester)
                            <a class="button secondary" href="{{ route('dashboard.visitors.show', $ticket->requester) }}">{{ __('ticket_detail.details.open_visitor') }}</a>
                        @endif
                    </div>
                </div>

                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="meta-label">{{ __('ticket_detail.details.ticket_reference') }}</span>
                        <span class="meta-value">
                            <span class="support-reference">
                                <code>{{ __('ticket_detail.reference', ['id' => $ticket->id]) }}</code>
                                <x-copy-value-button
                                    :value="__('ticket_detail.reference', ['id' => $ticket->id])"
                                    :label="__('support.copy')"
                                    :success-label="__('support.copied')"
                                    :aria-label="__('ticket_detail.details.copy_reference_aria', ['reference' => __('ticket_detail.reference', ['id' => $ticket->id])])"
                                    :title="__('ticket_detail.details.copy_reference')"
                                />
                            </span>
                        </span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('ticket_detail.details.support_code') }}</span>
                        <span class="meta-value">
                            @if ($ticket->conversation)
                                <x-support-code-reference
                                    :code="$ticket->conversation->support_code"
                                    :href="route('dashboard.conversations.show', $ticket->conversation->support_code)"
                                />
                            @else
                                {{ __('ticket_detail.common.no_linked_conversation') }}
                            @endif
                        </span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('ticket_detail.common.site') }}</span>
                        <span class="meta-value" lang="">{{ $ticket->site->name }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('ticket_detail.common.requester') }}</span>
                        <span class="meta-value" @if ($requesterReferenceIsAuthored) lang="" @endif>{{ $requesterReference }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('ticket_detail.details.latest_page') }}</span>
                        <span class="meta-value">
                            @if ($visitorContext['last_page_url'])
                                <a class="text-link" lang="" href="{{ $visitorContext['last_page_url'] }}" target="_blank" rel="noreferrer">
                                    {{ $visitorContext['last_page_url'] }}
                                </a>
                            @else
                                {{ __('ticket_detail.common.not_reported') }}
                            @endif
                        </span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('ticket_detail.common.created') }}</span>
                        <span class="meta-value">{{ $ticket->created_at->diffForHumans() }}</span>
                        <span class="lede">{{ __('ticket_detail.common.updated', ['elapsed' => $ticket->updated_at->diffForHumans()]) }}@if ($ticket->closed_at) · {{ __('ticket_detail.common.closed', ['elapsed' => $ticket->closed_at->diffForHumans()]) }}@endif</span>
                    </div>
                </div>
            </section>

            <section class="section" aria-labelledby="ticket-labels-heading">
                <div class="section-header">
                    <h2 id="ticket-labels-heading">{{ __('ticket_detail.details.labels') }}</h2>
                    <span class="lede">{{ trans_choice('ticket_detail.counts.total', $ticket->labels->count(), ['count' => $ticket->labels->count()]) }}</span>
                </div>

                <div class="message-list">
                    @forelse ($ticket->labels as $label)
                        <article class="message-card">
                            <div class="message-meta">
                                <x-ticket-label-chip :label="$label" :ticket-status="$ticket->status" />
                                <form method="POST" action="{{ route('dashboard.tickets.labels.destroy', [$ticket, $label]) }}">
                                    @csrf
                                    @method('DELETE')
                                    @include('agent.tickets.partials.return-query-fields')
                                    <button class="button secondary" type="submit">{{ __('ticket_detail.details.remove_label') }}</button>
                                </form>
                            </div>
                        </article>
                    @empty
                        <div class="empty-state">
                            <strong>{{ __('ticket_detail.details.no_labels') }}</strong>
                        </div>
                    @endforelse
                </div>

                <form class="section-form" method="POST" action="{{ route('dashboard.tickets.labels.store', $ticket) }}">
                    @csrf
                    @include('agent.tickets.partials.return-query-fields')

                    <div class="field">
                        <label for="label_name">{{ __('ticket_detail.details.add_label') }}</label>
                        <input id="label_name" name="label_name" type="text" value="{{ old('label_name') }}" list="ticket-label-options" placeholder="needs-dev, vip, wordpress" lang="">
                        <datalist id="ticket-label-options">
                            @foreach ($ticketLabelOptions as $labelOption)
                                <option value="{{ $labelOption->name }}"></option>
                            @endforeach
                        </datalist>
                        @error('label_name')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <button class="button" type="submit">{{ __('ticket_detail.details.add_label') }}</button>
                </form>
            </section>

            <section class="section" aria-labelledby="ticket-details-heading">
                <div class="section-header">
                    <h2 id="ticket-details-heading">{{ __('ticket_detail.details.ticket') }}</h2>
                    <span class="lede">{{ __('tickets.priorities.'.$ticket->priority) }}</span>
                </div>

                <form class="section-form" method="POST" action="{{ route('dashboard.tickets.update', $ticket) }}">
                    @csrf
                    @method('PUT')
                    @include('agent.tickets.partials.return-query-fields')

                    <div class="field">
                        <label for="subject">{{ __('ticket_detail.details.subject') }}</label>
                        <input id="subject" name="subject" type="text" value="{{ old('subject', $ticket->subject) }}" lang="">
                        @error('subject')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="category">{{ __('ticket_detail.common.category') }}</label>
                        <select id="category" name="category">
                            <option value="">{{ __('tickets.filters.category_uncategorized') }}</option>
                            @foreach ($ticketCategories as $value => $category)
                                <option value="{{ $value }}" @selected(old('category', $ticket->category) === $value)>
                                    {{ __('tickets.categories.'.$value) }}
                                </option>
                            @endforeach
                        </select>
                        <x-ticket-category-guidance :categories="$ticketCategoryGuidance" />
                        @error('category')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="priority">{{ __('ticket_detail.common.priority') }}</label>
                        <select id="priority" name="priority">
                            @foreach ($ticketPriorities as $value => $priority)
                                <option value="{{ $value }}" @selected(old('priority', $ticket->priority) === $value)>
                                    {{ __('tickets.priorities.'.$value) }}
                                </option>
                            @endforeach
                        </select>
                        <x-ticket-priority-guidance :priorities="$ticketPriorityGuidance" />
                        @error('priority')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="description">{{ __('ticket_detail.details.description') }}</label>
                        <textarea id="description" name="description" rows="6" lang="">{{ old('description', $ticket->description) }}</textarea>
                        @error('description')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <button class="button" type="submit">{{ __('ticket_detail.details.save') }}</button>
                </form>
            </section>

            @php
                $priorSupportRecordCount = $priorVisitorConversations->count() + $priorVisitorTickets->count();
            @endphp
            @if ($hasVisitorContext)
                <section class="section" aria-labelledby="ticket-visitor-context-heading">
                    <div class="section-header">
                        <h2 id="ticket-visitor-context-heading">{{ __('ticket_detail.visitor_context.heading') }}</h2>
                        <span class="lede">{{ __('ticket_detail.visitor_context.safe') }}</span>
                    </div>

                    <div class="meta-grid">
                        <div class="meta-item">
                            <span class="meta-label">{{ __('ticket_detail.visitor_context.visitor') }}</span>
                            <span class="meta-value" @if ($ticket->requester?->anonymous_id !== null) lang="" @endif>{{ $visitorContext['anonymous_id'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">{{ __('ticket_detail.visitor_context.host_id') }}</span>
                            @if ($visitorContext['external_id'])<span class="meta-value" lang="">{{ $visitorContext['external_id'] }}</span>@else<span class="meta-value">{{ __('ticket_detail.common.not_provided') }}</span>@endif
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">{{ __('ticket_detail.visitor_context.last_seen') }}</span>
                            <span class="meta-value">{{ $visitorContext['last_seen_at']?->diffForHumans() ?? __('ticket_detail.common.not_reported') }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">{{ __('ticket_detail.visitor_context.latest_page') }}</span>
                            @if ($visitorContext['last_page_url'])<span class="meta-value" lang="">{{ $visitorContext['last_page_url'] }}</span>@else<span class="meta-value">{{ __('ticket_detail.common.not_reported') }}</span>@endif
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">{{ __('ticket_detail.visitor_context.entry_page') }}</span>
                            @if ($visitorContext['started_page_url'])<span class="meta-value" lang="">{{ $visitorContext['started_page_url'] }}</span>@else<span class="meta-value">{{ __('ticket_detail.common.not_reported') }}</span>@endif
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">{{ __('ticket_detail.visitor_context.history') }}</span>
                            <span class="meta-value">{{ trans_choice('ticket_detail.counts.records', $priorSupportRecordCount, ['count' => $priorSupportRecordCount]) }}</span>
                        </div>
                    </div>

                    <div class="notice-copy notice-copy-bordered">
                        <p><strong>{{ __('ticket_detail.visitor_context.boundary') }}</strong></p>
                        <p>{{ __('ticket_detail.visitor_context.boundary_detail') }}</p>
                    </div>

                    <div class="section-header">
                        <strong>{{ __('ticket_detail.visitor_context.host_context') }}</strong>
                        <span class="lede">{{ trans_choice('ticket_detail.counts.fields', count($visitorContext['host_context']), ['count' => count($visitorContext['host_context'])]) }}</span>
                    </div>

                    @if ($visitorContext['host_context'] === [])
                        <p class="empty">{{ __('ticket_detail.visitor_context.host_empty') }}</p>
                    @else
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('ticket_detail.visitor_context.field') }}</th>
                                        <th scope="col">{{ __('ticket_detail.visitor_context.value') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($visitorContext['host_context'] as $field => $value)
                                        <tr>
                                            <td lang="">{{ $field }}</td>
                                            <td lang="">{{ $value }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <div class="section-header">
                        <strong>{{ __('ticket_detail.visitor_context.prior') }}</strong>
                        <span class="lede">{{ trans_choice('ticket_detail.counts.previous', $priorSupportRecordCount, ['count' => $priorSupportRecordCount]) }}</span>
                    </div>

                    @if ($priorVisitorConversations->isEmpty() && $priorVisitorTickets->isEmpty())
                        <p class="empty">{{ __('ticket_detail.visitor_context.prior_empty') }}</p>
                    @else
                        <div class="timeline-list">
                            @foreach ($priorVisitorConversations as $priorConversation)
                                <article class="timeline-item">
                                    <div class="timeline-content">
                                        <a class="text-link" href="{{ route('dashboard.conversations.show', $priorConversation->support_code) }}">
                                            @if (filled($priorConversation->subject))<span lang="">{{ $priorConversation->subject }}</span>@else{{ __('ticket_detail.conversation.untitled') }}@endif
                                        </a>
                                        <div class="timeline-meta">
                                            <span lang="">{{ $priorConversation->support_code }}</span>
                                            <span>{{ __('tickets.statuses.'.$priorConversation->status) }}</span>
                                            <span><x-translated-feedback :feedback="[
                                                'key' => 'ticket_detail.visitor_context.owner',
                                                'parameters' => $priorConversation->assignedAgent?->name !== null ? ['owner' => $priorConversation->assignedAgent->name] : [],
                                                'localized_parameters' => $priorConversation->assignedAgent?->name === null ? ['owner' => __('ticket_detail.common.unassigned')] : [],
                                            ]" /></span>
                                            <span>{{ __('ticket_detail.visitor_context.last_activity', ['elapsed' => $priorConversation->last_message_at?->diffForHumans() ?? $priorConversation->created_at->diffForHumans()]) }}</span>
                                        </div>
                                    </div>
                                </article>
                            @endforeach

                            @foreach ($priorVisitorTickets as $priorTicket)
                                <article class="timeline-item">
                                    <div class="timeline-content">
                                        <a class="text-link" href="{{ route('dashboard.tickets.show', $priorTicket) }}">
                                            <span lang="">{{ $priorTicket->subject }}</span>
                                        </a>
                                        <div class="timeline-meta">
                                            <span>{{ __('ticket_detail.reference', ['id' => $priorTicket->id]) }}</span>
                                            <span>{{ __('tickets.statuses.'.$priorTicket->status) }}</span>
                                            <span><x-translated-feedback :feedback="[
                                                'key' => 'ticket_detail.visitor_context.owner',
                                                'parameters' => $priorTicket->assignee?->name !== null ? ['owner' => $priorTicket->assignee->name] : [],
                                                'localized_parameters' => $priorTicket->assignee?->name === null ? ['owner' => __('ticket_detail.common.unassigned')] : [],
                                            ]" /></span>
                                            @if ($priorTicket->conversation)
                                                <a class="text-link" href="{{ route('dashboard.conversations.show', $priorTicket->conversation->support_code) }}">
                                                    <span lang="">{{ $priorTicket->conversation->support_code }}</span>
                                                </a>
                                            @else
                                                <span>{{ __('ticket_detail.common.not_linked') }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif
                </x-tab-panel>

                <x-tab-panel id="activity">
            <section class="section" aria-labelledby="ticket-timeline-heading">
                <div class="section-header">
                    <h2 id="ticket-timeline-heading">{{ __('ticket_detail.timeline.heading') }}</h2>
                    <span class="lede">
                        @if ($ticketTimelineFilter === 'all')
                            {{ trans_choice('ticket_detail.counts.events', $ticketTimelineTotalCount, ['count' => $ticketTimelineTotalCount]) }}
                        @else
                            {{ trans_choice('ticket_detail.counts.events_of', $ticketTimeline->count(), ['count' => $ticketTimeline->count(), 'total' => $ticketTimelineTotalCount]) }}
                        @endif
                    </span>
                </div>

                <div class="meta-grid">
                    @foreach ($ticketTimelineSummary as $timelineSummaryItem)
                        <div class="meta-item">
                            <span class="meta-label">{{ $timelineSummaryItem['label'] }}</span>
                            <span class="meta-value">{{ $timelineSummaryItem['value'] }}</span>
                            <span class="lede">{{ $timelineSummaryItem['description'] }}</span>
                        </div>
                    @endforeach
                </div>

                <div class="filter-summary" aria-label="{{ __('ticket_detail.timeline.filters_region') }}">
                    <div>
                        <strong>{{ __('ticket_detail.timeline.visibility') }}</strong>
                        <p class="lede">{{ __('ticket_detail.timeline.visibility_detail') }}</p>
                    </div>
                    <div class="filter-chips">
                        @foreach ($ticketTimelineFilters as $timelineFilterValue => $timelineFilterLabel)
                            @php
                                $timelineFilterQuery = $ticketReturnQuery;

                                if ($timelineFilterValue !== 'all') {
                                    $timelineFilterQuery['timeline_filter'] = $timelineFilterValue;
                                }
                            @endphp
                            <a
                                class="filter-chip"
                                href="{{ route('dashboard.tickets.show', ['ticket' => $ticket] + $timelineFilterQuery) }}"
                                @if ($ticketTimelineFilter === $timelineFilterValue) aria-current="page" @endif
                            >
                                {{ $timelineFilterLabel }}
                            </a>
                        @endforeach
                    </div>
                </div>

                <div class="timeline-list">
                    @forelse ($ticketTimeline as $timelineItem)
                        <article class="timeline-item {{ $timelineItem['type'] }}">
                            <div class="timeline-content">
                                <div class="message-meta">
                                    <strong><x-ticket-activity-label :label="$timelineItem['label']" :feedback="$timelineItem['label_feedback'] ?? null" :subject-change="$timelineItem['subject_change'] ?? null" :label-change="$timelineItem['label_change'] ?? null" /></strong>
                                    <span>{{ $timelineItem['occurred_at']?->diffForHumans() }}</span>
                                </div>
                                <div class="timeline-meta">
                                    <span @if ($timelineItem['actor_is_authored']) lang="" @endif>{{ $timelineItem['actor'] }}</span>
                                    <span>
                                        @if ($timelineItem['badge_feedback'] ?? null)
                                            <x-translated-feedback :feedback="$timelineItem['badge_feedback']" />
                                        @else
                                            {{ $timelineItem['badge'] }}
                                        @endif
                                    </span>
                                </div>
                                @if ($timelineItem['body'])
                                    <p class="message-body" lang="">{{ $timelineItem['body'] }}</p>
                                @endif
                            </div>
                        </article>
                    @empty
                        <div class="empty-state">
                            <strong>{{ $ticketTimelineEmptyMessage }}</strong>
                            <p class="lede">{{ $ticketTimelineEmptyDescription }}</p>
                        </div>
                    @endforelse
                </div>
            </section>

            <section class="section" aria-labelledby="ticket-activity-heading">
                <div class="section-header">
                    <h2 id="ticket-activity-heading">{{ __('ticket_detail.activity.heading') }}</h2>
                    <span class="lede">{{ trans_choice('ticket_detail.counts.total', $ticketActivity->count(), ['count' => $ticketActivity->count()]) }}</span>
                </div>

                <div class="message-list">
                    @forelse ($ticketActivity as $activity)
                        <article class="message-card">
                            <div class="message-meta">
                                <strong>
                                    <span @if ($activity['actor_is_authored']) lang="" @endif>{{ $activity['actor'] }}</span>
                                </strong>
                                <span>{{ $activity['occurred_at']?->diffForHumans() }}</span>
                            </div>
                            <p><x-ticket-activity-label :label="$activity['label']" :feedback="$activity['label_feedback']" :subject-change="$activity['subject_change']" :label-change="$activity['label_change']" /></p>
                            @if ($activity['body'])
                                <p class="message-body" lang="">{{ $activity['body'] }}</p>
                            @endif
                        </article>
                    @empty
                        <div class="empty-state">
                            <strong>{{ __('ticket_detail.activity.empty') }}</strong>
                            <p class="lede">{{ __('ticket_detail.activity.empty_detail') }}</p>
                        </div>
                    @endforelse
                </div>
            </section>
                </x-tab-panel>

            </x-tabs>
    @include('agent.partials.reply-composer-script')
</x-layouts.app>
