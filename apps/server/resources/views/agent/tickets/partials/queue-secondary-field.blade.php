{{-- Shared by the desktop column and the mobile Details disclosure, so
     localized feedback and permission-scoped activity cannot drift. --}}
@switch($queueField)
    @case('activity')
        <span class="wf-queue-cobrowse">{{ $previewLabel }}</span>
        <span class="wf-queue-preview" title="{{ $previewBody }}">
            {{ $previewBody }}@if ($activityPreview['occurred_at']) &middot; {{ $activityPreview['occurred_at']->diffForHumans() }}@endif
        </span>
        @if ($activityPreview['reply_visibility'])
            <span class="wf-queue-preview">
                {{ __('tickets.row.reply_visibility') }}
                @php
                    $cue = $activityPreview['reply_visibility']['cue'] ?? null;
                @endphp
                <span class="wf-queue-mark" @if ($activityPreview['reply_visibility']['tone'] !== 'manual') data-tone="attention" @endif>{{ $cue ? __('tickets.read_state.'.$cue['key']) : __('tickets.row.no_linked_conversation') }}</span>
                {{ $cue
                    ? ($cue['seen_at']
                        ? __('tickets.read_state.detail_seen', ['elapsed' => $cue['seen_at']->diffForHumans()])
                        : __('tickets.read_state.'.$cue['detail_key']))
                    : __('tickets.row.reply_visibility_none') }}
            </span>
        @endif
        @break

    @case('site')
        <span class="wf-queue-site">
            <span class="wf-site-dot" style="background: var({{ $ticket->site->resolvedColor()->cssVariable() }})" aria-hidden="true"></span>
            {{ $ticket->site->name }}
        </span>
        @break

    @case('category')
        <span class="wf-queue-cobrowse">{{ $ticket->category ? __('tickets.categories.'.$ticket->category) : __('tickets.filters.category_uncategorized') }}</span>
        @break

    @case('labels')
        @if ($ticket->labels->isEmpty())
            <span class="wf-queue-cobrowse">{{ __('tickets.row.none') }}</span>
        @else
            <div class="ticket-label-list">
                @foreach ($ticket->labels as $label)
                    <x-ticket-label-chip :label="$label" :ticket-status="$ticketStatus" />
                @endforeach
            </div>
        @endif
        @break

    @case('priority')
        <span class="wf-queue-cobrowse" @if ($ticket->priority === 'urgent' || $ticket->priority === 'high') data-tone="attention" @endif>
            {{ __('tickets.priorities.'.$ticket->priority) }}
        </span>
        @break

    @case('external')
        <span class="wf-queue-cobrowse" @if ($ticketExternalIssueState['tone'] !== 'manual') data-tone="{{ $ticketExternalIssueState['tone'] === 'ready' ? 'live' : 'attention' }}" @endif>
            {{ $ticketExternalIssueState['label'] }}
        </span>
        <span class="wf-queue-preview" title="{{ $ticketExternalIssueState['detail'] }}">{{ $ticketExternalIssueState['detail'] }}</span>
        @if ($ticketExternalIssueState['attempt'])
            <span class="wf-queue-preview" title="{{ $ticketExternalIssueState['attempt']['body'] }}">
                {{ __('tickets.row.latest_attempt') }} <x-translated-feedback :feedback="$ticketExternalIssueState['attempt']['label_feedback']" />: <x-translated-feedback :feedback="$ticketExternalIssueState['attempt']['body_feedback']" />
            </span>
            @if ($ticketExternalIssueState['attempt']['occurred_at'])
                <span class="wf-queue-preview">{{ $ticketExternalIssueState['attempt']['occurred_at']->diffForHumans() }}</span>
            @endif
        @endif
        @break

@endswitch
