<x-layouts.app title="Conversation {{ $conversation->support_code }}" :agent="$agent" :account="$account">
            <a class="text-link" href="{{ route('dashboard') }}">Back to dashboard</a>
            <h1>{{ $conversation->subject ?? 'Untitled conversation' }}</h1>
            <p class="lede">Support code {{ $conversation->support_code }}</p>

            @if (session('status'))
                <p class="status-message">{{ session('status') }}</p>
            @endif

            <section class="section" aria-labelledby="conversation-context-heading">
                <div class="section-header">
                    <h2 id="conversation-context-heading">Context</h2>
                    <span class="lede">{{ ucfirst($conversation->status) }}</span>
                </div>

                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="meta-label">Site</span>
                        <span class="meta-value">{{ $conversation->site->name }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Visitor</span>
                        <span class="meta-value">{{ $conversation->visitor->anonymous_id ?? 'Unknown visitor' }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Assigned to</span>
                        <span class="meta-value">{{ $conversation->assignedAgent?->name ?? 'Unassigned' }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Attention</span>
                        <span class="meta-value">{{ $conversation->attentionLabel() }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Opened</span>
                        <span class="meta-value">{{ $conversation->created_at->diffForHumans() }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Last Activity</span>
                        <span class="meta-value">{{ $conversation->last_message_at?->diffForHumans() ?? 'No messages yet' }}</span>
                    </div>
                </div>

                @if (! $conversation->assigned_agent_id)
                    <form class="section-form" method="POST" action="{{ route('dashboard.conversations.claim', $conversation->support_code) }}">
                        @csrf

                        <button class="button" type="submit">Claim conversation</button>
                    </form>
                @elseif ($conversation->assigned_agent_id === $agent->id)
                    <form class="section-form" method="POST" action="{{ route('dashboard.conversations.release', $conversation->support_code) }}">
                        @csrf

                        <button class="button secondary" type="submit">Release conversation</button>
                    </form>
                @endif

                <form class="section-form" method="POST" action="{{ route($conversation->status === 'closed' ? 'dashboard.conversations.reopen' : 'dashboard.conversations.close', $conversation->support_code) }}">
                    @csrf

                    <button class="button {{ $conversation->status === 'closed' ? '' : 'secondary' }}" type="submit">
                        {{ $conversation->status === 'closed' ? 'Reopen conversation' : 'Close conversation' }}
                    </button>
                </form>
            </section>

            <section class="section" aria-labelledby="visitor-context-heading">
                <div class="section-header">
                    <h2 id="visitor-context-heading">Visitor at a glance</h2>
                    <div class="section-actions">
                        <span class="lede">Safe context only</span>
                        @if ($conversation->visitor)
                            <a class="button secondary" href="{{ route('dashboard.visitors.show', $conversation->visitor) }}">Open visitor profile</a>
                        @endif
                    </div>
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
                        <span class="meta-label">Presence</span>
                        <span class="readiness-status" data-status="{{ in_array($visitorContext['presence']['state'], ['active', 'recent'], true) ? 'ready' : 'manual' }}">
                            {{ $visitorContext['presence']['label'] }}
                        </span>
                        <span class="lede">{{ $visitorContext['presence']['detail'] }}</span>
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
                        <span class="meta-value">{{ $priorConversations->count() }} previous</span>
                    </div>
                </div>

                <div class="section-header">
                    <strong>Support references</strong>
                    <span class="lede">Current and same-visitor records</span>
                </div>

                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="meta-label">Current support code</span>
                        <span class="meta-value">
                            <a class="text-link" href="{{ route('dashboard.support-code.lookup', ['support_code' => $conversation->support_code]) }}">
                                {{ $conversation->support_code }}
                            </a>
                        </span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Visitor reference</span>
                        <span class="meta-value">{{ $visitorContext['external_id'] ?? $visitorContext['anonymous_id'] }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Same visitor support codes</span>
                        <span class="meta-value">{{ $priorConversations->count() }} previous</span>
                        @if ($priorConversations->isEmpty())
                            <div class="notice-list">
                                <p>No previous support codes yet.</p>
                            </div>
                        @else
                            <div class="notice-list">
                                @foreach ($priorConversations as $priorConversation)
                                    <p>
                                        <a class="text-link" href="{{ route('dashboard.conversations.show', $priorConversation->support_code) }}">
                                            {{ $priorConversation->support_code }}
                                        </a>
                                        <span class="lede">{{ $priorConversation->subject ?? 'Untitled conversation' }}</span>
                                    </p>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Reference note</span>
                        <span class="meta-value">Use these references when the visitor or another agent needs to find this support trail again.</span>
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
                    <p class="empty">No host-provided context yet.</p>
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
                    <strong>Prior conversations</strong>
                    <span class="lede">{{ $priorConversations->count() }} previous</span>
                </div>

                @if ($priorConversations->isEmpty())
                    <p class="empty">No prior conversations for this visitor on this site.</p>
                @else
                    <div class="timeline-list">
                        @foreach ($priorConversations as $priorConversation)
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
                                    <div class="timeline-meta">
                                        <strong>Linked ticket</strong>
                                        @forelse ($priorConversation->tickets as $ticket)
                                            <a class="text-link" href="{{ route('dashboard.tickets.show', $ticket) }}">
                                                {{ $ticket->subject }}
                                            </a>
                                            <span>{{ ucfirst($ticket->status) }}</span>
                                        @empty
                                            <span>No ticket</span>
                                        @endforelse
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="section" aria-labelledby="tickets-heading">
                <div class="section-header">
                    <h2 id="tickets-heading">Ticket</h2>
                    <span class="lede">{{ $tickets->isEmpty() ? 'Not created' : $tickets->count().' linked' }}</span>
                </div>

                @if ($tickets->isEmpty())
                    <form class="section-form" method="POST" action="{{ route('dashboard.conversations.tickets.store', $conversation->support_code) }}">
                        @csrf

                        <div class="field">
                            <label for="category">Category</label>
                            <select id="category" name="category">
                                <option value="">Uncategorized</option>
                                @foreach ($ticketCategories as $value => $category)
                                    <option value="{{ $value }}" @selected(old('category') === $value)>{{ $category['label'] }}</option>
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
                                    <option value="{{ $value }}" @selected(old('priority', 'normal') === $value)>{{ $priority['label'] }}</option>
                                @endforeach
                            </select>
                            <x-ticket-priority-guidance :priorities="$ticketPriorityGuidance" />
                            @error('priority')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <button class="button" type="submit">Create ticket</button>
                    </form>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">Subject</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Category</th>
                                    <th scope="col">Priority</th>
                                    <th scope="col">Assignee</th>
                                    <th scope="col">Created</th>
                                    <th scope="col">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($tickets as $ticket)
                                    <tr>
                                        <td>
                                            <a class="text-link" href="{{ route('dashboard.tickets.show', $ticket) }}">
                                                {{ $ticket->subject }}
                                            </a>
                                        </td>
                                        <td>{{ ucfirst($ticket->status) }}</td>
                                        <td>{{ $ticket->categoryLabel() }}</td>
                                        <td>{{ ucfirst($ticket->priority) }}</td>
                                        <td>
                                            <form method="POST" action="{{ route('dashboard.tickets.assignee.update', $ticket) }}">
                                                @csrf
                                                @method('PUT')
                                                <label class="sr-only" for="ticket_{{ $ticket->id }}_assignee">Assign ticket</label>
                                                <select id="ticket_{{ $ticket->id }}_assignee" name="assignee_id">
                                                    <option value="">Unassigned</option>
                                                    @foreach ($accountAgents as $accountAgent)
                                                        <option value="{{ $accountAgent->id }}" @selected((int) $ticket->assignee_id === $accountAgent->id)>
                                                            {{ $accountAgent->name }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                                <button class="button secondary" type="submit">Assign ticket</button>
                                            </form>
                                            @error('assignee_id')
                                                <p class="field-error">{{ $message }}</p>
                                            @enderror
                                        </td>
                                        <td>{{ $ticket->created_at->diffForHumans() }}</td>
                                        <td>
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

                                            @if ($ticket->status !== 'closed')
                                                <form method="POST" action="{{ route('dashboard.tickets.close', $ticket) }}">
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
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="section" aria-labelledby="cobrowse-heading">
                <div class="section-header">
                    <h2 id="cobrowse-heading">Cobrowse</h2>
                    <span class="lede">{{ $cobrowseConsent['label'] }}</span>
                </div>

                <p class="empty">{{ $cobrowseConsent['message'] }}</p>

                @if ($cobrowseConsent['lifecycle'])
                    <div class="section-header">
                        <strong>Session timeline</strong>
                    </div>

                    <div class="meta-grid realtime-grid">
                        <div class="meta-item">
                            <span class="meta-label">Requested by</span>
                            <span class="meta-value">{{ $cobrowseConsent['lifecycle']['requested_by'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Requested</span>
                            <span class="meta-value">{{ $cobrowseConsent['lifecycle']['requested_at'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Consent granted</span>
                            <span class="meta-value">{{ $cobrowseConsent['lifecycle']['consented_at'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Stopped</span>
                            <span class="meta-value">{{ $cobrowseConsent['lifecycle']['ended_at'] }}</span>
                        </div>
                        @if ($cobrowseConsent['lifecycle']['ended_at'] !== 'Still active')
                            <div class="meta-item">
                                <span class="meta-label">Stopped by</span>
                                <span class="meta-value">{{ $cobrowseConsent['lifecycle']['ended_by'] }}</span>
                            </div>
                        @endif
                    </div>
                @endif

                @if (in_array($cobrowseConsent['status'], ['unavailable', 'revoked', 'ended'], true))
                    <form class="section-form" method="POST" action="{{ route('dashboard.conversations.cobrowse.request', $conversation->support_code) }}">
                        @csrf
                        <button class="button" type="submit">Request cobrowse</button>
                    </form>
                @elseif (in_array($cobrowseConsent['status'], ['pending', 'granted'], true))
                    <form class="section-form" method="POST" action="{{ route('dashboard.conversations.cobrowse.end', $conversation->support_code) }}">
                        @csrf
                        <button class="button secondary" type="submit">
                            {{ $cobrowseConsent['status'] === 'pending' ? 'Cancel request' : 'End cobrowse' }}
                        </button>
                    </form>
                @endif

                @if ($cobrowseConsent['transport'])
                    <div class="section-header">
                        <strong>Transport health</strong>
                        <span class="lede">{{ $cobrowseConsent['transport']['label'] }}</span>
                    </div>

                    <p class="empty realtime-note">{{ $cobrowseConsent['transport']['message'] }}</p>

                    <div class="meta-grid realtime-grid">
                        <div class="meta-item">
                            <span class="meta-label">State</span>
                            <span class="meta-value">{{ $cobrowseConsent['transport']['label'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Last report</span>
                            <span class="meta-value">{{ $cobrowseConsent['transport']['last_report'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Reconnects</span>
                            <span class="meta-value">{{ $cobrowseConsent['transport']['reconnects'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Pressure</span>
                            <span class="meta-value">{{ $cobrowseConsent['transport']['pressure'] }}</span>
                        </div>
                    </div>
                @endif

                @if ($realtime)
                    <div class="live-update" data-cobrowse-update-panel data-state="idle">
                        <div>
                            <strong>Cobrowse updates</strong>
                            <p class="lede" data-cobrowse-update-status>Waiting for live cobrowse updates.</p>
                        </div>
                        <button class="button secondary" type="button" data-cobrowse-refresh hidden>Refresh preview</button>
                    </div>
                @endif

                @if ($cobrowseConsent['page_state'])
                    <div class="section-header">
                        <strong>Visitor page</strong>
                    </div>

                    <div class="meta-grid realtime-grid">
                        <div class="meta-item">
                            <span class="meta-label">Title</span>
                            <span class="meta-value">{{ $cobrowseConsent['page_state']['title'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">URL</span>
                            <span class="meta-value">{{ $cobrowseConsent['page_state']['page_url'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Viewport</span>
                            <span class="meta-value">{{ $cobrowseConsent['page_state']['viewport'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Scroll</span>
                            <span class="meta-value">{{ $cobrowseConsent['page_state']['scroll'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Visibility</span>
                            <span class="meta-value">{{ $cobrowseConsent['page_state']['visibility_state'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Focus</span>
                            <span class="meta-value">{{ $cobrowseConsent['page_state']['focus'] }}</span>
                        </div>
                    </div>
                @else
                    <p class="empty realtime-note">No visitor page state yet.</p>
                @endif

                @if ($cobrowseConsent['snapshot'])
                    <div class="section-header">
                        <strong>Page snapshot</strong>
                    </div>

                    <div class="meta-grid realtime-grid">
                        <div class="meta-item">
                            <span class="meta-label">Title</span>
                            <span class="meta-value">{{ $cobrowseConsent['snapshot']['title'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">URL</span>
                            <span class="meta-value">{{ $cobrowseConsent['snapshot']['page_url'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Nodes</span>
                            <span class="meta-value">{{ $cobrowseConsent['snapshot']['node_count'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Masked</span>
                            <span class="meta-value">{{ $cobrowseConsent['snapshot']['masked_count'] }}</span>
                        </div>
                    </div>

                    <div class="message-list">
                        <article class="message">
                            <p class="message-body">{{ $cobrowseConsent['snapshot']['text'] }}</p>
                        </article>
                    </div>
                @else
                    <p class="empty realtime-note">No sanitized page snapshot yet.</p>
                @endif

                @if ($cobrowseConsent['mutation_stream'])
                    <div class="section-header">
                        <strong>Mutation stream</strong>
                    </div>

                    <div class="meta-grid realtime-grid">
                        <div class="meta-item">
                            <span class="meta-label">Batches</span>
                            <span class="meta-value">{{ $cobrowseConsent['mutation_stream']['batch_count'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Mutations</span>
                            <span class="meta-value">{{ $cobrowseConsent['mutation_stream']['mutation_count'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Dropped</span>
                            <span class="meta-value">{{ $cobrowseConsent['mutation_stream']['dropped_count'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Skipped</span>
                            <span class="meta-value">{{ $cobrowseConsent['mutation_stream']['skipped_count'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Last sequence</span>
                            <span class="meta-value">{{ $cobrowseConsent['mutation_stream']['last_sequence'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">URL</span>
                            <span class="meta-value">{{ $cobrowseConsent['mutation_stream']['last_page_url'] }}</span>
                        </div>
                    </div>
                @else
                    <p class="empty realtime-note">No mutation stream diagnostics yet.</p>
                @endif

                @if ($cobrowseConsent['replay_preview'])
                    <div class="section-header">
                        <strong>Replay preview</strong>
                        <span class="lede">
                            {{ $cobrowseConsent['replay_preview']['applied_mutations'] }}
                            /
                            {{ $cobrowseConsent['replay_preview']['skipped_mutations'] }}
                        </span>
                    </div>

                    <div class="cobrowse-preview-frame">
                        <iframe
                            class="cobrowse-preview"
                            title="Cobrowse replay preview"
                            sandbox
                            srcdoc="{{ $cobrowseConsent['replay_preview']['srcdoc'] }}"
                        ></iframe>
                    </div>
                @else
                    <p class="empty realtime-note">No replay preview yet.</p>
                @endif

                @if ($cobrowseConsent['payload_budget'])
                    <div class="section-header">
                        <strong>Payload budget</strong>
                    </div>

                    <div class="meta-grid realtime-grid">
                        <div class="meta-item">
                            <span class="meta-label">Snapshot HTML</span>
                            <span class="meta-value">{{ $cobrowseConsent['payload_budget']['snapshot_html'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Snapshot text</span>
                            <span class="meta-value">{{ $cobrowseConsent['payload_budget']['snapshot_text'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Mutation batch</span>
                            <span class="meta-value">{{ $cobrowseConsent['payload_budget']['mutation_batch'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Mutation text</span>
                            <span class="meta-value">{{ $cobrowseConsent['payload_budget']['mutation_text'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Mutation HTML</span>
                            <span class="meta-value">{{ $cobrowseConsent['payload_budget']['mutation_html'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Recent batches</span>
                            <span class="meta-value">{{ $cobrowseConsent['payload_budget']['recent_batches'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Telemetry payload</span>
                            <span class="meta-value">{{ $cobrowseConsent['payload_budget']['telemetry_payload'] }}</span>
                        </div>
                    </div>
                @endif

                @if ($cobrowseConsent['telemetry'])
                    <div class="section-header">
                        <strong>Connection telemetry</strong>
                    </div>

                    <div class="meta-grid realtime-grid">
                        <div class="meta-item">
                            <span class="meta-label">RTT</span>
                            <span class="meta-value">{{ $cobrowseConsent['telemetry']['rtt'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Max RTT</span>
                            <span class="meta-value">{{ $cobrowseConsent['telemetry']['max_rtt'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Payload</span>
                            <span class="meta-value">{{ $cobrowseConsent['telemetry']['payload'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Max payload</span>
                            <span class="meta-value">{{ $cobrowseConsent['telemetry']['max_payload'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Dropped batches</span>
                            <span class="meta-value">{{ $cobrowseConsent['telemetry']['dropped_batches'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Reconnects</span>
                            <span class="meta-value">{{ $cobrowseConsent['telemetry']['reconnects'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Samples</span>
                            <span class="meta-value">{{ $cobrowseConsent['telemetry']['samples'] }}</span>
                        </div>
                    </div>
                @else
                    <p class="empty realtime-note">No cobrowse connection telemetry yet.</p>
                @endif
            </section>

            <section class="section" aria-labelledby="messages-heading">
                <div class="section-header">
                    <h2 id="messages-heading">Messages</h2>
                    <span class="lede">{{ $messages->count() }} total</span>
                </div>

                @include('agent.conversations.partials.message-list', [
                    'emptyMessage' => 'No messages yet.',
                    'transcriptMessages' => $messages,
                ])
            </section>

            <section class="section" aria-labelledby="reply-heading">
                <div class="section-header">
                    <h2 id="reply-heading">Reply</h2>
                    <span class="lede">{{ $conversation->attentionLabel() }}</span>
                </div>

                @php
                    $selectedReplyTemplate = (string) old('reply_template', '');
                    $replyAssigneeLabel = 'Unassigned';

                    if ((int) $conversation->assigned_agent_id === $agent->id) {
                        $replyAssigneeLabel = 'Assigned to you';
                    } elseif ($conversation->assignedAgent) {
                        $replyAssigneeLabel = 'Assigned to '.$conversation->assignedAgent->name;
                    }
                @endphp

                <div class="reply-workspace" data-reply-shell>
                    <form
                        class="section-form reply-form"
                        method="POST"
                        action="{{ route('dashboard.conversations.messages.store', $conversation->support_code) }}"
                        data-reply-composer
                        data-submitting-label="Sending reply..."
                    >
                        @csrf

                        <div class="reply-context-strip" aria-label="Reply context">
                            <div class="reply-context-item">
                                <span class="meta-label">Reply context</span>
                                <span class="meta-value">{{ $conversation->attentionLabel() }}</span>
                            </div>
                            <div class="reply-context-item">
                                <span class="meta-label">Owner</span>
                                <span class="meta-value">{{ $replyAssigneeLabel }}</span>
                            </div>
                            <div class="reply-context-item">
                                <span class="meta-label">Support code</span>
                                <span class="meta-value">{{ $conversation->support_code }}</span>
                            </div>
                        </div>

                        <div class="field">
                            <label for="reply_template">Reply helper</label>
                            <select id="reply_template" name="reply_template" data-template-picker data-target="#body">
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
                            <label for="body">Message</label>
                            <textarea
                                id="body"
                                name="body"
                                rows="5"
                                placeholder="Write a clear, calm reply."
                                aria-describedby="reply-shortcut-help"
                                data-reply-body
                                data-shortcut-submit
                            >{{ old('body') }}</textarea>
                            <p id="reply-shortcut-help" class="sr-only">Command or Control plus Enter sends this reply.</p>
                            @error('body')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <p class="sr-only" data-reply-status aria-live="polite"></p>

                        <button class="button" type="submit" data-reply-submit>Send reply</button>
                    </form>

                    <aside class="reply-assist" aria-labelledby="reply-assist-heading">
                        <h3 id="reply-assist-heading">Reply assist</h3>

                        <div class="reply-template-preview" data-template-preview>
                            <div data-template-preview-empty @if ($selectedReplyTemplate !== '') hidden @endif>
                                <strong>No helper selected</strong>
                                <p class="lede">Custom replies stay fully agent-written.</p>
                            </div>

                            @foreach ($replyTemplates as $replyTemplateKey => $replyTemplate)
                                <article data-template-preview-item="{{ $replyTemplateKey }}" @if ($selectedReplyTemplate !== $replyTemplateKey) hidden @endif>
                                    <strong>{{ $replyTemplate['label'] }}</strong>
                                    <p>{{ $replyTemplate['body'] }}</p>
                                </article>
                            @endforeach
                        </div>

                        <div class="notice-list">
                            <p>Keep sensitive details out of replies unless the visitor supplied them here.</p>
                            <p>Create or attach a ticket when the next step needs durable follow-up.</p>
                        </div>
                    </aside>
                </div>
            </section>

            @include('agent.partials.reply-composer-script')

    @if ($realtime)
        <script>
            (function () {
                var config = @json($realtime);
                var panel = document.querySelector('[data-cobrowse-update-panel]');
                var status = document.querySelector('[data-cobrowse-update-status]');
                var refresh = document.querySelector('[data-cobrowse-refresh]');
                var csrf = document.querySelector('meta[name="csrf-token"]');

                if (!config || !panel || !status || !window.WebSocket) {
                    if (status) {
                        status.textContent = 'Live cobrowse updates are unavailable in this browser.';
                    }

                    return;
                }

                if (refresh) {
                    refresh.addEventListener('click', function () {
                        window.location.reload();
                    });
                }

                function setStatus(message, state) {
                    status.textContent = message;
                    panel.dataset.state = state || 'idle';
                }

                function parsePayload(payload) {
                    if (typeof payload === 'string') {
                        return JSON.parse(payload);
                    }

                    return payload || {};
                }

                function subscribe(socket, auth) {
                    socket.send(JSON.stringify({
                        event: 'pusher:subscribe',
                        data: {
                            auth: auth,
                            channel: config.channelName
                        }
                    }));
                }

                function authorize(socket, socketId) {
                    var body = new URLSearchParams();

                    body.set('socket_id', socketId);
                    body.set('channel_name', config.channelName);

                    fetch(config.authEndpoint, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                            'X-CSRF-TOKEN': csrf ? csrf.getAttribute('content') : ''
                        },
                        body: body.toString()
                    })
                        .then(function (response) {
                            if (!response.ok) {
                                throw new Error('Broadcast authorization failed.');
                            }

                            return response.json();
                        })
                        .then(function (data) {
                            subscribe(socket, data.auth);
                            setStatus('Listening for live cobrowse updates.', 'listening');
                        })
                        .catch(function () {
                            setStatus('Live cobrowse updates could not connect.', 'warning');
                        });
                }

                var socketScheme = config.scheme === 'https' ? 'wss' : 'ws';
                var socketUrl = socketScheme + '://' + config.host + ':' + config.port + '/app/' + encodeURIComponent(config.appKey) + '?protocol=7&client=wayfindr-agent&version=0.0.0&flash=false';
                var socket = new WebSocket(socketUrl);

                socket.addEventListener('message', function (message) {
                    var event;

                    try {
                        event = JSON.parse(message.data);
                    } catch (error) {
                        return;
                    }

                    if (event.event === 'pusher:connection_established') {
                        authorize(socket, parsePayload(event.data).socket_id);

                        return;
                    }

                    if (event.event === config.eventName) {
                        setStatus('New cobrowse update available. Refresh the preview when you are ready.', 'available');

                        if (refresh) {
                            refresh.hidden = false;
                        }
                    }
                });

                socket.addEventListener('close', function () {
                    if (panel.dataset.state !== 'available') {
                        setStatus('Live cobrowse updates disconnected.', 'warning');
                    }
                });

                socket.addEventListener('error', function () {
                    if (panel.dataset.state !== 'available') {
                        setStatus('Live cobrowse updates could not connect.', 'warning');
                    }
                });
            })();
        </script>
    @endif
</x-layouts.app>
