<section class="section" aria-labelledby="messages-heading">
    <div class="section-header">
        <h2 id="messages-heading">{{ __('conversations.detail.headings.messages') }}</h2>
        {{-- The script that updates this count cannot reach the catalogue, so both
             plural forms are handed to it as data attributes and it picks one. --}}
        <span
            class="lede"
            data-transcript-count
            data-total-one="{{ trans_choice('conversations.detail.tabs.transcript_total', 1, ['count' => 1]) }}"
            data-total-many="{{ trans_choice('conversations.detail.tabs.transcript_total', 2, ['count' => ':count']) }}"
        >{{ trans_choice('conversations.detail.tabs.transcript_total', $messages->count(), ['count' => $messages->count()]) }}</span>
    </div>

    <div data-transcript>
        @include('agent.conversations.partials.message-list', [
            'emptyMessage' => 'No messages yet.',
            'transcriptMessages' => $messages,
            'supportCode' => $conversation->support_code,
            'transcriptSiteColor' => $conversation->site->resolvedColor()->cssVariable(),
        ])
    </div>

    <p class="realtime-note" data-visitor-typing aria-live="polite" {{ $conversation->visitorTypingState() === 'typing' ? '' : 'hidden' }}>{{ __('conversations.detail.reply.typing') }}</p>
</section>

<section class="section" aria-labelledby="reply-heading">
    <div class="section-header">
        <h2 id="reply-heading">{{ __('conversations.detail.reply.heading') }}</h2>
        {{-- The model hands out a state; this surface renders it. --}}
        <span class="lede">{{ __('conversations.row.attention_'.$conversation->attentionState()) }}</span>
    </div>

    @php
        $oldReplyTemplate = old('reply_template', '');
        $selectedReplyTemplate = is_string($oldReplyTemplate) ? $oldReplyTemplate : '';
    @endphp

    <div class="reply-workspace" data-reply-shell>
        <form
            class="section-form reply-form"
            method="POST"
            action="{{ route('dashboard.conversations.messages.store', $conversation->support_code) }}"
            data-reply-composer
            data-submitting-label="Sending reply..."
            data-typing-url="{{ route('dashboard.conversations.typing.store', $conversation->support_code) }}"
            data-attachments-url="{{ route('dashboard.conversations.attachments.store', $conversation->support_code) }}"
        >
            @csrf
            @include('agent.conversations.partials.return-query-fields')

            <div class="reply-context-strip" aria-label="{{ __('conversations.detail.reply.context') }}">
                <div class="reply-context-item">
                    <span class="meta-label">{{ __('conversations.detail.reply.visitor_read') }}</span>
                    <span class="meta-value" data-visitor-read-label aria-live="polite">{{ __('tickets.read_state.'.$conversation->visitorReadCue()['key']) }}</span>
                    <span class="lede" data-visitor-read-detail>@php($readCue = $conversation->visitorReadCue()){{ $readCue['seen_at']
                        ? __('tickets.read_state.detail_seen', ['elapsed' => $readCue['seen_at']->diffForHumans()])
                        : __('tickets.read_state.'.$readCue['detail_key']) }}</span>
                </div>
            </div>

            <div class="field">
                <label for="reply_template">{{ __('conversations.detail.reply.helper') }}</label>
                <select id="reply_template" name="reply_template" data-template-picker data-target="#body">
                    <option value="">{{ __('conversations.detail.reply.custom') }}</option>
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
                <label for="body">{{ __('conversations.detail.reply.label') }}</label>
                <textarea
                    id="body"
                    name="body"
                    rows="5"
                    placeholder="{{ __('conversations.detail.reply.guidance') }}"
                    aria-describedby="reply-shortcut-help"
                    data-reply-body
                    data-shortcut-submit
                >{{ old('body') }}</textarea>
                <p id="reply-shortcut-help" class="sr-only">{{ __('conversations.detail.reply.shortcut') }}</p>
                @error('body')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field reply-attachments-field">
                <input
                    class="reply-file-input"
                    type="file"
                    accept="image/*,application/pdf,text/plain,.txt,.log"
                    multiple
                    hidden
                    aria-hidden="true"
                    tabindex="-1"
                    data-reply-file-input
                >
                <button class="button secondary reply-attach-button" type="button" data-reply-attach>
                    <span aria-hidden="true">📎</span> {{ __('conversations.detail.reply.attach') }}
                </button>
                <ul class="reply-attachments" data-reply-attachments aria-label="{{ __('conversations.detail.reply.files') }}" hidden></ul>
                @error('attachment_ids')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <p class="sr-only" data-reply-status aria-live="polite"></p>

            <button class="button" type="submit" data-reply-submit>{{ __('conversations.detail.reply.send') }}</button>
        </form>

        <aside class="reply-assist" aria-labelledby="reply-assist-heading">
            <h3 id="reply-assist-heading">{{ __('conversations.detail.reply.assist') }}</h3>

            <div class="reply-template-preview" data-template-preview>
                <div data-template-preview-empty @if ($selectedReplyTemplate !== '') hidden @endif>
                    <strong>{{ __('conversations.detail.reply.writing_own') }}</strong>
                    <p class="lede">{{ __('conversations.detail.reply.helper_note') }}</p>
                </div>

                @foreach ($replyTemplates as $replyTemplateKey => $replyTemplate)
                    <article data-template-preview-item="{{ $replyTemplateKey }}" @if ($selectedReplyTemplate !== $replyTemplateKey) hidden @endif>
                        <strong>{{ $replyTemplate['label'] }}</strong>
                        <p>{{ $replyTemplate['body'] }}</p>
                    </article>
                @endforeach
            </div>

            <div class="notice-list">
                <p>{{ __('conversations.detail.reply.privacy') }}</p>
                <p>{{ __('conversations.detail.ticket.create_hint') }}</p>
            </div>
        </aside>
    </div>
</section>

@include('agent.partials.reply-composer-script')
