@php
    $emptyMessage = $emptyMessage ?? 'No messages yet.';
    $supportCode = $supportCode ?? null;
    $transcriptSiteColor = $transcriptSiteColor ?? null;
    $transcriptMessages = $transcriptMessages ?? collect();
    $latestAgentMessageId = $transcriptMessages
        ->filter(fn ($message) => $message->sender_type === \App\Models\User::class)
        ->last()?->id;
    $previousTranscriptMessage = null;
@endphp

@if ($transcriptMessages->isEmpty())
    <p class="empty">{{ $emptyMessage }}</p>
@else
    <div class="message-list" @if ($transcriptSiteColor) style="--wf-conversation-site: var({{ $transcriptSiteColor }})" @endif>
        @foreach ($transcriptMessages as $transcriptMessage)
            @php
                $isAgent = $transcriptMessage->sender_type === \App\Models\User::class;
                // Shared by the translated conversation and ticket pages. A
                // shared VIEW may read the catalogue because it only renders
                // inside a request.
                $senderName = $isAgent
                    ? ($transcriptMessage->sender?->name ?? __('conversations.detail.roles.agent'))
                    : __('conversations.detail.roles.visitor');
                $senderNameIsAuthored = $isAgent && $transcriptMessage->sender?->name !== null;
                $secondsSincePrevious = $previousTranscriptMessage?->created_at?->diffInSeconds($transcriptMessage->created_at, false);
                $isGrouped = $previousTranscriptMessage
                    && $previousTranscriptMessage->sender_type === $transcriptMessage->sender_type
                    && (string) $previousTranscriptMessage->sender_id === (string) $transcriptMessage->sender_id
                    && $secondsSincePrevious !== null
                    && $secondsSincePrevious >= 0
                    && $secondsSincePrevious <= 300;
                $messageClasses = 'message '.($isAgent ? 'agent' : 'visitor').($isGrouped ? ' grouped' : '');
            @endphp
            <article class="{{ $messageClasses }}" data-message-id="{{ $transcriptMessage->id }}">
                <div class="message-meta">
                    <strong class="{{ $isGrouped ? 'sr-only' : 'message-sender' }}" @if ($senderNameIsAuthored) lang="" @endif>{{ $senderName }}</strong>
                    <span class="message-status-line">
                        <time class="message-time" datetime="{{ $transcriptMessage->created_at->toJSON() }}">{{ $transcriptMessage->created_at->diffForHumans() }}</time>
                        @if ($isAgent && $transcriptMessage->seen_at)
                            <span
                                class="message-seen"
                                @if ((string) $transcriptMessage->id === (string) $latestAgentMessageId) data-agent-message-seen-id="{{ $transcriptMessage->id }}" @endif
                            >
                                {{ __('conversations.detail.reply.seen_by_visitor', ['elapsed' => $transcriptMessage->seen_at->diffForHumans()]) }}
                            </span>
                        @elseif ($isAgent && (string) $transcriptMessage->id === (string) $latestAgentMessageId)
                            <span class="message-seen" data-agent-message-seen-id="{{ $transcriptMessage->id }}">{{ __('conversations.detail.reply.not_seen') }}</span>
                        @endif
                    </span>
                </div>
                @php
                    $messageAttachments = $transcriptMessage->relationLoaded('attachments')
                        ? $transcriptMessage->attachments
                        : collect();
                @endphp

                @if (filled($transcriptMessage->body))
                    {{-- The conversation's own content, not the dashboard's. A
                         visitor writes in whatever language they came in with,
                         and an agent replies in whatever language they chose to
                         reply in -- neither has anything to do with the language
                         this agent reads the DASHBOARD in. `lang=""` is HTML's
                         "unknown", the same answer a managed reply template
                         gives, and the honest one: guessing German because the
                         chrome is German would have a screen reader pronounce an
                         English conversation with German rules. --}}
                    <p class="message-body" lang="">{{ $transcriptMessage->body }}</p>
                @elseif ($messageAttachments->isEmpty())
                    <p class="message-empty">{{ __('conversations.detail.reply.no_body') }}</p>
                @endif

                @if ($supportCode && $messageAttachments->isNotEmpty())
                    <div class="message-attachments">
                        @foreach ($messageAttachments as $attachment)
                            @php
                                $attachmentUrl = route('dashboard.conversations.attachments.show', [
                                    'supportCode' => $supportCode,
                                    'attachment' => $attachment->id,
                                ]);
                            @endphp

                            @if ($attachment->isImage())
                                <a class="message-attachment message-attachment-image-link" href="{{ $attachmentUrl }}" target="_blank" rel="noopener noreferrer">
                                    {{-- `lang` on the img, not around it: alt is an ATTRIBUTE and takes
                                         its language from its element. The filename is whatever
                                         the visitor called it. --}}
                                    <img class="message-attachment-image" src="{{ $attachmentUrl }}" alt="{{ $attachment->original_filename }}" lang="" loading="lazy">
                                </a>
                            @else
                                <a class="message-attachment message-attachment-file" href="{{ $attachmentUrl }}" target="_blank" rel="noopener noreferrer" download>
                                    <x-icon name="attachment" :size="14" class="message-attachment-icon" />
                                    <span class="message-attachment-name" lang="">{{ $attachment->original_filename }}</span>
                                </a>
                            @endif
                        @endforeach
                    </div>
                @endif
            </article>

            @php
                $previousTranscriptMessage = $transcriptMessage;
            @endphp
        @endforeach
    </div>
@endif
