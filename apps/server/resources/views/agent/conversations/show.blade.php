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
                    <h2 id="visitor-context-heading">Visitor context</h2>
                    <span class="lede">Existing widget context</span>
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
                        <span class="meta-label">Started on</span>
                        <span class="meta-value">{{ $visitorContext['started_page_url'] ?? 'Not reported' }}</span>
                    </div>
                </div>

                <div class="notice-copy">
                    <p>Use this context to orient support, not to collect extra visitor data.</p>
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
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">Subject</th>
                                    <th scope="col">Support code</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Last activity</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($priorConversations as $priorConversation)
                                    <tr>
                                        <td>
                                            <a class="text-link" href="{{ route('dashboard.conversations.show', $priorConversation->support_code) }}">
                                                {{ $priorConversation->subject ?? 'Untitled conversation' }}
                                            </a>
                                        </td>
                                        <td>{{ $priorConversation->support_code }}</td>
                                        <td>{{ ucfirst($priorConversation->status) }}</td>
                                        <td>{{ $priorConversation->last_message_at?->diffForHumans() ?? $priorConversation->created_at->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
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

                @if ($messages->isEmpty())
                    <p class="empty">No messages yet.</p>
                @else
                    <div class="message-list">
                        @foreach ($messages as $message)
                            @php
                                $isAgent = $message->sender_type === \App\Models\User::class;
                                $senderName = $isAgent
                                    ? ($message->sender?->name ?? 'Agent')
                                    : 'Visitor';
                            @endphp
                            <article class="message {{ $isAgent ? 'agent' : 'visitor' }}">
                                <div class="message-meta">
                                    <strong>{{ $senderName }}</strong>
                                    <span>{{ $message->created_at->diffForHumans() }}</span>
                                </div>
                                <p class="message-body">{{ $message->body }}</p>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="section" aria-labelledby="reply-heading">
                <div class="section-header">
                    <h2 id="reply-heading">Reply</h2>
                </div>

                <form class="section-form" method="POST" action="{{ route('dashboard.conversations.messages.store', $conversation->support_code) }}">
                    @csrf

                    <div class="field">
                        <label for="body">Message</label>
                        <textarea id="body" name="body" rows="4" required>{{ old('body') }}</textarea>
                        @error('body')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <button class="button" type="submit">Send reply</button>
                </form>
            </section>

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
