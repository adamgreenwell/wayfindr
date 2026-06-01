<x-layouts.app title="Ticket - {{ $ticket->subject }}" :agent="$agent" :account="$account">
            <a class="text-link" href="{{ route('dashboard') }}">Back to dashboard</a>
            <h1>{{ $ticket->subject }}</h1>
            <p class="lede">Ticket #{{ $ticket->id }}</p>

            @if (session('status'))
                <p class="status-message">{{ session('status') }}</p>
            @endif

            <section class="section" aria-labelledby="ticket-context-heading">
                <div class="section-header">
                    <h2 id="ticket-context-heading">Context</h2>
                    <span class="lede">{{ ucfirst($ticket->status) }}</span>
                </div>

                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="meta-label">Site</span>
                        <span class="meta-value">{{ $ticket->site->name }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Requester</span>
                        <span class="meta-value">{{ $ticket->requester?->anonymous_id ?? 'Not linked' }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Priority</span>
                        <span class="meta-value">{{ ucfirst($ticket->priority) }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Category</span>
                        <span class="meta-value">{{ $ticket->categoryLabel() }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Assignee</span>
                        <span class="meta-value">{{ $ticket->assignee?->name ?? 'Unassigned' }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Attention</span>
                        <span class="meta-value">{{ $ticket->attentionLabel() }}</span>
                        <span class="lede">{{ $ticket->attentionDescription() }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Created</span>
                        <span class="meta-value">{{ $ticket->created_at->diffForHumans() }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Updated</span>
                        <span class="meta-value">{{ $ticket->updated_at->diffForHumans() }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Closed</span>
                        <span class="meta-value">{{ $ticket->closed_at?->diffForHumans() ?? 'Not closed' }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Conversation</span>
                        <span class="meta-value">
                            @if ($ticket->conversation)
                                <a class="text-link" href="{{ route('dashboard.conversations.show', $ticket->conversation->support_code) }}">
                                    {{ $ticket->conversation->support_code }}
                                </a>
                            @else
                                Not linked
                            @endif
                        </span>
                    </div>
                </div>
            </section>

            <section class="section" aria-labelledby="ticket-timeline-heading">
                <div class="section-header">
                    <h2 id="ticket-timeline-heading">Timeline</h2>
                    <span class="lede">{{ $ticketTimeline->count() }} events</span>
                </div>

                <div class="timeline-list">
                    @forelse ($ticketTimeline as $timelineItem)
                        <article class="timeline-item {{ $timelineItem['type'] }}">
                            <div class="timeline-content">
                                <div class="message-meta">
                                    <strong>{{ $timelineItem['label'] }}</strong>
                                    <span>{{ $timelineItem['occurred_at']?->diffForHumans() }}</span>
                                </div>
                                <div class="timeline-meta">
                                    <span>{{ $timelineItem['actor'] }}</span>
                                    <span>{{ $timelineItem['badge'] }}</span>
                                </div>
                                @if ($timelineItem['body'])
                                    <p class="message-body">{{ $timelineItem['body'] }}</p>
                                @endif
                            </div>
                        </article>
                    @empty
                        <div class="empty-state">No ticket timeline events yet.</div>
                    @endforelse
                </div>
            </section>

            @if ($ticket->conversation)
                <section class="section" aria-labelledby="linked-conversation-heading">
                    <div class="section-header">
                        <h2 id="linked-conversation-heading">Linked conversation</h2>
                        <span class="lede">{{ ucfirst($ticket->conversation->status) }}</span>
                    </div>

                    <div class="notice-copy">
                        <p>{{ $ticket->conversation->subject ?? 'Untitled conversation' }}</p>
                        <p>
                            <a class="button secondary" href="{{ route('dashboard.conversations.show', $ticket->conversation->support_code) }}">
                                View linked conversation
                            </a>
                        </p>
                    </div>

                    <div class="section-header">
                        <strong>Recent conversation messages</strong>
                        <span class="lede">{{ $linkedConversationMessages->count() }} shown</span>
                    </div>

                    <div class="message-list">
                        @forelse ($linkedConversationMessages as $conversationMessage)
                            <article class="message-card @if ($conversationMessage->sender_type === \App\Models\User::class) agent-message @endif">
                                <div class="message-meta">
                                    <strong>
                                        @if ($conversationMessage->sender_type === \App\Models\User::class)
                                            {{ $conversationMessage->sender?->name ?? 'Agent' }}
                                        @else
                                            Visitor
                                        @endif
                                    </strong>
                                    <span>{{ $conversationMessage->created_at->diffForHumans() }}</span>
                                </div>
                                <p>{{ $conversationMessage->body }}</p>
                            </article>
                        @empty
                            <div class="empty-state">No conversation messages yet.</div>
                        @endforelse
                    </div>

                    <form class="section-form" method="POST" action="{{ route('dashboard.tickets.replies.store', $ticket) }}">
                        @csrf

                        <div class="field">
                            <label for="reply_template">Reply helper</label>
                            <select id="reply_template" name="reply_template" data-reply-template data-template-picker data-target="#message">
                                <option value="">Write a custom reply</option>
                                @foreach ($replyTemplates as $replyTemplateKey => $replyTemplate)
                                    <option
                                        value="{{ $replyTemplateKey }}"
                                        data-body="{{ $replyTemplate['body'] }}"
                                        @selected(old('reply_template') === $replyTemplateKey)
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
                            <label for="message">Visitor reply</label>
                            <textarea id="message" name="message" rows="4" placeholder="Send a reply to the visitor.">{{ old('message') }}</textarea>
                            @error('message')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <button class="button" type="submit">Send visitor reply</button>
                    </form>
                </section>
            @endif

            <section class="section" aria-labelledby="external-links-heading">
                <div class="section-header">
                    <h2 id="external-links-heading">External links</h2>
                    <span class="lede">{{ $ticket->externalLinks->count() }} linked</span>
                </div>

                @if ($githubIssueProjects->isNotEmpty())
                    <div class="section-form">
                        <strong>External issue actions</strong>
                        <p class="lede">Create a conservative GitHub issue from this ticket without exporting transcripts, cobrowse snapshots, or internal notes.</p>

                        @error('external_issue')
                            <p class="field-error">{{ $message }}</p>
                        @enderror

                        @foreach ($githubIssueProjects as $githubIssueProject)
                            <form method="POST" action="{{ route('dashboard.tickets.external-issues.github.store', $ticket) }}">
                                @csrf
                                <input type="hidden" name="site_external_issue_project_id" value="{{ $githubIssueProject->id }}">
                                <button class="button" type="submit">Create GitHub issue</button>
                                <span class="lede">{{ $githubIssueProject->project_key }}</span>
                            </form>
                        @endforeach
                    </div>
                @endif

                <div class="message-list">
                    @forelse ($ticket->externalLinks as $externalLink)
                        <article class="message-card">
                            <div class="message-meta">
                                <strong>{{ $externalLink->providerLabel() }}</strong>
                                <span>{{ $externalLink->syncStatusLabel() }}</span>
                            </div>
                            <p>
                                <span>{{ $externalLink->external_key ?? $externalLink->external_id ?? 'External record' }}</span>
                                <span class="lede">{{ $externalLink->project_key }}</span>
                            </p>
                            <p>
                                <a class="text-link" href="{{ $externalLink->url }}" rel="noopener noreferrer" target="_blank">
                                    {{ $externalLink->url }}
                                </a>
                            </p>

                            <form method="POST" action="{{ route('dashboard.tickets.external-links.destroy', [$ticket, $externalLink]) }}">
                                @csrf
                                @method('DELETE')
                                <button class="button secondary" type="submit">Remove external link</button>
                            </form>
                        </article>
                    @empty
                        <div class="empty-state">No external issue links yet.</div>
                    @endforelse
                </div>

                <form class="section-form" method="POST" action="{{ route('dashboard.tickets.external-links.store', $ticket) }}">
                    @csrf

                    <div class="field">
                        <label for="provider">Provider</label>
                        <select id="provider" name="provider">
                            @foreach ($externalIssueProviders as $value => $label)
                                <option value="{{ $value }}" @selected(old('provider', 'github') === $value)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                        @error('provider')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="project_key">Project or repository</label>
                        <input id="project_key" name="project_key" type="text" value="{{ old('project_key') }}" placeholder="owner/repository, group/project, or project key">
                        @error('project_key')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="external_id">External ID</label>
                        <input id="external_id" name="external_id" type="text" value="{{ old('external_id') }}" placeholder="123">
                        @error('external_id')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="external_key">External key</label>
                        <input id="external_key" name="external_key" type="text" value="{{ old('external_key') }}" placeholder="#123 or PROJ-123">
                        @error('external_key')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="url">URL</label>
                        <input id="url" name="url" type="url" value="{{ old('url') }}" placeholder="https://example.test/issues/123">
                        @error('url')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="sync_status">Sync status</label>
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

                    <button class="button" type="submit">Add external link</button>
                </form>
            </section>

            @if ($visitorContext['last_page_url'] || $visitorContext['started_page_url'] || $visitorContext['host_context'] !== [])
                <section class="section" aria-labelledby="ticket-visitor-context-heading">
                    <div class="section-header">
                        <h2 id="ticket-visitor-context-heading">Visitor context</h2>
                        <span class="lede">Captured from conversation</span>
                    </div>

                    <div class="meta-grid">
                        <div class="meta-item">
                            <span class="meta-label">Latest page</span>
                            <span class="meta-value">{{ $visitorContext['last_page_url'] ?? 'Not reported' }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Started on</span>
                            <span class="meta-value">{{ $visitorContext['started_page_url'] ?? 'Not reported' }}</span>
                        </div>
                    </div>

                    <div class="section-header">
                        <strong>Host context</strong>
                        <span class="lede">{{ count($visitorContext['host_context']) }} fields</span>
                    </div>

                    @if ($visitorContext['host_context'] === [])
                        <p class="empty">No host-provided context was captured.</p>
                    @else
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th scope="col">Field</th>
                                        <th scope="col">Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($visitorContext['host_context'] as $field => $value)
                                        <tr>
                                            <td>{{ $field }}</td>
                                            <td>{{ $value }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            @endif

            <section class="section" aria-labelledby="ticket-actions-heading">
                <div class="section-header">
                    <h2 id="ticket-actions-heading">Actions</h2>
                    <span class="lede">{{ $ticket->assignee?->name ?? 'Unassigned' }}</span>
                </div>

                <form class="section-form" method="POST" action="{{ route('dashboard.tickets.assignee.update', $ticket) }}">
                    @csrf
                    @method('PUT')

                    <div class="field">
                        <label for="assignee_id">Assign ticket</label>
                        <select id="assignee_id" name="assignee_id">
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

                @if ($ticket->status === 'open')
                    <form class="section-form" method="POST" action="{{ route('dashboard.tickets.pending', $ticket) }}">
                        @csrf
                        <button class="button secondary" type="submit">Mark pending</button>
                    </form>
                @endif

                @if (in_array($ticket->status, ['closed', 'pending'], true))
                    <form class="section-form" method="POST" action="{{ route('dashboard.tickets.reopen', $ticket) }}">
                        @csrf
                        <button class="button secondary" type="submit">Reopen ticket</button>
                    </form>
                @endif

                @if ($ticket->status !== 'closed')
                    <form class="section-form" method="POST" action="{{ route('dashboard.tickets.close', $ticket) }}">
                        @csrf
                        <div class="field">
                            <label for="resolution_note">Resolution note</label>
                            <textarea id="resolution_note" name="resolution_note" rows="3" placeholder="What changed, what was confirmed, or why this can be closed.">{{ old('resolution_note') }}</textarea>
                            @error('resolution_note')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <button class="button secondary" type="submit">Close ticket</button>
                    </form>
                @endif
            </section>

            <section class="section" aria-labelledby="ticket-details-heading">
                <div class="section-header">
                    <h2 id="ticket-details-heading">Ticket details</h2>
                    <span class="lede">{{ ucfirst($ticket->priority) }}</span>
                </div>

                <form class="section-form" method="POST" action="{{ route('dashboard.tickets.update', $ticket) }}">
                    @csrf
                    @method('PUT')

                    <div class="field">
                        <label for="subject">Subject</label>
                        <input id="subject" name="subject" type="text" value="{{ old('subject', $ticket->subject) }}">
                        @error('subject')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="category">Category</label>
                        <select id="category" name="category">
                            <option value="">Uncategorized</option>
                            @foreach ($ticketCategories as $value => $category)
                                <option value="{{ $value }}" @selected(old('category', $ticket->category) === $value)>
                                    {{ $category['label'] }}
                                </option>
                            @endforeach
                        </select>
                        <x-ticket-category-guidance :categories="$ticketCategoryGuidance" />
                        @error('category')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="priority">Priority</label>
                        <select id="priority" name="priority">
                            @foreach ($ticketPriorities as $value => $priority)
                                <option value="{{ $value }}" @selected(old('priority', $ticket->priority) === $value)>
                                    {{ $priority['label'] }}
                                </option>
                            @endforeach
                        </select>
                        <x-ticket-priority-guidance :priorities="$ticketPriorityGuidance" />
                        @error('priority')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" rows="6">{{ old('description', $ticket->description) }}</textarea>
                        @error('description')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <button class="button" type="submit">Save ticket</button>
                </form>
            </section>

            <section class="section" aria-labelledby="ticket-notes-heading">
                <div class="section-header">
                    <h2 id="ticket-notes-heading">Internal notes</h2>
                    <span class="lede">{{ $ticket->auditEvents->count() }} total</span>
                </div>

                <div class="message-list">
                    @forelse ($ticket->auditEvents as $note)
                        <article class="message-card agent-message">
                            <div class="message-meta">
                                <strong>{{ $note->actor?->name ?? 'Unknown agent' }}</strong>
                                <span>{{ $note->occurred_at->diffForHumans() }}</span>
                            </div>
                            <p>{{ data_get($note->metadata, 'body') }}</p>
                        </article>
                    @empty
                        <div class="empty-state">No internal notes yet.</div>
                    @endforelse
                </div>

                <form class="section-form" method="POST" action="{{ route('dashboard.tickets.notes.store', $ticket) }}">
                    @csrf

                    <div class="field">
                        <label for="note_template">Note helper</label>
                        <select id="note_template" name="note_template" data-template-picker data-target="#body">
                            <option value="">Write a custom note</option>
                            @foreach ($noteTemplates as $noteTemplateKey => $noteTemplate)
                                <option
                                    value="{{ $noteTemplateKey }}"
                                    data-body="{{ $noteTemplate['body'] }}"
                                    @selected(old('note_template') === $noteTemplateKey)
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
                        <label for="body">Add internal note</label>
                        <textarea id="body" name="body" rows="4" placeholder="Document follow-up, escalation context, or handoff details.">{{ old('body') }}</textarea>
                        @error('body')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <button class="button" type="submit">Add note</button>
                </form>
            </section>

            <section class="section" aria-labelledby="ticket-activity-heading">
                <div class="section-header">
                    <h2 id="ticket-activity-heading">Activity</h2>
                    <span class="lede">{{ $ticketActivity->count() }} total</span>
                </div>

                <div class="message-list">
                    @forelse ($ticketActivity as $activity)
                        <article class="message-card">
                            <div class="message-meta">
                                <strong>
                                    @if ($activity->actor_type === \App\Models\Visitor::class)
                                        Visitor
                                    @else
                                        {{ $activity->actor?->name ?? 'System' }}
                                    @endif
                                </strong>
                                <span>{{ $activity->occurred_at->diffForHumans() }}</span>
                            </div>
                            <p>
                                @switch($activity->action)
                                    @case('ticket.created')
                                        @if (data_get($activity->metadata, 'source') === 'conversation' && data_get($activity->metadata, 'support_code'))
                                            <span>Ticket created from conversation {{ data_get($activity->metadata, 'support_code') }}</span>
                                        @else
                                            <span>Ticket created</span>
                                        @endif
                                        @break

                                    @case('ticket.closed')
                                        Ticket closed
                                        @break

                                    @case('ticket.pending')
                                        Ticket marked pending
                                        @break

                                    @case('ticket.reopened')
                                        Ticket reopened
                                        @break

                                    @case('ticket.note_added')
                                        Internal note added
                                        @break

                                    @case('ticket.reply_sent')
                                        Visitor reply sent
                                        @break

                                    @case('ticket.external_link_created')
                                        External link added
                                        @break

                                    @case('ticket.external_issue_created')
                                        GitHub issue created
                                        @break

                                    @case('ticket.external_link_removed')
                                        External link removed
                                        @break

                                    @case('ticket.external_sync_failed')
                                        External sync failed
                                        @break

                                    @case('ticket.visitor_replied')
                                        Visitor replied
                                        @break

                                    @case('ticket.assignee_updated')
                                        Assignee changed from {{ data_get($activity->metadata, 'old_assignee_name') ?? 'Unassigned' }} to {{ data_get($activity->metadata, 'new_assignee_name') ?? 'Unassigned' }}
                                        @break

                                    @case('ticket.updated')
                                        @foreach (data_get($activity->metadata, 'changes', []) as $field => $change)
                                            @if ($field === 'description')
                                                <span>Description updated</span>@if (! $loop->last)<br>@endif
                                            @elseif ($field === 'category')
                                                <span>Category changed from {{ \App\Support\TicketCategory::label(data_get($change, 'old')) }} to {{ \App\Support\TicketCategory::label(data_get($change, 'new')) }}</span>@if (! $loop->last)<br>@endif
                                            @elseif ($field === 'priority')
                                                <span>Priority changed from {{ ucfirst(data_get($change, 'old')) }} to {{ ucfirst(data_get($change, 'new')) }}</span>@if (! $loop->last)<br>@endif
                                            @else
                                                <span>{{ ucfirst($field) }} changed from {{ data_get($change, 'old') }} to {{ data_get($change, 'new') }}</span>@if (! $loop->last)<br>@endif
                                            @endif
                                        @endforeach
                                        @break

                                    @default
                                        {{ ucfirst(str_replace(['ticket.', '_'], ['', ' '], $activity->action)) }}
                                @endswitch
                            </p>
                            @if (data_get($activity->metadata, 'resolution_note'))
                                <p class="message-body">{{ data_get($activity->metadata, 'resolution_note') }}</p>
                            @endif
                        </article>
                    @empty
                        <div class="empty-state">No ticket activity yet.</div>
                    @endforelse
                </div>
            </section>
    <script>
        document.querySelectorAll('[data-template-picker]').forEach((templatePicker) => {
            const templateTarget = templatePicker.dataset.target
                ? document.querySelector(templatePicker.dataset.target)
                : null;

            templatePicker.addEventListener('change', () => {
                const body = templatePicker.selectedOptions[0]?.dataset.body || '';

                if (! body || ! templateTarget) {
                    return;
                }

                templateTarget.value = body;
                templateTarget.focus();
            });
        });
    </script>
</x-layouts.app>
