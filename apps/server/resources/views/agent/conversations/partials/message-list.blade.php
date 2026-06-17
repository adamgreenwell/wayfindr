@php
    $emptyMessage = $emptyMessage ?? 'No messages yet.';
    $transcriptMessages = $transcriptMessages ?? collect();
    $latestAgentMessageId = $transcriptMessages
        ->filter(fn ($message) => $message->sender_type === \App\Models\User::class)
        ->last()?->id;
    $previousTranscriptMessage = null;
@endphp

@if ($transcriptMessages->isEmpty())
    <p class="empty">{{ $emptyMessage }}</p>
@else
    <div class="message-list">
        @foreach ($transcriptMessages as $transcriptMessage)
            @php
                $isAgent = $transcriptMessage->sender_type === \App\Models\User::class;
                $senderName = $isAgent
                    ? ($transcriptMessage->sender?->name ?? 'Agent')
                    : 'Visitor';
                $secondsSincePrevious = $previousTranscriptMessage?->created_at?->diffInSeconds($transcriptMessage->created_at, false);
                $isGrouped = $previousTranscriptMessage
                    && $previousTranscriptMessage->sender_type === $transcriptMessage->sender_type
                    && (string) $previousTranscriptMessage->sender_id === (string) $transcriptMessage->sender_id
                    && $secondsSincePrevious !== null
                    && $secondsSincePrevious >= 0
                    && $secondsSincePrevious <= 300;
                $messageClasses = 'message '.($isAgent ? 'agent' : 'visitor').($isGrouped ? ' grouped' : '');
            @endphp
            <article class="{{ $messageClasses }}">
                <div class="message-meta">
                    <strong class="{{ $isGrouped ? 'sr-only' : 'message-sender' }}">{{ $senderName }}</strong>
                    <span class="message-status-line">
                        <time class="message-time" datetime="{{ $transcriptMessage->created_at->toJSON() }}">{{ $transcriptMessage->created_at->diffForHumans() }}</time>
                        @if ($isAgent && $transcriptMessage->seen_at)
                            <span
                                class="message-seen"
                                @if ((string) $transcriptMessage->id === (string) $latestAgentMessageId) data-agent-message-seen-id="{{ $transcriptMessage->id }}" @endif
                            >
                                Seen by visitor {{ $transcriptMessage->seen_at->diffForHumans() }}
                            </span>
                        @elseif ($isAgent && (string) $transcriptMessage->id === (string) $latestAgentMessageId)
                            <span class="message-seen" data-agent-message-seen-id="{{ $transcriptMessage->id }}">Not seen yet</span>
                        @endif
                    </span>
                </div>
                <p class="message-body">{{ $transcriptMessage->body }}</p>
            </article>

            @php
                $previousTranscriptMessage = $transcriptMessage;
            @endphp
        @endforeach
    </div>
@endif
