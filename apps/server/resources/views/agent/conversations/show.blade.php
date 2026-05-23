<x-layouts.app title="Conversation {{ $conversation->support_code }}">
    <div class="shell">
        <header class="topbar">
            <div class="topbar-inner">
                <div>
                    <div class="brand">Wayfindr</div>
                    <div class="lede">{{ $agent->name }} - {{ $account->name }}</div>
                </div>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="button secondary" type="submit">Sign out</button>
                </form>
            </div>
        </header>

        <main class="page">
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
                        <span class="meta-label">Opened</span>
                        <span class="meta-value">{{ $conversation->created_at->diffForHumans() }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Last Activity</span>
                        <span class="meta-value">{{ $conversation->last_message_at?->diffForHumans() ?? 'No messages yet' }}</span>
                    </div>
                </div>
            </section>

            <section class="section" aria-labelledby="cobrowse-heading">
                <div class="section-header">
                    <h2 id="cobrowse-heading">Cobrowse</h2>
                    <span class="lede">{{ $cobrowseConsent['label'] }}</span>
                </div>

                <p class="empty">{{ $cobrowseConsent['message'] }}</p>

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
        </main>
    </div>
</x-layouts.app>
