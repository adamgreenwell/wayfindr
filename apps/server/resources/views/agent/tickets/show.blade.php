<x-layouts.app title="Ticket - {{ $ticket->subject }}" :agent="$agent" :account="$account">
            <a class="text-link" href="{{ $ticketReturnLink['href'] }}">{{ $ticketReturnLink['label'] }}</a>
            <h1>{{ $ticket->subject }}</h1>
            <p class="lede">Ticket #{{ $ticket->id }}</p>

            @if (session('status'))
                <p class="status-message">{{ session('status') }}</p>
            @endif

            @php
                $ticketActivityPreview = $ticket->queueActivityPreview();
                $ticketTiming = $ticket->queueTimingContext();
                $ticketNextAction = $ticket->nextAction();
                $ticketReplyVisibility = $ticket->replyVisibility();
                $ticketStatusActionReadiness = $ticket->statusActionReadiness();
                $ticketLifecycleNote = $ticket->latestLifecycleNote();
                $requesterReference = $ticket->requester?->email
                    ?? $ticket->requester?->name
                    ?? $ticket->requester?->anonymous_id
                    ?? 'Not linked';
            @endphp
            <section class="section" aria-labelledby="ticket-work-state-heading">
                <div class="section-header">
                    <h2 id="ticket-work-state-heading">Work state</h2>
                    <span class="lede">{{ $ticket->attentionLabel() }}</span>
                </div>

                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="meta-label">Status</span>
                        <span class="meta-value">{{ ucfirst($ticket->status) }}</span>
                        <span class="lede">{{ $ticket->attentionDescription() }}</span>
                    </div>
                    @if ($ticketLifecycleNote)
                        <div class="meta-item">
                            <span class="meta-label">Lifecycle note</span>
                            <span class="meta-value">{{ $ticketLifecycleNote['label'] }}</span>
                            <span class="lede">{{ $ticketLifecycleNote['body'] }}</span>
                            <span class="table-note">
                                {{ $ticketLifecycleNote['actor'] }} - {{ $ticketLifecycleNote['occurred_at']->diffForHumans() }}
                            </span>
                        </div>
                    @endif
                    <div class="meta-item">
                        <span class="meta-label">Owner</span>
                        <span class="meta-value">{{ $ticket->assignee?->name ?? 'Unassigned' }}</span>
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
                        <a class="button secondary health-action" href="{{ $ticketNextAction['href'] }}">
                            {{ $ticketNextAction['cta'] }}
                        </a>
                    </div>
                </div>
            </section>

            <section class="section" aria-labelledby="ticket-artifacts-heading">
                <div class="section-header">
                    <h2 id="ticket-artifacts-heading">Support artifacts</h2>
                    <span class="lede">Ticket coverage</span>
                </div>

                <div class="meta-grid">
                    @foreach ($ticketArtifactCoverage as $artifact)
                        <div class="meta-item">
                            <span class="meta-label">{{ $artifact['label'] }}</span>
                            <span class="meta-value">{{ $artifact['value'] }}</span>
                            <span class="readiness-status" data-status="{{ $artifact['tone'] }}">{{ $artifact['tone'] === 'ready' ? 'Present' : 'Optional' }}</span>
                            <span class="lede">{{ $artifact['description'] }}</span>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="section" aria-labelledby="ticket-reference-heading">
                <div class="section-header">
                    <h2 id="ticket-reference-heading">Support reference</h2>
                    <div class="section-actions">
                        <span class="lede">Use these details when searching, handoffs, or follow-up need a stable anchor.</span>
                        @if ($ticket->requester)
                            <a class="button secondary" href="{{ route('dashboard.visitors.show', $ticket->requester) }}">Open visitor profile</a>
                        @endif
                    </div>
                </div>

                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="meta-label">Ticket reference</span>
                        <span class="meta-value">Ticket #{{ $ticket->id }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Support code</span>
                        <span class="meta-value">
                            @if ($ticket->conversation)
                                <x-support-code-reference
                                    :code="$ticket->conversation->support_code"
                                    :href="route('dashboard.conversations.show', $ticket->conversation->support_code)"
                                />
                            @else
                                No linked conversation
                            @endif
                        </span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Site</span>
                        <span class="meta-value">{{ $ticket->site->name }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Requester</span>
                        <span class="meta-value">{{ $requesterReference }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Latest visitor page</span>
                        <span class="meta-value">
                            @if ($visitorContext['last_page_url'])
                                <a class="text-link" href="{{ $visitorContext['last_page_url'] }}" target="_blank" rel="noreferrer">
                                    {{ $visitorContext['last_page_url'] }}
                                </a>
                            @else
                                Not reported
                            @endif
                        </span>
                    </div>
                </div>
            </section>

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
                        <span class="meta-label">Labels</span>
                        <span class="meta-value">
                            @if ($ticket->labels->isEmpty())
                                None
                            @else
                                <span class="ticket-label-list">
                                    @foreach ($ticket->labels as $label)
                                        <x-ticket-label-chip :label="$label" :ticket-status="$ticket->status" />
                                    @endforeach
                                </span>
                            @endif
                        </span>
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

            @if ($latestTicketEscalation)
                @php
                    $escalationActor = $latestTicketEscalation->actor?->name ?? 'An agent';
                    $escalationTarget = data_get($latestTicketEscalation->metadata, 'target_agent_name')
                        ?? data_get($latestTicketEscalation->metadata, 'new_assignee_name')
                        ?? $ticket->assignee?->name
                        ?? 'Unassigned';
                    $escalationReason = data_get($latestTicketEscalation->metadata, 'reason');
                @endphp
                <section class="section" aria-labelledby="ticket-escalation-heading">
                    <div class="section-header">
                        <h2 id="ticket-escalation-heading">Escalation</h2>
                        <span class="lede">{{ $ticket->escalationAudienceLabelFor($agent) }}</span>
                    </div>

                    <div class="notice-copy">
                        <p><strong>{{ $escalationActor }} escalated this ticket to {{ $escalationTarget }}</strong></p>
                        @if ($escalationReason)
                            <p>{{ $escalationReason }}</p>
                        @endif
                    </div>
                </section>
            @endif

            <section class="section" aria-labelledby="ticket-labels-heading">
                <div class="section-header">
                    <h2 id="ticket-labels-heading">Labels</h2>
                    <span class="lede">{{ $ticket->labels->count() }} total</span>
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
                                    <button class="button secondary" type="submit">Remove label</button>
                                </form>
                            </div>
                        </article>
                    @empty
                        <div class="empty-state">No labels yet.</div>
                    @endforelse
                </div>

                <form class="section-form" method="POST" action="{{ route('dashboard.tickets.labels.store', $ticket) }}">
                    @csrf
                    @include('agent.tickets.partials.return-query-fields')

                    <div class="field">
                        <label for="label_name">Add label</label>
                        <input id="label_name" name="label_name" type="text" value="{{ old('label_name') }}" list="ticket-label-options" placeholder="needs-dev, vip, wordpress">
                        <datalist id="ticket-label-options">
                            @foreach ($ticketLabelOptions as $labelOption)
                                <option value="{{ $labelOption->name }}"></option>
                            @endforeach
                        </datalist>
                        @error('label_name')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <button class="button" type="submit">Add label</button>
                </form>
            </section>

            <section class="section" aria-labelledby="ticket-timeline-heading">
                <div class="section-header">
                    <h2 id="ticket-timeline-heading">Timeline</h2>
                    <span class="lede">
                        @if ($ticketTimelineFilter === 'all')
                            {{ $ticketTimelineTotalCount }} events
                        @else
                            {{ $ticketTimeline->count() }} of {{ $ticketTimelineTotalCount }} events
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

                <div class="filter-summary" aria-label="Timeline filters">
                    <div>
                        <strong>Timeline visibility</strong>
                        <p class="lede">Narrow the ticket history without hiding the full summary above.</p>
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
                        <div class="empty-state">{{ $ticketTimelineEmptyMessage }}</div>
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

                    @include('agent.conversations.partials.message-list', [
                        'emptyMessage' => 'No conversation messages yet.',
                        'transcriptMessages' => $linkedConversationMessages,
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
                            data-submitting-label="Sending visitor reply..."
                        >
                            @csrf
                            @include('agent.tickets.partials.return-query-fields')

                            <div class="field">
                                <label for="reply_template">Reply helper</label>
                                <select id="reply_template" name="reply_template" data-reply-template data-template-picker data-target="#message">
                                    <option value="">Write a custom reply</option>
                                    @foreach ($replyTemplates as $replyTemplateKey => $replyTemplate)
                                        <option
                                            value="{{ $replyTemplateKey }}"
                                            data-body="{{ $replyTemplate['body'] }}"
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
                                <label for="message">Visitor reply</label>
                                <textarea
                                    id="message"
                                    name="message"
                                    rows="4"
                                    placeholder="Send a reply to the visitor."
                                    aria-describedby="ticket-reply-shortcut-help"
                                    data-reply-body
                                    data-shortcut-submit
                                >{{ old('message') }}</textarea>
                                <p id="ticket-reply-shortcut-help" class="sr-only">Command or Control plus Enter sends this visitor reply.</p>
                                @error('message')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <p class="sr-only" data-reply-status aria-live="polite"></p>

                            <button class="button" type="submit" data-reply-submit>Send visitor reply</button>
                        </form>

                        <aside class="reply-assist" aria-labelledby="ticket-reply-assist-heading">
                            <h3 id="ticket-reply-assist-heading">Reply assist</h3>

                            <div class="reply-template-preview" data-template-preview>
                                <div data-template-preview-empty @if ($selectedReplyTemplate !== '') hidden @endif>
                                    <strong>No helper selected</strong>
                                    <p class="lede">Custom ticket replies stay fully agent-written.</p>
                                </div>

                                @foreach ($replyTemplates as $replyTemplateKey => $replyTemplate)
                                    <article data-template-preview-item="{{ $replyTemplateKey }}" @if ($selectedReplyTemplate !== $replyTemplateKey) hidden @endif>
                                        <strong>{{ $replyTemplate['label'] }}</strong>
                                        <p>{{ $replyTemplate['body'] }}</p>
                                    </article>
                                @endforeach
                            </div>

                            <div class="notice-list">
                                <p>Keep sensitive details out of visitor replies unless the visitor supplied them here.</p>
                                <p>Use ticket replies when the customer should see the update; use internal notes for private handoff context.</p>
                            </div>
                        </aside>
                    </div>
                </section>
            @endif

            <section class="section" aria-labelledby="external-links-heading">
                <div class="section-header">
                    <h2 id="external-links-heading">External links</h2>
                    <span class="lede">{{ $ticketExternalIssueHealth['total'] }} total</span>
                </div>

                <div id="ticket-external-issue-health" aria-labelledby="ticket-external-issue-health-heading">
                    <div class="section-header">
                        <h2 id="ticket-external-issue-health-heading">External issue health</h2>
                        <span class="readiness-status" data-status="{{ $ticketExternalIssueHealth['tone'] }}">{{ $ticketExternalIssueHealth['label'] }}</span>
                    </div>

                    <div class="meta-grid">
                        @foreach ($ticketExternalIssueHealth['status_counts'] as $statusCount)
                            <div class="meta-item">
                                <span class="meta-label">{{ $statusCount['label'] }}</span>
                                <span class="meta-value">{{ $statusCount['count'] }} {{ strtolower($statusCount['label']) }}</span>
                            </div>
                        @endforeach
                        <div class="meta-item">
                            <span class="meta-label">Last external attempt</span>
                            <span class="meta-value">{{ $ticketExternalIssueHealth['latest_attempt']['label'] }}</span>
                            <span class="lede">{{ $ticketExternalIssueHealth['latest_attempt']['body'] }}</span>
                            @if ($ticketExternalIssueHealth['latest_attempt']['occurred_at'])
                                <span class="table-note">{{ $ticketExternalIssueHealth['latest_attempt']['occurred_at']->diffForHumans() }}</span>
                            @endif
                        </div>
                    </div>

                    @if ($ticketExternalIssueHealth['total'] === 0 && $ticketExternalIssueHealth['failures']->isEmpty())
                        <p class="empty">No external issues linked to this ticket yet.</p>
                    @elseif ($ticketExternalIssueHealth['failures']->isEmpty())
                        <p class="empty">External issue links are not reporting failures for this ticket.</p>
                    @else
                        <div class="timeline-list">
                            @foreach ($ticketExternalIssueHealth['failures'] as $failure)
                                <article class="timeline-item internal-note">
                                    <div class="timeline-content">
                                        <strong>{{ $loop->first ? 'Last failure' : 'Earlier failure' }}</strong>
                                        <p class="message-body">{{ $failure['provider'] }} could not sync {{ $failure['project_key'] }}.</p>
                                        <div class="timeline-meta">
                                            @if ($failure['occurred_at'])
                                                <span>{{ $failure['occurred_at']->diffForHumans() }}</span>
                                            @endif
                                            <span>Provider details withheld</span>
                                        </div>
                                        @if ($failure['retry'])
                                            <form class="compact-form external-issue-retry-form" method="POST" action="{{ $failure['retry']['route'] }}">
                                                @csrf
                                                <input type="hidden" name="site_external_issue_project_id" value="{{ $failure['retry']['site_external_issue_project_id'] }}">
                                                <button class="button secondary" type="submit">{{ $failure['retry']['label'] }}</button>
                                                <span class="lede">Retry uses the current site project mapping and the conservative export payload.</span>
                                            </form>
                                        @else
                                            <p class="lede">
                                                <strong>Retry unavailable</strong><br>
                                                Check the site external issue settings before retrying.
                                            </p>
                                        @endif
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </div>

                @if ($githubIssueProjects->isNotEmpty() || $gitlabIssueProjects->isNotEmpty())
                    <div class="section-form">
                        <strong>External issue actions</strong>
                        <p class="lede">Create a conservative external issue from this ticket without exporting transcripts, cobrowse snapshots, or internal notes.</p>

                        @error('external_issue')
                            <p class="field-error">{{ $message }}</p>
                        @enderror

                        <div class="external-issue-export-preview" data-external-issue-export-preview>
                            <div class="notice-copy notice-copy-bordered">
                                <p><strong>External issue export preview</strong></p>
                                <p>Review the scoped payload before sending this ticket to another tracker.</p>
                            </div>

                            <div class="meta-grid">
                                <div class="meta-item">
                                    <span class="meta-label">Issue title</span>
                                    <span class="meta-value">{{ $externalIssueExportPreview['title'] }}</span>
                                </div>
                                <div class="meta-item">
                                    <span class="meta-label">Data boundary</span>
                                    <span class="meta-value">Safe summary only</span>
                                    <span class="lede">No raw transcripts, cobrowse snapshots, or internal notes by default.</span>
                                </div>
                            </div>

                            <div class="section-header">
                                <strong>Summary sent to external trackers</strong>
                                <span class="lede">Provider credentials and raw errors stay out of this payload.</span>
                            </div>
                            <pre class="code-block"><code>{{ $externalIssueExportPreview['body'] }}</code></pre>
                        </div>

                        @foreach ($githubIssueProjects as $githubIssueProject)
                            <form method="POST" action="{{ route('dashboard.tickets.external-issues.github.store', $ticket) }}">
                                @csrf
                                <input type="hidden" name="site_external_issue_project_id" value="{{ $githubIssueProject->id }}">
                                <button class="button" type="submit">Create GitHub issue</button>
                                <span class="lede">{{ $githubIssueProject->project_key }}</span>
                            </form>
                        @endforeach

                        @foreach ($gitlabIssueProjects as $gitlabIssueProject)
                            <form method="POST" action="{{ route('dashboard.tickets.external-issues.gitlab.store', $ticket) }}">
                                @csrf
                                <input type="hidden" name="site_external_issue_project_id" value="{{ $gitlabIssueProject->id }}">
                                <button class="button" type="submit">Create GitLab issue</button>
                                <span class="lede">{{ $gitlabIssueProject->project_key }}</span>
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

            @php
                $priorSupportRecordCount = $priorVisitorConversations->count() + $priorVisitorTickets->count();
            @endphp
            @if ($visitorContext['has_visitor'] || $visitorContext['last_page_url'] || $visitorContext['started_page_url'] || $visitorContext['host_context'] !== [])
                <section class="section" aria-labelledby="ticket-visitor-context-heading">
                    <div class="section-header">
                        <h2 id="ticket-visitor-context-heading">Visitor at a glance</h2>
                        <span class="lede">Safe context only</span>
                    </div>

                    <div class="meta-grid">
                        <div class="meta-item">
                            <span class="meta-label">Visitor</span>
                            <span class="meta-value">{{ $visitorContext['anonymous_id'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Host visitor ID</span>
                            <span class="meta-value">{{ $visitorContext['external_id'] ?? 'Not provided' }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Last seen</span>
                            <span class="meta-value">{{ $visitorContext['last_seen_at']?->diffForHumans() ?? 'Not reported' }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Latest page</span>
                            <span class="meta-value">{{ $visitorContext['last_page_url'] ?? 'Not reported' }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Entry page</span>
                            <span class="meta-value">{{ $visitorContext['started_page_url'] ?? 'Not reported' }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">History on this site</span>
                            <span class="meta-value">{{ $priorSupportRecordCount }} {{ \Illuminate\Support\Str::plural('record', $priorSupportRecordCount) }}</span>
                        </div>
                    </div>

                    <div class="notice-copy notice-copy-bordered">
                        <p><strong>Data boundary</strong></p>
                        <p>Use this context to answer the current request. Do not collect, export, or infer extra visitor data without consent.</p>
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

                    <div class="section-header">
                        <strong>Prior support records</strong>
                        <span class="lede">{{ $priorSupportRecordCount }} previous</span>
                    </div>

                    @if ($priorVisitorConversations->isEmpty() && $priorVisitorTickets->isEmpty())
                        <p class="empty">No prior support records for this visitor on this site.</p>
                    @else
                        <div class="timeline-list">
                            @foreach ($priorVisitorConversations as $priorConversation)
                                <article class="timeline-item">
                                    <div class="timeline-content">
                                        <a class="text-link" href="{{ route('dashboard.conversations.show', $priorConversation->support_code) }}">
                                            {{ $priorConversation->subject ?? 'Untitled conversation' }}
                                        </a>
                                        <div class="timeline-meta">
                                            <span>{{ $priorConversation->support_code }}</span>
                                            <span>{{ ucfirst($priorConversation->status) }}</span>
                                            <span>Owner: {{ $priorConversation->assignedAgent?->name ?? 'Unassigned' }}</span>
                                            <span>Last activity: {{ $priorConversation->last_message_at?->diffForHumans() ?? $priorConversation->created_at->diffForHumans() }}</span>
                                        </div>
                                    </div>
                                </article>
                            @endforeach

                            @foreach ($priorVisitorTickets as $priorTicket)
                                <article class="timeline-item">
                                    <div class="timeline-content">
                                        <a class="text-link" href="{{ route('dashboard.tickets.show', $priorTicket) }}">
                                            {{ $priorTicket->subject }}
                                        </a>
                                        <div class="timeline-meta">
                                            <span>Ticket #{{ $priorTicket->id }}</span>
                                            <span>{{ ucfirst($priorTicket->status) }}</span>
                                            <span>Owner: {{ $priorTicket->assignee?->name ?? 'Unassigned' }}</span>
                                            @if ($priorTicket->conversation)
                                                <a class="text-link" href="{{ route('dashboard.conversations.show', $priorTicket->conversation->support_code) }}">
                                                    {{ $priorTicket->conversation->support_code }}
                                                </a>
                                            @else
                                                <span>No linked conversation</span>
                                            @endif
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif

            <section class="section" aria-labelledby="ticket-actions-heading">
                <div class="section-header">
                    <h2 id="ticket-actions-heading">Actions</h2>
                    <span class="lede">{{ $ticket->assignee?->name ?? 'Unassigned' }}</span>
                </div>

                <div class="notice-copy notice-copy-bordered">
                    <p>
                        <strong>Status action readiness</strong>
                        <span class="readiness-status" data-status="{{ $ticketStatusActionReadiness['tone'] }}">
                            {{ $ticketStatusActionReadiness['title'] }}
                        </span>
                    </p>
                    <p>{{ $ticketStatusActionReadiness['detail'] }}</p>
                    <div class="notice-actions">
                        <a class="button secondary" href="{{ $ticketStatusActionReadiness['href'] }}">
                            {{ $ticketStatusActionReadiness['cta'] }}
                        </a>
                    </div>
                </div>

                @php
                    $escalationAgents = $accountAgents->reject(fn ($accountAgent) => $accountAgent->is($agent))->values();
                @endphp

                <form class="section-form" method="POST" action="{{ route('dashboard.tickets.assignee.update', $ticket) }}">
                    @csrf
                    @method('PUT')
                    @include('agent.tickets.partials.return-query-fields')

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

                <div class="section-form">
                    <strong>Escalate ticket</strong>
                    <p class="lede">Send a deliberate handoff to another agent who can support this site.</p>

                    @if ($escalationAgents->isEmpty())
                        <p class="empty">No other site agents are available for escalation.</p>
                    @else
                        <form method="POST" action="{{ route('dashboard.tickets.escalations.store', $ticket) }}">
                            @csrf
                            @include('agent.tickets.partials.return-query-fields')

                            <div class="field">
                                <label for="target_agent_id">Escalate to</label>
                                <select id="target_agent_id" name="target_agent_id">
                                    <option value="">Choose an agent</option>
                                    @foreach ($escalationAgents as $escalationAgent)
                                        <option value="{{ $escalationAgent->id }}" @selected((int) old('target_agent_id') === $escalationAgent->id)>
                                            {{ $escalationAgent->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('target_agent_id')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="field">
                                <label for="escalation_reason">Reason</label>
                                <textarea id="escalation_reason" name="reason" rows="3" placeholder="Why does this need another set of eyes?">{{ old('reason') }}</textarea>
                                @error('reason')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <button class="button" type="submit">Escalate ticket</button>
                        </form>
                    @endif
                </div>

                @if ($ticket->status === 'open')
                    <form class="section-form" method="POST" action="{{ route('dashboard.tickets.pending', $ticket) }}">
                        @csrf
                        @include('agent.tickets.partials.return-query-fields')
                        <div class="field">
                            <label for="pending_note">Pending note</label>
                            <textarea id="pending_note" name="pending_note" rows="3" placeholder="What are we waiting on from the customer?">{{ old('pending_note') }}</textarea>
                            @error('pending_note')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <button class="button secondary" type="submit">Mark pending</button>
                    </form>
                @endif

                @if (in_array($ticket->status, ['closed', 'pending'], true))
                    <form class="section-form" method="POST" action="{{ route('dashboard.tickets.reopen', $ticket) }}">
                        @csrf
                        @include('agent.tickets.partials.return-query-fields')
                        <div class="field">
                            <label for="reopen_note">Reopen note</label>
                            <textarea id="reopen_note" name="reopen_note" rows="3" placeholder="What changed or why does this need attention again?">{{ old('reopen_note') }}</textarea>
                            @error('reopen_note')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <button class="button secondary" type="submit">Reopen ticket</button>
                    </form>
                @endif

                @if ($ticket->status !== 'closed')
                    <form class="section-form" method="POST" action="{{ route('dashboard.tickets.close', $ticket) }}">
                        @csrf
                        @include('agent.tickets.partials.return-query-fields')
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
                    @include('agent.tickets.partials.return-query-fields')

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

                @php
                    $oldNoteTemplate = old('note_template', '');
                    $selectedNoteTemplate = is_string($oldNoteTemplate) ? $oldNoteTemplate : '';
                @endphp

                <div class="reply-workspace" data-reply-shell>
                    <form class="section-form" method="POST" action="{{ route('dashboard.tickets.notes.store', $ticket) }}">
                        @csrf
                        @include('agent.tickets.partials.return-query-fields')

                        <div class="field">
                            <label for="note_template">Note helper</label>
                            <select id="note_template" name="note_template" data-template-picker data-target="#body">
                                <option value="">Write a custom note</option>
                                @foreach ($noteTemplates as $noteTemplateKey => $noteTemplate)
                                    <option
                                        value="{{ $noteTemplateKey }}"
                                        data-body="{{ $noteTemplate['body'] }}"
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
                            <label for="body">Add internal note</label>
                            <textarea id="body" name="body" rows="4" placeholder="Document follow-up, escalation context, or handoff details.">{{ old('body') }}</textarea>
                            @error('body')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <button class="button" type="submit">Add note</button>
                    </form>

                    <aside class="reply-assist" aria-labelledby="ticket-note-assist-heading">
                        <h3 id="ticket-note-assist-heading">Note assist</h3>

                        <div class="reply-template-preview" data-template-preview>
                            <div data-template-preview-empty @if ($selectedNoteTemplate !== '') hidden @endif>
                                <strong>No note helper selected</strong>
                                <p class="lede">Custom notes stay fully agent-written.</p>
                            </div>

                            @foreach ($noteTemplates as $noteTemplateKey => $noteTemplate)
                                <article data-template-preview-item="{{ $noteTemplateKey }}" @if ($selectedNoteTemplate !== $noteTemplateKey) hidden @endif>
                                    <strong>{{ $noteTemplate['label'] }}</strong>
                                    <p>{{ $noteTemplate['body'] }}</p>
                                </article>
                            @endforeach
                        </div>

                        <div class="notice-list">
                            <p>Internal notes are private handoff context for your team, not visitor replies.</p>
                            <p>Avoid storing sensitive details unless they are necessary for support continuity.</p>
                        </div>
                    </aside>
                </div>
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
                                        {{ \App\Support\ExternalIssueProvider::label(data_get($activity->metadata, 'provider')) }} issue created
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

                                    @case('ticket.escalated')
                                        Ticket escalated from {{ data_get($activity->metadata, 'old_assignee_name') ?? 'Unassigned' }} to {{ data_get($activity->metadata, 'target_agent_name') ?? data_get($activity->metadata, 'new_assignee_name') ?? 'Unassigned' }}
                                        @break

                                    @case('ticket.label_added')
                                        Label added: {{ data_get($activity->metadata, 'label_name') }}
                                        @break

                                    @case('ticket.label_removed')
                                        Label removed: {{ data_get($activity->metadata, 'label_name') }}
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
                            @php
                                $activityBody = data_get($activity->metadata, 'resolution_note')
                                    ?? data_get($activity->metadata, 'pending_note')
                                    ?? data_get($activity->metadata, 'reopen_note')
                                    ?? data_get($activity->metadata, 'reason');
                            @endphp
                            @if ($activityBody)
                                <p class="message-body">{{ $activityBody }}</p>
                            @endif
                        </article>
                    @empty
                        <div class="empty-state">No ticket activity yet.</div>
                    @endforelse
                </div>
            </section>
    @include('agent.partials.reply-composer-script')
</x-layouts.app>
