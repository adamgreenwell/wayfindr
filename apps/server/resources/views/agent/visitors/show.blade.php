<x-layouts.app title="Visitor profile" :agent="$agent" :account="$account">
            <a class="text-link" href="{{ route('dashboard') }}">Back to dashboard</a>
            <h1>Visitor profile</h1>
            <p class="lede">{{ $visitor->site->name }} - {{ $visitorContext['anonymous_id'] }}</p>

            <section class="section" aria-labelledby="visitor-profile-heading">
                <div class="section-header">
                    <h2 id="visitor-profile-heading">Visitor at a glance</h2>
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
                        <span class="meta-label">First captured entry page</span>
                        <span class="meta-value">{{ $visitorContext['first_started_page_url'] ?? 'Not reported' }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Support history</span>
                        <span class="meta-value">{{ $conversations->count() }} conversations - {{ $tickets->count() }} tickets</span>
                    </div>
                </div>

                <div class="section-header">
                    <strong>Support references</strong>
                    <span class="lede">Stable anchors for search, handoff, and follow-up.</span>
                </div>

                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="meta-label">Visitor lookup reference</span>
                        <span class="meta-value">
                            <a class="text-link" href="{{ route('dashboard.support-code.lookup', ['support_code' => $supportReferences['visitor_reference']]) }}">
                                {{ $supportReferences['visitor_reference'] }}
                            </a>
                        </span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Host visitor ID</span>
                        <span class="meta-value">
                            @if ($supportReferences['host_visitor_id'])
                                <a class="text-link" href="{{ route('dashboard.support-code.lookup', ['reference_type' => 'visitor', 'support_code' => $supportReferences['host_visitor_id']]) }}">
                                    {{ $supportReferences['host_visitor_id'] }}
                                </a>
                            @else
                                Not provided
                            @endif
                        </span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Latest support code</span>
                        <span class="meta-value">
                            @if ($supportReferences['latest_conversation'])
                                <a class="text-link" href="{{ route('dashboard.conversations.show', $supportReferences['latest_conversation']->support_code) }}">
                                    {{ $supportReferences['latest_conversation']->support_code }}
                                </a>
                            @else
                                No conversations yet
                            @endif
                        </span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Latest ticket</span>
                        <span class="meta-value">
                            @if ($supportReferences['latest_ticket'])
                                <a class="text-link" href="{{ route('dashboard.tickets.show', $supportReferences['latest_ticket']) }}">
                                    Ticket #{{ $supportReferences['latest_ticket']->id }}
                                </a>
                            @else
                                No tickets yet
                            @endif
                        </span>
                    </div>
                </div>

                <div class="notice-copy notice-copy-bordered">
                    <p><strong>Data boundary</strong></p>
                    <p>Use this page to understand the support trail. Do not collect, export, or infer extra visitor data without consent.</p>
                </div>

                <div class="section-header">
                    <strong>Host context</strong>
                    <span class="lede">{{ count($visitorContext['host_context']) }} fields</span>
                </div>

                @if ($visitorContext['host_context'] === [])
                    <div class="empty empty-state">
                        <strong>No host-provided context yet.</strong>
                        Wayfindr only has the anonymous visitor reference until the host site supplies safe customer or account context.
                    </div>
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

            <section class="section" aria-labelledby="visitor-history-heading">
                <div class="section-header">
                    <h2 id="visitor-history-heading">Support history</h2>
                    <span class="lede">{{ $conversations->count() }} conversations - {{ $tickets->count() }} tickets</span>
                </div>

                <div class="section-header">
                    <strong>Conversations</strong>
                    <span class="lede">{{ $conversations->count() }} shown</span>
                </div>

                @if ($conversations->isEmpty())
                    <div class="empty empty-state">
                        <strong>No conversations for this visitor yet.</strong>
                        New conversations will appear here once this visitor starts a support thread on this site.
                    </div>
                @else
                    <div class="timeline-list">
                        @foreach ($conversations as $conversation)
                            <article class="timeline-item">
                                <div class="timeline-content">
                                    <a class="text-link" href="{{ route('dashboard.conversations.show', $conversation->support_code) }}">
                                        {{ $conversation->subject ?? 'Untitled conversation' }}
                                    </a>
                                    <div class="timeline-meta">
                                        <span>{{ $conversation->support_code }}</span>
                                        <span>{{ ucfirst($conversation->status) }}</span>
                                        <span>Owner: {{ $conversation->assignedAgent?->name ?? 'Unassigned' }}</span>
                                        <span>Last activity: {{ $conversation->last_message_at?->diffForHumans() ?? $conversation->created_at->diffForHumans() }}</span>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif

                <div class="section-header">
                    <strong>Tickets</strong>
                    <span class="lede">{{ $tickets->count() }} shown</span>
                </div>

                @if ($tickets->isEmpty())
                    <div class="empty empty-state">
                        <strong>No tickets for this visitor yet.</strong>
                        Create a ticket from a conversation when the next step needs durable follow-up.
                    </div>
                @else
                    <div class="timeline-list">
                        @foreach ($tickets as $ticket)
                            <article class="timeline-item">
                                <div class="timeline-content">
                                    <a class="text-link" href="{{ route('dashboard.tickets.show', $ticket) }}">
                                        {{ $ticket->subject }}
                                    </a>
                                    <div class="timeline-meta">
                                        <span>{{ ucfirst($ticket->status) }}</span>
                                        <span>{{ $ticket->categoryLabel() }}</span>
                                        <span>{{ ucfirst($ticket->priority) }}</span>
                                        <span>Owner: {{ $ticket->assignee?->name ?? 'Unassigned' }}</span>
                                        @if ($ticket->conversation)
                                            <span>Support code: {{ $ticket->conversation->support_code }}</span>
                                        @endif
                                        <span>Updated: {{ $ticket->updated_at->diffForHumans() }}</span>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>
</x-layouts.app>
