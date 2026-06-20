<x-layouts.app title="Conversations" :agent="$agent" :account="$account">
            <p><a class="text-link" href="{{ route('dashboard') }}">Back to dashboard</a></p>
            <h1>Conversations</h1>
            <p class="lede">Active visitor conversations for {{ $account->name }}.</p>

            <section id="conversations" class="section" aria-labelledby="conversations-heading">
                <div class="section-header">
                    <h2 id="conversations-heading">Conversation queue</h2>
                    <div class="section-actions">
                        <span class="lede">
                            {{ $conversations->count() }} open ·
                            {{ $newActivityConversationCount === 1 ? '1 needs attention' : $newActivityConversationCount.' need attention' }} ·
                            {{ $cobrowseAttentionConversationCount === 1 ? '1 cobrowse session needs attention' : $cobrowseAttentionConversationCount.' cobrowse sessions need attention' }}
                        </span>
                        @foreach ($conversationFilters as $filterValue => $filterLabel)
                            <a
                                class="button {{ $conversationFilter === $filterValue ? '' : 'secondary' }}"
                                href="{{ route('dashboard.conversations.index', $filterValue === 'all' ? [] : ['conversation_filter' => $filterValue]) }}"
                                @if ($conversationFilter === $filterValue) aria-current="page" @endif
                            >
                                {{ $filterLabel }}
                            </a>
                        @endforeach
                    </div>
                </div>

                @if ($conversations->isEmpty())
                    <p class="empty">{{ $conversationEmptyMessage }}</p>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">Subject</th>
                                    <th scope="col">Site</th>
                                    <th scope="col">Visitor</th>
                                    <th scope="col">Presence</th>
                                    <th scope="col">Cobrowse</th>
                                    <th scope="col">Assigned</th>
                                    <th scope="col">Attention</th>
                                    <th scope="col">Read</th>
                                    <th scope="col">Support Code</th>
                                    <th scope="col">Last Activity</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($conversations as $conversation)
                                    @php
                                        $cobrowseTransport = $cobrowseTransportByConversationId->get($conversation->id, [
                                            'label' => 'Unavailable',
                                            'message' => 'Cobrowse transport is not active.',
                                            'last_report' => 'Not reported',
                                            'tone' => 'manual',
                                        ]);
                                    @endphp
                                    <tr>
                                        <td>
                                            <a class="text-link" href="{{ route('dashboard.conversations.show', $conversation->support_code) }}">
                                                {{ $conversation->subject ?? 'Untitled conversation' }}
                                            </a>
                                        </td>
                                        <td>
                                            <a class="text-link" href="{{ route('dashboard.sites.show', $conversation->site) }}">
                                                {{ $conversation->site->name }}
                                            </a>
                                        </td>
                                        <td>{{ $conversation->visitor->anonymous_id ?? 'Unknown visitor' }}</td>
                                        <td>
                                            <span class="readiness-status" data-status="{{ in_array($conversation->visitor?->presenceState(), ['active', 'recent'], true) ? 'ready' : 'manual' }}">
                                                {{ $conversation->visitor?->presenceLabel() ?? 'Not reported' }}
                                            </span>
                                        </td>
                                        <td>
                                            <span
                                                class="readiness-status"
                                                data-status="{{ $cobrowseTransport['tone'] }}"
                                                aria-label="Cobrowse {{ $cobrowseTransport['label'] }}. {{ $cobrowseTransport['message'] }}"
                                                title="{{ $cobrowseTransport['message'] }}"
                                            >
                                                {{ $cobrowseTransport['label'] }}
                                            </span>
                                            <span class="table-note">Last report {{ $cobrowseTransport['last_report'] }}</span>
                                        </td>
                                        <td>{{ $conversation->assignedAgent?->name ?? 'Unassigned' }}</td>
                                        <td>{{ $conversation->attentionLabel() }}</td>
                                        <td>
                                            <span class="readiness-status" data-status="{{ $conversation->hasNewActivityFor($agent) ? 'attention' : 'ready' }}">
                                                {{ $conversation->readStateLabelFor($agent) }}
                                            </span>
                                        </td>
                                        <td>
                                            <a class="text-link" href="{{ route('dashboard.support-code.lookup', ['support_code' => $conversation->support_code]) }}" aria-label="Open support record {{ $conversation->support_code }}">
                                                <code>{{ $conversation->support_code }}</code>
                                            </a>
                                        </td>
                                        <td>{{ $conversation->last_message_at?->diffForHumans() ?? $conversation->created_at->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
</x-layouts.app>
