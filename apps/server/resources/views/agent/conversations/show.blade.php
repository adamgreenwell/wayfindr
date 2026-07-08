<x-layouts.app title="Conversation {{ $conversation->support_code }}" :agent="$agent" :account="$account">
            @php
                $conversationActivityPreview = $conversation->queueActivityPreview();
                $conversationNextAction = $conversation->nextAction();
                $conversationStatusActionReadiness = $conversation->statusActionReadiness();
                $visitorReadTone = match ($conversation->visitorReadState()) {
                    'seen' => 'ready',
                    'unseen' => 'attention',
                    default => 'manual',
                };
                $conversationMapSections = [
                    ['label' => 'Context', 'href' => '#conversation-context-heading'],
                    ['label' => 'Visitor', 'href' => '#visitor-context-heading'],
                    ['label' => 'Messages', 'href' => '#messages-heading'],
                    ['label' => 'Reply', 'href' => '#reply-heading'],
                    ['label' => 'Ticket', 'href' => '#tickets-heading'],
                    ['label' => 'Cobrowse', 'href' => '#cobrowse-heading'],
                    ['label' => 'Status', 'href' => '#conversation-status-action'],
                ];
            @endphp

            <x-page-header :title="$conversation->subject ?? 'Untitled conversation'" :subtitle="'Support code '.$conversation->support_code" :back-href="$conversationBackUrl" back-label="Back to conversations" />

            @if (session('status'))
                <p class="status-message">{{ session('status') }}</p>
            @endif

            <section class="section" aria-labelledby="conversation-map-heading">
                <div class="section-header">
                    <h2 id="conversation-map-heading">Conversation map</h2>
                    <span class="lede">Jump to what this conversation needs next.</span>
                </div>

                <div class="filter-summary" aria-label="Conversation detail sections">
                    <div>
                        <strong>Available sections</strong>
                        <p class="lede">Use the map when the full conversation workspace gets long.</p>
                    </div>
                    <div class="filter-chips">
                        @foreach ($conversationMapSections as $conversationMapSection)
                            <a class="filter-chip" href="{{ $conversationMapSection['href'] }}">
                                {{ $conversationMapSection['label'] }}
                            </a>
                        @endforeach
                    </div>
                </div>
            </section>

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
                        <span class="meta-label">Next action</span>
                        <span class="meta-value">{{ $conversationNextAction['title'] }}</span>
                        <span class="lede">{{ $conversationNextAction['body'] }}</span>
                        <a class="text-link health-action" href="{{ $conversationNextAction['href'] }}">{{ $conversationNextAction['cta'] }}</a>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Reply visibility</span>
                        <span
                            class="readiness-status"
                            data-status="{{ $visitorReadTone }}"
                            data-visitor-read-label
                            data-visitor-read-context-label
                            aria-live="polite"
                        >
                            {{ $conversation->visitorReadLabel() }}
                        </span>
                        <span class="lede" data-visitor-read-detail>{{ $conversation->visitorReadDetail() }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Latest activity</span>
                        <span class="meta-value">{{ $conversationActivityPreview['label'] }}</span>
                        <span class="lede">{{ $conversationActivityPreview['body'] }}</span>
                        @if ($conversationActivityPreview['occurred_at'])
                            <span class="table-note">{{ $conversationActivityPreview['occurred_at']->diffForHumans() }}</span>
                        @endif
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
                        @include('agent.conversations.partials.return-query-fields')

                        <button class="button" type="submit">Claim conversation</button>
                    </form>
                @elseif ($conversation->assigned_agent_id === $agent->id)
                    <form class="section-form" method="POST" action="{{ route('dashboard.conversations.release', $conversation->support_code) }}">
                        @csrf
                        @include('agent.conversations.partials.return-query-fields')

                        <button class="button secondary" type="submit">Release conversation</button>
                    </form>
                @endif

                <div class="notice-copy notice-copy-bordered" id="conversation-status-action">
                    <p>
                        <strong>Status action readiness</strong>
                        <span class="readiness-status" data-status="{{ $conversationStatusActionReadiness['tone'] }}">
                            {{ $conversationStatusActionReadiness['title'] }}
                        </span>
                    </p>
                    <p>{{ $conversationStatusActionReadiness['detail'] }}</p>
                    <div class="notice-actions">
                        <a class="button secondary" href="{{ $conversationStatusActionReadiness['href'] }}">
                            {{ $conversationStatusActionReadiness['cta'] }}
                        </a>
                    </div>
                </div>

                <form class="section-form" method="POST" action="{{ route($conversation->status === 'closed' ? 'dashboard.conversations.reopen' : 'dashboard.conversations.close', $conversation->support_code) }}">
                    @csrf
                    @include('agent.conversations.partials.return-query-fields')

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
                        <span class="readiness-status" data-status="{{ in_array($visitorContext['presence']['state'], ['active', 'recent'], true) ? 'ready' : 'manual' }}" data-visitor-presence-label aria-live="polite">
                            {{ $visitorContext['presence']['label'] }}
                        </span>
                        <span class="lede" data-visitor-presence-detail>{{ $visitorContext['presence']['detail'] }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Last seen</span>
                        <span class="meta-value" data-visitor-presence-last-seen>{{ $visitorContext['last_seen_at']?->diffForHumans() ?? 'Not reported' }}</span>
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
                            <x-support-code-reference
                                :code="$conversation->support_code"
                                :href="route('dashboard.support-code.lookup', ['support_code' => $conversation->support_code])"
                            />
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

            @include('agent.conversations.partials.chat-workspace')

            <section class="section" aria-labelledby="tickets-heading">
                <div class="section-header">
                    <h2 id="tickets-heading">Ticket</h2>
                    <span class="lede">{{ $tickets->isEmpty() ? 'Not created' : $tickets->count().' linked' }}</span>
                </div>

                @if ($tickets->isEmpty())
                    <form class="section-form" method="POST" action="{{ route('dashboard.conversations.tickets.store', $conversation->support_code) }}">
                        @csrf
                        @include('agent.conversations.partials.return-query-fields')

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
                    @include('agent.conversations.partials.linked-ticket-work')
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

                @if ($cobrowseConsent['snapshot_recovery'])
                    <div
                        class="live-update"
                        data-state="{{ $cobrowseConsent['snapshot_recovery']['status'] }}"
                        data-cobrowse-snapshot-recovery
                        data-pending="{{ $cobrowseConsent['snapshot_recovery']['status'] === 'pending' ? 'true' : 'false' }}"
                    >
                        <div>
                            <span class="meta-label">Snapshot refresh guidance</span>
                            <strong data-cobrowse-snapshot-recovery-label>{{ $cobrowseConsent['snapshot_recovery']['label'] }}</strong>
                            <p class="lede" data-cobrowse-snapshot-recovery-message>{{ $cobrowseConsent['snapshot_recovery']['message'] }}</p>
                        </div>
                    </div>
                @endif

                @if (in_array($cobrowseConsent['status'], ['unavailable', 'revoked', 'ended'], true))
                    <form class="section-form" method="POST" action="{{ route('dashboard.conversations.cobrowse.request', $conversation->support_code) }}">
                        @csrf
                        @include('agent.conversations.partials.return-query-fields')

                        <button class="button" type="submit">Request cobrowse</button>
                    </form>
                @elseif (in_array($cobrowseConsent['status'], ['pending', 'granted'], true))
                    @if ($cobrowseConsent['status'] === 'granted')
                        @php
                            $resyncStatus = $cobrowseConsent['resync_request']['status'] ?? null;
                            $resyncActionLabel = in_array($resyncStatus, ['delayed', 'exhausted', 'expired'], true)
                                ? 'Request another fresh snapshot'
                                : 'Request fresh snapshot';
                        @endphp

                        @if ($resyncStatus === 'pending')
                            <form
                                class="section-form"
                                method="POST"
                                action="{{ route('dashboard.conversations.cobrowse.resync', $conversation->support_code) }}"
                                data-resync-retry-form
                                data-retry-at="{{ $cobrowseConsent['resync_request']['retry_at'] ?? '' }}"
                                data-retry-label="Request another fresh snapshot"
                                data-retry-ready-help="Still waiting. You can request another fresh snapshot now."
                                data-retry-ready-recovery="Request another fresh snapshot if the preview still looks out of date."
                            >
                                @csrf
                                @include('agent.conversations.partials.return-query-fields')

                                <button class="button secondary" type="submit" disabled data-resync-retry-button>Fresh snapshot already requested</button>
                                <p class="field-help" data-resync-retry-help>Waiting for the visitor widget before requesting another snapshot.</p>
                            </form>
                        @else
                            <form class="section-form" method="POST" action="{{ route('dashboard.conversations.cobrowse.resync', $conversation->support_code) }}">
                                @csrf
                                @include('agent.conversations.partials.return-query-fields')

                                <button class="button secondary" type="submit">{{ $resyncActionLabel }}</button>
                            </form>
                        @endif
                    @endif
                    <form class="section-form" method="POST" action="{{ route('dashboard.conversations.cobrowse.end', $conversation->support_code) }}">
                        @csrf
                        @include('agent.conversations.partials.return-query-fields')

                        <button class="button secondary" type="submit">
                            {{ $cobrowseConsent['status'] === 'pending' ? 'Cancel request' : 'End cobrowse' }}
                        </button>
                    </form>
                @endif

                @if ($cobrowseConsent['resync_request'])
                    <div class="live-update" data-state="{{ $cobrowseConsent['resync_request']['status'] }}">
                        <div>
                            <strong>{{ $cobrowseConsent['resync_request']['label'] }}</strong>
                            <p class="lede">{{ $cobrowseConsent['resync_request']['message'] }}</p>
                        </div>
                        <span class="lede">
                            Requested by {{ $cobrowseConsent['resync_request']['requested_by'] }}
                            {{ $cobrowseConsent['resync_request']['requested_at'] }}
                            @if (filled($cobrowseConsent['resync_request']['fulfilled_at'] ?? null))
                                <br>
                                Received {{ $cobrowseConsent['resync_request']['fulfilled_at'] }}
                            @endif
                            @if (filled($cobrowseConsent['resync_request']['expires_at'] ?? null))
                                <br>
                                Expires {{ $cobrowseConsent['resync_request']['expires_at'] }}
                            @endif
                            @if (filled($cobrowseConsent['resync_request']['expired_at'] ?? null))
                                <br>
                                Expired {{ $cobrowseConsent['resync_request']['expired_at'] }}
                            @endif
                        </span>
                    </div>

                    @if (! empty($cobrowseConsent['resync_request']['recovery_timeline'] ?? []))
                        <div class="section-header">
                            <strong>Recovery timeline</strong>
                            <span class="lede">Fresh snapshot path</span>
                        </div>

                        <div class="timeline-list">
                            @foreach ($cobrowseConsent['resync_request']['recovery_timeline'] as $timelineItem)
                                <article class="timeline-item internal-note" data-recovery-state="{{ $timelineItem['state'] }}">
                                    <div class="timeline-content">
                                        <strong>{{ $timelineItem['label'] }}</strong>
                                        <p class="message-body">{{ $timelineItem['detail'] }}</p>
                                        <div class="timeline-meta">
                                            <span>{{ $timelineItem['occurred_at'] }}</span>
                                            <span>{{ $timelineItem['badge'] }}</span>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                @endif

                @if ($cobrowseConsent['transport'])
                    @php
                        $transportRecoveryLocked = ($cobrowseConsent['resync_request']['status'] ?? null) === 'pending';
                        $transportRecoveryAction = $transportRecoveryLocked
                            ? 'Fresh snapshot already requested. Wait for the visitor widget before retrying.'
                            : $cobrowseConsent['transport']['recovery_action'];
                    @endphp

                    <div class="section-header" data-cobrowse-transport-panel data-state="{{ $cobrowseConsent['transport']['state'] }}">
                        <strong>Transport health</strong>
                        <span class="lede" data-cobrowse-transport-label>{{ $cobrowseConsent['transport']['label'] }}</span>
                    </div>

                    <p class="empty realtime-note" data-cobrowse-transport-message>{{ $cobrowseConsent['transport']['message'] }}</p>

                    <div class="meta-grid realtime-grid">
                        <div class="meta-item">
                            <span class="meta-label">State</span>
                            <span class="meta-value" data-cobrowse-transport-state-label>{{ $cobrowseConsent['transport']['label'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Last report</span>
                            <span class="meta-value" data-cobrowse-transport-last-report>{{ $cobrowseConsent['transport']['last_report'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Reconnects</span>
                            <span class="meta-value" data-cobrowse-transport-reconnects>{{ $cobrowseConsent['transport']['reconnects'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Pressure</span>
                            <span class="meta-value" data-cobrowse-transport-pressure>{{ $cobrowseConsent['transport']['pressure'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Agent guidance</span>
                            <span class="meta-value" data-cobrowse-transport-guidance>{{ $cobrowseConsent['transport']['guidance'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Recovery action</span>
                            <span
                                class="meta-value"
                                data-cobrowse-transport-recovery
                                data-recovery-locked="{{ $transportRecoveryLocked ? 'true' : 'false' }}"
                            >{{ $transportRecoveryAction }}</span>
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
                        <span
                            class="readiness-status"
                            data-status="{{ $cobrowseConsent['snapshot']['freshness']['tone'] }}"
                            data-cobrowse-snapshot-status
                        >{{ $cobrowseConsent['snapshot']['freshness']['label'] }}</span>
                    </div>

                    <div class="meta-grid realtime-grid">
                        <div class="meta-item">
                            <span class="meta-label">Snapshot freshness</span>
                            <span class="meta-value" data-cobrowse-snapshot-freshness-label>{{ $cobrowseConsent['snapshot']['freshness']['label'] }}</span>
                            <span class="lede" data-cobrowse-snapshot-freshness-message>{{ $cobrowseConsent['snapshot']['freshness']['message'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Reported</span>
                            <span class="meta-value" data-cobrowse-snapshot-freshness-reported>{{ $cobrowseConsent['snapshot']['freshness']['reported_label'] }}</span>
                        </div>
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
                            <span data-cobrowse-replay-applied>{{ $cobrowseConsent['replay_preview']['applied_mutations'] }}</span>
                            /
                            <span data-cobrowse-replay-skipped>{{ $cobrowseConsent['replay_preview']['skipped_mutations'] }}</span>
                        </span>
                        @if ($cobrowseConsent['replay_preview']['viewport_width'])
                            <span class="lede" data-cobrowse-viewport-label>Visitor viewport {{ number_format($cobrowseConsent['replay_preview']['viewport_width']) }}px</span>
                        @else
                            <span class="lede" data-cobrowse-viewport-label hidden></span>
                        @endif
                        <span
                            class="readiness-status"
                            data-status="{{ $cobrowseConsent['replay_preview']['drift']['tone'] }}"
                            data-cobrowse-replay-drift-status
                        >{{ $cobrowseConsent['replay_preview']['drift']['label'] }}</span>
                    </div>

                    <p
                        class="lede realtime-note"
                        data-cobrowse-replay-drift-message
                        data-recommend-resync="{{ $cobrowseConsent['replay_preview']['drift']['recommend_resync'] ? 'true' : 'false' }}"
                        @unless ($cobrowseConsent['replay_preview']['drift']['state'] !== 'steady') hidden @endunless
                    >{{ $cobrowseConsent['replay_preview']['drift']['message'] }} ({{ $cobrowseConsent['replay_preview']['drift']['summary'] }})</p>

                    <div class="cobrowse-preview-frame">
                        <div class="cobrowse-preview-scale">
                            <iframe
                                class="cobrowse-preview"
                                title="Cobrowse replay preview"
                                sandbox
                                srcdoc="{{ $cobrowseConsent['replay_preview']['srcdoc'] }}"
                                data-cobrowse-replay-frame
                                @if ($cobrowseConsent['replay_preview']['viewport_width']) data-viewport-width="{{ $cobrowseConsent['replay_preview']['viewport_width'] }}" @endif
                            ></iframe>
                        </div>
                    </div>

                    <script>
                        (function () {
                            // Render the sandboxed preview at the visitor's reported viewport
                            // width, scaled down to fit the dashboard column (never scaled up),
                            // so captured layout keeps the visitor's real proportions instead
                            // of wrapping at whatever width the column happens to be.
                            function sizeCobrowsePreview() {
                                var frame = document.querySelector('[data-cobrowse-replay-frame]');

                                if (!frame || !frame.parentElement) {
                                    return;
                                }

                                var wrap = frame.parentElement;
                                var viewportWidth = parseInt(frame.getAttribute('data-viewport-width') || '', 10);

                                if (!viewportWidth || viewportWidth <= 0 || wrap.clientWidth <= 0) {
                                    frame.style.width = '';
                                    frame.style.height = '';
                                    frame.style.transform = '';

                                    return;
                                }

                                var scale = Math.min(1, wrap.clientWidth / viewportWidth);

                                frame.style.width = viewportWidth + 'px';
                                frame.style.height = Math.round(wrap.clientHeight / scale) + 'px';
                                frame.style.transform = 'scale(' + scale + ')';
                            }

                            // Chrome can leave the transform-scaled sandboxed iframe
                            // unpainted after parsing its srcdoc: the layer gets promoted
                            // but never rasterized, so the preview sits blank while every
                            // diagnostic reads live. Rebuilding the box across a forced
                            // reflow reliably repaints — but only once the srcdoc document
                            // has finished loading; nudging mid-parse does nothing and the
                            // stall lands afterwards. So the toggle runs from the frame's
                            // load event, which also re-fires on every live srcdoc swap;
                            // the immediate call covers a document that finished loading
                            // before this script attached the listener. Lives in this
                            // always-rendered block so non-realtime installs are covered.
                            function repaintCobrowsePreview() {
                                var frame = document.querySelector('[data-cobrowse-replay-frame]');

                                if (!frame) {
                                    return;
                                }

                                frame.style.display = 'none';
                                void frame.offsetHeight;
                                frame.style.display = '';
                            }

                            var repaintFrame = document.querySelector('[data-cobrowse-replay-frame]');

                            if (repaintFrame) {
                                repaintFrame.addEventListener('load', repaintCobrowsePreview);
                            }

                            window.wayfindrSizeCobrowsePreview = sizeCobrowsePreview;
                            window.addEventListener('resize', sizeCobrowsePreview);
                            sizeCobrowsePreview();
                            repaintCobrowsePreview();
                        })();
                    </script>
                @else
                    <p class="empty realtime-note" data-cobrowse-replay-empty>No replay preview yet.</p>
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
                        <div class="meta-item">
                            <span class="meta-label">Stock widget batch payload</span>
                            <span class="meta-value">{{ $cobrowseConsent['payload_budget']['widget_batch_payload'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Stock widget queue</span>
                            <span class="meta-value">{{ $cobrowseConsent['payload_budget']['widget_queue'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Mutation flush</span>
                            <span class="meta-value">{{ $cobrowseConsent['payload_budget']['widget_mutation_flush'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Pressure resync</span>
                            <span class="meta-value">{{ $cobrowseConsent['payload_budget']['widget_pressure_resync'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Status poll</span>
                            <span class="meta-value">{{ $cobrowseConsent['payload_budget']['widget_status_poll'] }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Resync attempts</span>
                            <span class="meta-value">{{ $cobrowseConsent['payload_budget']['widget_resync_attempts'] }}</span>
                        </div>
                    </div>
                @endif

                <div class="section-header" data-cobrowse-telemetry-heading @if (! $cobrowseConsent['telemetry']) hidden @endif>
                    <strong>Connection telemetry</strong>
                </div>

                <div class="meta-grid realtime-grid" data-cobrowse-telemetry-grid @if (! $cobrowseConsent['telemetry']) hidden @endif>
                    <div class="meta-item">
                        <span class="meta-label">RTT</span>
                        <span class="meta-value" data-cobrowse-telemetry-rtt>{{ $cobrowseConsent['telemetry']['rtt'] ?? 'Not reported' }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Max RTT</span>
                        <span class="meta-value" data-cobrowse-telemetry-max-rtt>{{ $cobrowseConsent['telemetry']['max_rtt'] ?? 'Not reported' }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Payload</span>
                        <span class="meta-value" data-cobrowse-telemetry-payload>{{ $cobrowseConsent['telemetry']['payload'] ?? 'Not reported' }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Max payload</span>
                        <span class="meta-value" data-cobrowse-telemetry-max-payload>{{ $cobrowseConsent['telemetry']['max_payload'] ?? 'Not reported' }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Dropped batches</span>
                        <span class="meta-value" data-cobrowse-telemetry-dropped-batches>{{ $cobrowseConsent['telemetry']['dropped_batches'] ?? '0' }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Reconnects</span>
                        <span class="meta-value" data-cobrowse-telemetry-reconnects>{{ $cobrowseConsent['telemetry']['reconnects'] ?? '0' }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Samples</span>
                        <span class="meta-value" data-cobrowse-telemetry-samples>{{ $cobrowseConsent['telemetry']['samples'] ?? '0' }}</span>
                    </div>
                </div>

                <p class="empty realtime-note" data-cobrowse-telemetry-empty @if ($cobrowseConsent['telemetry']) hidden @endif>No cobrowse connection telemetry yet.</p>
            </section>

            <script>
                (function () {
                    var retryForms = document.querySelectorAll('[data-resync-retry-form]');
                    var lockedRecovery = document.querySelector('[data-cobrowse-transport-recovery][data-recovery-locked="true"]');

                    retryForms.forEach(function (form) {
                        var retryAt = Date.parse(form.getAttribute('data-retry-at') || '');
                        var button = form.querySelector('[data-resync-retry-button]');
                        var help = form.querySelector('[data-resync-retry-help]');

                        if (!button || Number.isNaN(retryAt)) {
                            return;
                        }

                        function enableRetry() {
                            button.disabled = false;
                            button.textContent = form.getAttribute('data-retry-label') || 'Request another fresh snapshot';

                            if (help) {
                                help.textContent = form.getAttribute('data-retry-ready-help') || 'You can request another fresh snapshot now.';
                            }

                            if (lockedRecovery) {
                                lockedRecovery.textContent = form.getAttribute('data-retry-ready-recovery') || 'Request another fresh snapshot if the preview still looks out of date.';
                                lockedRecovery.dataset.recoveryLocked = 'false';
                            }
                        }

                        var delay = retryAt - Date.now();

                        if (delay <= 0) {
                            enableRetry();

                            return;
                        }

                        window.setTimeout(enableRetry, delay);
                    });
                })();
            </script>

    @if ($realtime)
        <script>
            (function () {
                var config = @json($realtime);
                var panel = document.querySelector('[data-cobrowse-update-panel]');
                var status = document.querySelector('[data-cobrowse-update-status]');
                var refresh = document.querySelector('[data-cobrowse-refresh]');
                var previewFrame = document.querySelector('[data-cobrowse-replay-frame]');
                var previewApplied = document.querySelector('[data-cobrowse-replay-applied]');
                var previewSkipped = document.querySelector('[data-cobrowse-replay-skipped]');
                var previewDriftStatus = document.querySelector('[data-cobrowse-replay-drift-status]');
                var previewDriftMessage = document.querySelector('[data-cobrowse-replay-drift-message]');
                var previewViewportLabel = document.querySelector('[data-cobrowse-viewport-label]');
                var visitorPresenceLabel = document.querySelector('[data-visitor-presence-label]');
                var visitorPresenceDetail = document.querySelector('[data-visitor-presence-detail]');
                var visitorPresenceLastSeen = document.querySelector('[data-visitor-presence-last-seen]');
                var visitorReadLabels = document.querySelectorAll('[data-visitor-read-label]');
                var visitorReadDetails = document.querySelectorAll('[data-visitor-read-detail]');
                var transportPanel = document.querySelector('[data-cobrowse-transport-panel]');
                var transportLabel = document.querySelector('[data-cobrowse-transport-label]');
                var transportMessage = document.querySelector('[data-cobrowse-transport-message]');
                var transportStateLabel = document.querySelector('[data-cobrowse-transport-state-label]');
                var transportLastReport = document.querySelector('[data-cobrowse-transport-last-report]');
                var transportReconnects = document.querySelector('[data-cobrowse-transport-reconnects]');
                var transportPressure = document.querySelector('[data-cobrowse-transport-pressure]');
                var transportGuidance = document.querySelector('[data-cobrowse-transport-guidance]');
                var transportRecovery = document.querySelector('[data-cobrowse-transport-recovery]');
                var snapshotStatus = document.querySelector('[data-cobrowse-snapshot-status]');
                var snapshotFreshnessLabel = document.querySelector('[data-cobrowse-snapshot-freshness-label]');
                var snapshotFreshnessMessage = document.querySelector('[data-cobrowse-snapshot-freshness-message]');
                var snapshotFreshnessReported = document.querySelector('[data-cobrowse-snapshot-freshness-reported]');
                var snapshotRecovery = document.querySelector('[data-cobrowse-snapshot-recovery]');
                var snapshotRecoveryLabel = document.querySelector('[data-cobrowse-snapshot-recovery-label]');
                var snapshotRecoveryMessage = document.querySelector('[data-cobrowse-snapshot-recovery-message]');
                var telemetryHeading = document.querySelector('[data-cobrowse-telemetry-heading]');
                var telemetryEmpty = document.querySelector('[data-cobrowse-telemetry-empty]');
                var telemetryGrid = document.querySelector('[data-cobrowse-telemetry-grid]');
                var telemetryRtt = document.querySelector('[data-cobrowse-telemetry-rtt]');
                var telemetryMaxRtt = document.querySelector('[data-cobrowse-telemetry-max-rtt]');
                var telemetryPayload = document.querySelector('[data-cobrowse-telemetry-payload]');
                var telemetryMaxPayload = document.querySelector('[data-cobrowse-telemetry-max-payload]');
                var telemetryDroppedBatches = document.querySelector('[data-cobrowse-telemetry-dropped-batches]');
                var telemetryReconnects = document.querySelector('[data-cobrowse-telemetry-reconnects]');
                var telemetrySamples = document.querySelector('[data-cobrowse-telemetry-samples]');
                var csrf = document.querySelector('meta[name="csrf-token"]');
                var hasCobrowseTargets = Boolean(panel && status);
                var hasPresenceTargets = Boolean(visitorPresenceLabel && visitorPresenceDetail);
                var hasReadTargets = visitorReadLabels.length > 0 && visitorReadDetails.length > 0;
                var hasTransportTargets = Boolean(transportLabel && transportMessage && transportStateLabel);
                var hasSnapshotFreshnessTargets = Boolean(snapshotStatus && snapshotFreshnessLabel && snapshotFreshnessMessage && snapshotFreshnessReported);
                var hasSnapshotRecoveryTargets = Boolean(snapshotRecovery && snapshotRecoveryLabel && snapshotRecoveryMessage);
                var hasTelemetryTargets = Boolean(telemetryGrid && telemetryRtt);

                if (!config || (!hasCobrowseTargets && !hasPresenceTargets && !hasReadTargets && !hasTransportTargets && !hasSnapshotFreshnessTargets && !hasSnapshotRecoveryTargets && !hasTelemetryTargets) || !window.WebSocket) {
                    if (status) {
                        status.textContent = 'Live cobrowse updates are unavailable in this browser.';
                    }

                    return;
                }

                if (refresh) {
                    refresh.addEventListener('click', function () {
                        if (config.previewUrl) {
                            setStatus('Refreshing the preview…', 'listening');
                            refreshCobrowsePreview();

                            return;
                        }

                        window.location.reload();
                    });
                }

                function setStatus(message, state) {
                    if (!hasCobrowseTargets) {
                        return;
                    }

                    status.textContent = message;
                    panel.dataset.state = state || 'idle';
                }

                var previewRefreshInFlight = false;
                var previewRefreshQueued = false;

                // Swap the server-sanitized preview into the existing iframe in
                // place and refresh the applied/skipped and drift labels. Returns
                // false when there is nothing to update (no preview yet, or the
                // preview section was not rendered at page load).
                function applyPreviewState(preview) {
                    if (!preview || !previewFrame) {
                        return false;
                    }

                    if (typeof preview.srcdoc === 'string') {
                        previewFrame.srcdoc = preview.srcdoc;
                    }

                    if (previewApplied && typeof preview.applied_mutations === 'string') {
                        previewApplied.textContent = preview.applied_mutations;
                    }

                    if (previewSkipped && typeof preview.skipped_mutations === 'string') {
                        previewSkipped.textContent = preview.skipped_mutations;
                    }

                    var drift = preview.drift || null;

                    if (drift && previewDriftStatus) {
                        previewDriftStatus.textContent = drift.label || '';
                        previewDriftStatus.dataset.status = drift.tone || 'manual';
                    }

                    if (drift && previewDriftMessage) {
                        var summary = drift.summary ? ' (' + drift.summary + ')' : '';
                        previewDriftMessage.textContent = (drift.message || '') + summary;
                        previewDriftMessage.dataset.recommendResync = drift.recommend_resync ? 'true' : 'false';
                        previewDriftMessage.hidden = drift.state === 'steady';
                    }

                    // Keep the preview rendered at the visitor's reported viewport
                    // width across live swaps (the width can change if the visitor
                    // resizes or moves devices). The repaint after the swap needs
                    // no call here: the frame's persistent load listener (in the
                    // preview block) fires when the replaced srcdoc finishes
                    // parsing — the only moment the repaint actually works.
                    syncPreviewViewport(preview.viewport_width);

                    return true;
                }

                // Resize the preview to the visitor's reported viewport width. Also
                // driven directly from the metadata-only broadcast summary, so a
                // resize-only page_state update fixes the geometry immediately
                // without refetching preview content.
                function syncPreviewViewport(viewportWidth) {
                    if (!previewFrame) {
                        return;
                    }

                    if (typeof viewportWidth === 'number' && viewportWidth > 0) {
                        previewFrame.setAttribute('data-viewport-width', String(viewportWidth));

                        if (previewViewportLabel) {
                            previewViewportLabel.textContent = 'Visitor viewport ' + viewportWidth.toLocaleString() + 'px';
                            previewViewportLabel.hidden = false;
                        }
                    } else {
                        previewFrame.removeAttribute('data-viewport-width');

                        if (previewViewportLabel) {
                            previewViewportLabel.textContent = '';
                            previewViewportLabel.hidden = true;
                        }
                    }

                    if (typeof window.wayfindrSizeCobrowsePreview === 'function') {
                        window.wayfindrSizeCobrowsePreview();
                    }
                }

                // Fetch the latest sanitized preview and apply it live. The
                // broadcast only carries metadata, so the iframe content always
                // comes back through the server sanitizer here, never the socket.
                function refreshCobrowsePreview() {
                    if (!config.previewUrl) {
                        if (refresh) {
                            refresh.hidden = false;
                        }

                        return;
                    }

                    if (previewRefreshInFlight) {
                        previewRefreshQueued = true;

                        return;
                    }

                    previewRefreshInFlight = true;

                    fetch(config.previewUrl, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    })
                        .then(function (response) {
                            if (!response.ok) {
                                throw new Error('Preview refresh failed: ' + response.status);
                            }

                            return response.json();
                        })
                        .then(function (body) {
                            var data = body && body.data ? body.data : {};
                            var preview = data.replay_preview || null;

                            if (preview && !previewFrame) {
                                // The first preview arrived after page load; a
                                // reload renders the section that does not exist yet.
                                window.location.reload();

                                return;
                            }

                            if (applyPreviewState(preview)) {
                                if (refresh) {
                                    refresh.hidden = true;
                                }

                                setStatus('Preview updated with the latest cobrowse changes.', 'listening');
                            }
                        })
                        .catch(function () {
                            if (refresh) {
                                refresh.hidden = false;
                            }

                            setStatus('Could not refresh the preview automatically. Use Refresh preview to try again.', 'warning');
                        })
                        .then(function () {
                            previewRefreshInFlight = false;

                            if (previewRefreshQueued) {
                                previewRefreshQueued = false;
                                refreshCobrowsePreview();
                            }
                        });
                }

                function presenceStatusFor(state) {
                    return state === 'active' || state === 'recent'
                        ? 'ready'
                        : 'manual';
                }

                function updateVisitorPresence(visitorPresence) {
                    if (!hasPresenceTargets || !visitorPresence) {
                        return;
                    }

                    visitorPresenceLabel.textContent = visitorPresence.label || 'Not reported';
                    visitorPresenceLabel.dataset.status = presenceStatusFor(visitorPresence.state || 'unknown');
                    visitorPresenceDetail.textContent = visitorPresence.detail || 'No visitor heartbeat yet.';

                    if (visitorPresenceLastSeen) {
                        visitorPresenceLastSeen.textContent = visitorPresence.last_seen_label || 'Not reported';
                    }
                }

                function readStatusFor(state) {
                    if (state === 'seen') {
                        return 'ready';
                    }

                    return state === 'unseen' ? 'attention' : 'manual';
                }

                function updateVisitorRead(visitorRead) {
                    if (!hasReadTargets || !visitorRead) {
                        return;
                    }

                    visitorReadLabels.forEach(function (visitorReadLabel) {
                        visitorReadLabel.textContent = visitorRead.label || 'No agent reply yet';

                        if (visitorReadLabel.hasAttribute('data-status')) {
                            visitorReadLabel.dataset.status = readStatusFor(visitorRead.state || 'none');
                        }
                    });

                    visitorReadDetails.forEach(function (visitorReadDetail) {
                        visitorReadDetail.textContent = visitorRead.detail || 'No agent reply has been sent.';
                    });

                    var messageId = visitorRead.message_id ? String(visitorRead.message_id) : '';
                    var agentMessageSeen = messageId
                        ? document.querySelector('[data-agent-message-seen-id="' + messageId + '"]')
                        : null;

                    if (!agentMessageSeen) {
                        return;
                    }

                    if (visitorRead.state === 'seen') {
                        agentMessageSeen.textContent = 'Seen by visitor ' + (visitorRead.seen_label || 'just now');

                        return;
                    }

                    if (visitorRead.state === 'unseen') {
                        agentMessageSeen.textContent = 'Not seen yet';
                    }
                }

                function setText(target, value) {
                    if (!target) {
                        return;
                    }

                    target.textContent = value;
                }

                function numericValue(value) {
                    var number = Number(value);

                    return Number.isFinite(number) && number >= 0 ? number : null;
                }

                function formatNumber(value) {
                    var number = numericValue(value);

                    return number === null ? '0' : Math.round(number).toLocaleString();
                }

                function formatMilliseconds(value) {
                    var number = numericValue(value);

                    return number === null ? 'Not reported' : Math.round(number).toLocaleString() + ' ms';
                }

                function formatBytes(value) {
                    var number = numericValue(value);

                    return number === null ? 'Not reported' : Math.round(number).toLocaleString() + ' bytes';
                }

                function timestampValue(value) {
                    var timestamp = Date.parse(value || '');

                    return Number.isNaN(timestamp) ? null : timestamp;
                }

                function formatRelativeTimestamp(value) {
                    var timestamp = timestampValue(value);

                    if (timestamp === null) {
                        return 'just now';
                    }

                    var elapsedSeconds = Math.max(0, Math.round((Date.now() - timestamp) / 1000));

                    if (elapsedSeconds < 45) {
                        return 'just now';
                    }

                    var elapsedMinutes = Math.round(elapsedSeconds / 60);

                    if (elapsedMinutes <= 1) {
                        return '1 minute ago';
                    }

                    return elapsedMinutes.toLocaleString() + ' minutes ago';
                }

                function transportPressureFromSummary(summary) {
                    var pressure = summary.transport_pressure || null;

                    if (!pressure) {
                        return null;
                    }

                    return {
                        dropped_batches: numericValue(pressure.dropped_batches) || 0,
                        skipped_mutations: numericValue(pressure.skipped_mutations) || 0,
                        reported_at: pressure.reported_at || null,
                    };
                }

                function droppedBatchPressure(telemetry, pressure) {
                    var droppedBatches = pressure ? pressure.dropped_batches : numericValue(telemetry.dropped_batches) || 0;
                    var skippedMutations = pressure ? pressure.skipped_mutations : 0;
                    var parts = [];

                    if (droppedBatches > 0) {
                        parts.push(Math.round(droppedBatches).toLocaleString() + ' dropped ' + (droppedBatches === 1 ? 'batch' : 'batches'));
                    }

                    if (skippedMutations > 0) {
                        parts.push(Math.round(skippedMutations).toLocaleString() + ' skipped ' + (skippedMutations === 1 ? 'mutation' : 'mutations'));
                    }

                    if (parts.length === 0) {
                        return 'No recent drops reported';
                    }

                    return parts.join(', ');
                }

                function transportHealthFromTelemetry(telemetry, pressure) {
                    var droppedBatches = pressure ? pressure.dropped_batches : numericValue(telemetry.dropped_batches) || 0;
                    var skippedMutations = pressure ? pressure.skipped_mutations : 0;
                    var reconnects = numericValue(telemetry.reconnects) || 0;

                    if (telemetry.resync_attempts_exhausted === true) {
                        return {
                            state: 'exhausted',
                            label: 'Retry limit reached',
                            message: 'Fresh snapshot retry limit reached.',
                            guidance: 'Request another fresh snapshot when the visitor transport settles.',
                            recovery_action: 'Request another fresh snapshot when the visitor transport settles.',
                        };
                    }

                    if (reconnects > 0) {
                        return {
                            state: 'reconnecting',
                            label: 'Reconnecting',
                            message: 'The visitor transport has reconnected recently; preview data may briefly lag.',
                            guidance: 'Use chat to confirm anything that depends on fast-changing page state.',
                            recovery_action: 'Give the visitor widget a moment, then request a fresh snapshot if the preview still lags.',
                        };
                    }

                    if (droppedBatches > 0 || skippedMutations > 0) {
                        return {
                            state: 'degraded',
                            label: 'Degraded',
                            message: 'Cobrowse reports are arriving, but the visitor page is changing faster than Wayfindr can fully replay.',
                            guidance: 'Use the preview for orientation and confirm fast-changing details through chat.',
                            recovery_action: 'Request a fresh snapshot once the visitor widget settles, and use chat for fast-changing details.',
                        };
                    }

                    return {
                        state: 'live',
                        label: 'Live',
                        message: 'Cobrowse reports are arriving normally.',
                        guidance: 'Preview is current enough to use alongside chat.',
                        recovery_action: 'No recovery action needed.',
                    };
                }

                function updateTransportHealth(telemetry, pressure) {
                    if (!hasTransportTargets || !telemetry) {
                        return;
                    }

                    var health = transportHealthFromTelemetry(telemetry, pressure);

                    if (transportPanel) {
                        transportPanel.dataset.state = health.state;
                    }

                    setText(transportLabel, health.label);
                    setText(transportMessage, health.message);
                    setText(transportStateLabel, health.label);
                    setText(transportLastReport, formatRelativeTimestamp(pressure && pressure.reported_at ? pressure.reported_at : telemetry.reported_at));
                    setText(transportReconnects, formatNumber(telemetry.reconnects));
                    setText(transportPressure, droppedBatchPressure(telemetry, pressure));
                    setText(transportGuidance, health.guidance);

                    if (!transportRecovery) {
                        return;
                    }

                    if (transportRecovery.dataset.recoveryLocked === 'true' && health.state !== 'exhausted') {
                        return;
                    }

                    transportRecovery.dataset.recoveryLocked = 'false';
                    setText(transportRecovery, health.recovery_action);
                }

                function recoveryFromSnapshotFreshness(freshness) {
                    if (!freshness || !freshness.state || freshness.state === 'fresh') {
                        return null;
                    }

                    if (snapshotRecovery && snapshotRecovery.dataset.pending === 'true') {
                        return {
                            status: 'pending',
                            label: 'Snapshot refresh already requested',
                            message: 'A fresh snapshot request is already waiting on the visitor widget. Use chat while it catches up.',
                        };
                    }

                    if (freshness.state === 'unknown') {
                        return {
                            status: 'unknown',
                            label: 'Snapshot time needs confirmation',
                            message: 'Ask the visitor what they see or request a fresh snapshot before relying on this preview.',
                        };
                    }

                    return {
                        status: freshness.state,
                        label: 'Snapshot may need refresh',
                        message: 'Request a fresh snapshot before relying on this preview, or confirm the page through chat.',
                    };
                }

                function updateSnapshotRecovery(freshness) {
                    if (!hasSnapshotRecoveryTargets) {
                        return;
                    }

                    var recovery = recoveryFromSnapshotFreshness(freshness);

                    if (!recovery) {
                        snapshotRecovery.hidden = true;

                        return;
                    }

                    snapshotRecovery.hidden = false;
                    snapshotRecovery.dataset.state = recovery.status || 'unknown';
                    setText(snapshotRecoveryLabel, recovery.label || 'Snapshot may need refresh');
                    setText(snapshotRecoveryMessage, recovery.message || 'Use chat to confirm what the visitor sees before relying on this preview.');
                }

                function updateSnapshotFreshness(snapshot) {
                    if (!hasSnapshotFreshnessTargets || !snapshot || !snapshot.freshness) {
                        return false;
                    }

                    var freshness = snapshot.freshness;

                    setText(snapshotStatus, freshness.label || 'Time unknown');
                    snapshotStatus.dataset.status = freshness.tone || 'manual';
                    setText(snapshotFreshnessLabel, freshness.label || 'Time unknown');
                    setText(snapshotFreshnessMessage, freshness.message || 'Use chat to confirm what the visitor sees before relying on this preview.');
                    setText(snapshotFreshnessReported, freshness.reported_label || 'Report time unavailable');
                    updateSnapshotRecovery(freshness);

                    return true;
                }

                function updateConnectionTelemetry(telemetry) {
                    if (!hasTelemetryTargets || !telemetry) {
                        return;
                    }

                    if (telemetryHeading) {
                        telemetryHeading.hidden = false;
                    }

                    if (telemetryEmpty) {
                        telemetryEmpty.hidden = true;
                    }

                    telemetryGrid.hidden = false;

                    setText(telemetryRtt, formatMilliseconds(telemetry.rtt_ms));
                    setText(telemetryMaxRtt, formatMilliseconds(telemetry.max_rtt_ms));
                    setText(telemetryPayload, formatBytes(telemetry.payload_bytes));
                    setText(telemetryMaxPayload, formatBytes(telemetry.max_payload_bytes));
                    setText(telemetryDroppedBatches, formatNumber(telemetry.dropped_batches));
                    setText(telemetryReconnects, formatNumber(telemetry.reconnects));
                    setText(telemetrySamples, formatNumber(telemetry.samples));
                }

                function telemetryIsFreshForUpdate(telemetry, payload) {
                    var update = payload.update || {};
                    var updateKind = update.kind || '';

                    if (updateKind === 'telemetry') {
                        return true;
                    }

                    var telemetryAt = timestampValue(telemetry.reported_at);
                    var updateAt = timestampValue(update.reported_at);

                    return telemetryAt !== null && updateAt !== null && telemetryAt >= updateAt;
                }

                function pressureIsFreshForUpdate(pressure, payload) {
                    if (!pressure) {
                        return false;
                    }

                    var update = payload.update || {};
                    var updateKind = update.kind || '';

                    if (updateKind === 'telemetry') {
                        return true;
                    }

                    var pressureAt = timestampValue(pressure.reported_at);
                    var updateAt = timestampValue(update.reported_at);

                    return pressureAt !== null && updateAt !== null && pressureAt >= updateAt;
                }

                function updateLiveCobrowseTelemetry(payload) {
                    var summary = payload.summary || {};
                    var telemetry = summary.telemetry || null;
                    var pressure = transportPressureFromSummary(summary);

                    if (!telemetry) {
                        return null;
                    }

                    var telemetryFresh = telemetryIsFreshForUpdate(telemetry, payload);
                    var pressureFresh = pressureIsFreshForUpdate(pressure, payload);

                    if (!telemetryFresh && !pressureFresh) {
                        return null;
                    }

                    updateTransportHealth(telemetry, pressureFresh ? pressure : null);

                    if (telemetryFresh) {
                        updateConnectionTelemetry(telemetry);
                    }

                    return telemetry;
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
                            setStatus('Listening for live conversation updates.', 'listening');
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
                        var cobrowsePayload = parsePayload(event.data);
                        var telemetry = updateLiveCobrowseTelemetry(cobrowsePayload);
                        var updateKind = cobrowsePayload.update ? cobrowsePayload.update.kind : '';
                        var summary = cobrowsePayload.summary || {};

                        // The metadata-only summary carries the visitor's clamped
                        // viewport width on every update kind, so a resize-only
                        // page_state report fixes the preview geometry immediately
                        // without refetching preview content.
                        if (typeof summary.viewport_width === 'number' && summary.viewport_width > 0) {
                            syncPreviewViewport(summary.viewport_width);
                        }

                        if (updateKind === 'snapshot') {
                            updateSnapshotFreshness(summary.snapshot);

                            if (config.previewUrl) {
                                setStatus('Fresh snapshot received. Updating the preview…', 'listening');
                                refreshCobrowsePreview();
                            } else {
                                setStatus('Fresh snapshot received live. Refresh the preview when you are ready.', 'available');

                                if (refresh) {
                                    refresh.hidden = false;
                                }
                            }

                            return;
                        }

                        if (updateKind === 'telemetry') {
                            setStatus(
                                telemetry && telemetry.resync_attempts_exhausted === true
                                    ? 'Fresh snapshot retry limit reached. Request another fresh snapshot when you are ready.'
                                    : 'Connection telemetry updated live.',
                                telemetry && telemetry.resync_attempts_exhausted === true ? 'exhausted' : 'listening'
                            );

                            return;
                        }

                        // Only mutation batches change the rendered preview, so
                        // only they trigger a live re-fetch. Other kinds (page
                        // state, consent lifecycle) keep the calm manual cue so
                        // frequent page-state reports do not refetch needlessly.
                        if (config.previewUrl && updateKind === 'mutations') {
                            setStatus('New cobrowse changes received. Updating the preview…', 'listening');
                            refreshCobrowsePreview();
                        } else {
                            setStatus('New cobrowse update available. Refresh the preview when you are ready.', 'available');

                            if (refresh) {
                                refresh.hidden = false;
                            }
                        }
                    }

                    if (event.event === config.presenceEventName) {
                        updateVisitorPresence(parsePayload(event.data).visitor_presence);
                    }

                    if (event.event === config.readEventName) {
                        updateVisitorRead(parsePayload(event.data).visitor_read);
                    }
                });

                socket.addEventListener('close', function () {
                    if (hasCobrowseTargets && panel.dataset.state !== 'available') {
                        setStatus('Live cobrowse updates disconnected.', 'warning');
                    }
                });

                socket.addEventListener('error', function () {
                    if (hasCobrowseTargets && panel.dataset.state !== 'available') {
                        setStatus('Live cobrowse updates could not connect.', 'warning');
                    }
                });
            })();
        </script>
    @endif
</x-layouts.app>
