<section class="section" aria-labelledby="messages-heading">
    <div class="section-header">
        <h2 id="messages-heading">Messages</h2>
        <span class="lede" data-transcript-count>{{ $messages->count() }} total</span>
    </div>

    <div data-transcript>
        @include('agent.conversations.partials.message-list', [
            'emptyMessage' => 'No messages yet.',
            'transcriptMessages' => $messages,
        ])
    </div>

    <p class="realtime-note" data-visitor-typing aria-live="polite" {{ $conversation->visitorTypingState() === 'typing' ? '' : 'hidden' }}>Visitor is typing…</p>
</section>

<section class="section" aria-labelledby="reply-heading">
    <div class="section-header">
        <h2 id="reply-heading">Reply</h2>
        <span class="lede">{{ $conversation->attentionLabel() }}</span>
    </div>

    @php
        $oldReplyTemplate = old('reply_template', '');
        $selectedReplyTemplate = is_string($oldReplyTemplate) ? $oldReplyTemplate : '';
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
            data-typing-url="{{ route('dashboard.conversations.typing.store', $conversation->support_code) }}"
        >
            @csrf
            @include('agent.conversations.partials.return-query-fields')

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
                <div class="reply-context-item">
                    <span class="meta-label">Visitor read</span>
                    <span class="meta-value" data-visitor-read-label aria-live="polite">{{ $conversation->visitorReadLabel() }}</span>
                    <span class="lede" data-visitor-read-detail>{{ $conversation->visitorReadDetail() }}</span>
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
