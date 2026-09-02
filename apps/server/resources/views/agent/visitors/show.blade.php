<x-layouts.app :title="__('visitors.profile.document_title')" :agent="$agent" :account="$account">
    <x-page-header :title="__('visitors.profile.title')" :back-href="route('dashboard')" :back-label="__('visitors.profile.back')">
        <p class="lede"><span lang="">{{ $visitor->site->name }}</span> · <span lang="">{{ $visitorContext['anonymous_id'] }}</span></p>
    </x-page-header>

    <section class="section" aria-labelledby="visitor-profile-heading">
        <div class="section-header">
            <h2 id="visitor-profile-heading">{{ __('visitors.profile.glance.heading') }}</h2>
            <span class="lede">{{ __('visitors.profile.glance.safe_only') }}</span>
        </div>

        <div class="meta-grid">
            <div class="meta-item">
                <span class="meta-label">{{ __('visitors.profile.glance.visitor') }}</span>
                <span class="meta-value" lang="">{{ $visitorContext['anonymous_id'] }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">{{ __('visitors.profile.glance.host_visitor_id') }}</span>
                <span class="meta-value" @if ($visitorContext['external_id']) lang="" @endif>{{ $visitorContext['external_id'] ?? __('visitors.common.not_provided') }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">{{ __('visitors.profile.glance.last_seen') }}</span>
                <span class="meta-value">{{ $visitorContext['last_seen_at']?->diffForHumans() ?? __('visitors.common.not_reported') }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">{{ __('visitors.profile.glance.latest_page') }}</span>
                <span class="meta-value" @if ($visitorContext['last_page_url']) lang="" @endif>{{ $visitorContext['last_page_url'] ?? __('visitors.common.not_reported') }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">{{ __('visitors.profile.glance.entry_page') }}</span>
                <span class="meta-value" @if ($visitorContext['first_started_page_url']) lang="" @endif>{{ $visitorContext['first_started_page_url'] ?? __('visitors.common.not_reported') }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">{{ __('visitors.profile.glance.support_history') }}</span>
                <span class="meta-value">
                    {{ trans_choice('visitors.counts.conversations', $conversations->count(), ['count' => \App\Support\ReaderNumber::count($conversations->count())]) }}
                    ·
                    {{ trans_choice('visitors.counts.tickets', $tickets->count(), ['count' => \App\Support\ReaderNumber::count($tickets->count())]) }}
                </span>
            </div>
        </div>

        <div class="section-header">
            <strong>{{ __('visitors.snapshot.heading') }}</strong>
            <span class="readiness-status" data-status="{{ $supportSnapshot['tone'] }}">
                {{ $supportSnapshot['status_label'] }}
            </span>
        </div>

        <div class="meta-grid">
            <div class="meta-item">
                <span class="meta-label">{{ __('visitors.snapshot.conversations') }}</span>
                <span class="meta-value">{{ $supportSnapshot['active_conversation_label'] }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">{{ __('visitors.snapshot.tickets') }}</span>
                <span class="meta-value">{{ $supportSnapshot['active_ticket_label'] }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">{{ __('visitors.snapshot.next_step') }}</span>
                <span class="meta-value">{{ $supportSnapshot['next_action']['title'] }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">{{ __('visitors.snapshot.agent_cue') }}</span>
                <span class="meta-value">{{ $supportSnapshot['status_label'] }}</span>
                <p class="lede">{{ $supportSnapshot['next_action']['body'] }}</p>
                @if ($supportSnapshot['next_action']['href'])
                    <a class="text-link health-action" href="{{ $supportSnapshot['next_action']['href'] }}">
                        {{ $supportSnapshot['next_action']['cta'] }}
                    </a>
                @endif
            </div>
        </div>

        <div class="section-header">
            <strong>{{ __('visitors.references.heading') }}</strong>
            <span class="lede">{{ __('visitors.references.lede') }}</span>
        </div>

        <div class="meta-grid">
            <div class="meta-item">
                <span class="meta-label">{{ __('visitors.references.visitor') }}</span>
                <span class="meta-value">
                    <a class="text-link" lang="" href="{{ route('dashboard.support-code.lookup', ['support_code' => $supportReferences['visitor_reference']]) }}">
                        {{ $supportReferences['visitor_reference'] }}
                    </a>
                </span>
            </div>
            <div class="meta-item">
                <span class="meta-label">{{ __('visitors.profile.glance.host_visitor_id') }}</span>
                <span class="meta-value">
                    @if ($supportReferences['host_visitor_id'])
                        <a class="text-link" lang="" href="{{ route('dashboard.support-code.lookup', ['reference_type' => 'visitor', 'support_code' => $supportReferences['host_visitor_id']]) }}">
                            {{ $supportReferences['host_visitor_id'] }}
                        </a>
                    @else
                        {{ __('visitors.common.not_provided') }}
                    @endif
                </span>
            </div>
            <div class="meta-item">
                <span class="meta-label">{{ __('visitors.references.latest_support_code') }}</span>
                <span class="meta-value">
                    @if ($supportReferences['latest_conversation'])
                        <a class="text-link" lang="" href="{{ route('dashboard.conversations.show', $supportReferences['latest_conversation']->support_code) }}">
                            {{ $supportReferences['latest_conversation']->support_code }}
                        </a>
                    @else
                        {{ __('visitors.references.no_conversations') }}
                    @endif
                </span>
            </div>
            <div class="meta-item">
                <span class="meta-label">{{ __('visitors.references.latest_ticket') }}</span>
                <span class="meta-value">
                    @if ($supportReferences['latest_ticket'])
                        <a class="text-link" href="{{ route('dashboard.tickets.show', $supportReferences['latest_ticket']) }}">
                            {{ __('visitors.references.ticket', ['id' => $supportReferences['latest_ticket']->id]) }}
                        </a>
                    @else
                        {{ __('visitors.references.no_tickets') }}
                    @endif
                </span>
            </div>
        </div>

        <div class="notice-copy notice-copy-bordered">
            <p><strong>{{ __('visitors.boundary.heading') }}</strong></p>
            <p>{{ __('visitors.boundary.body') }}</p>
        </div>

        <div class="section-header">
            <strong>{{ __('visitors.context.heading') }}</strong>
            <span class="lede">{{ trans_choice('visitors.counts.fields', count($visitorContext['host_context']), ['count' => \App\Support\ReaderNumber::count(count($visitorContext['host_context']))]) }}</span>
        </div>

        @if ($visitorContext['host_context'] === [])
            <div class="empty empty-state">
                <strong>{{ __('visitors.context.empty_heading') }}</strong>
                {{ __('visitors.context.empty_body') }}
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('visitors.context.field') }}</th>
                            <th scope="col">{{ __('visitors.context.value') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($visitorContext['host_context'] as $field => $value)
                            <tr>
                                <td lang="">{{ $field }}</td>
                                <td lang="">{{ $value }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="section" aria-labelledby="visitor-history-heading">
        <div class="section-header">
            <h2 id="visitor-history-heading">{{ __('visitors.history.heading') }}</h2>
            <span class="lede">
                {{ trans_choice('visitors.counts.conversations', $conversations->count(), ['count' => \App\Support\ReaderNumber::count($conversations->count())]) }}
                ·
                {{ trans_choice('visitors.counts.tickets', $tickets->count(), ['count' => \App\Support\ReaderNumber::count($tickets->count())]) }}
            </span>
        </div>

        <div class="section-header">
            <strong>{{ __('visitors.history.conversations') }}</strong>
            <span class="lede">{{ trans_choice('visitors.counts.shown_conversations', $conversations->count(), ['count' => \App\Support\ReaderNumber::count($conversations->count())]) }}</span>
        </div>

        @if ($conversations->isEmpty())
            <div class="empty empty-state">
                <strong>{{ __('visitors.history.no_conversations_heading') }}</strong>
                {{ __('visitors.history.no_conversations_body') }}
            </div>
        @else
            <div class="timeline-list">
                @foreach ($conversations as $conversation)
                    <article class="timeline-item">
                        <div class="timeline-content">
                            <a class="text-link" @if ($conversation->subject) lang="" @endif href="{{ route('dashboard.conversations.show', $conversation->support_code) }}">
                                {{ $conversation->subject ?? __('visitors.history.untitled_conversation') }}
                            </a>
                            <div class="timeline-meta">
                                <span lang="">{{ $conversation->support_code }}</span>
                                <span>{{ __('conversations.detail.statuses.'.$conversation->status) }}</span>
                                <span>{{ __('visitors.history.owner') }}: @if ($conversation->assignedAgent)<span lang="">{{ $conversation->assignedAgent->name }}</span>@else{{ __('visitors.history.unassigned') }}@endif</span>
                                <span>{{ __('visitors.history.last_activity', ['elapsed' => $conversation->last_message_at?->diffForHumans() ?? $conversation->created_at->diffForHumans()]) }}</span>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        <div class="section-header">
            <strong>{{ __('visitors.history.tickets') }}</strong>
            <span class="lede">{{ trans_choice('visitors.counts.shown_tickets', $tickets->count(), ['count' => \App\Support\ReaderNumber::count($tickets->count())]) }}</span>
        </div>

        @if ($tickets->isEmpty())
            <div class="empty empty-state">
                <strong>{{ __('visitors.history.no_tickets_heading') }}</strong>
                {{ __('visitors.history.no_tickets_body') }}
            </div>
        @else
            <div class="timeline-list">
                @foreach ($tickets as $ticket)
                    <article class="timeline-item">
                        <div class="timeline-content">
                            <a class="text-link" lang="" href="{{ route('dashboard.tickets.show', $ticket) }}">
                                {{ $ticket->subject }}
                            </a>
                            <div class="timeline-meta">
                                <span>{{ __('tickets.statuses.'.$ticket->status) }}</span>
                                <span>{{ $ticket->category ? __('tickets.categories.'.$ticket->category) : __('tickets.filters.category_uncategorized') }}</span>
                                <span>{{ __('tickets.priorities.'.$ticket->priority) }}</span>
                                <span>{{ __('visitors.history.owner') }}: @if ($ticket->assignee)<span lang="">{{ $ticket->assignee->name }}</span>@else{{ __('visitors.history.unassigned') }}@endif</span>
                                @if ($ticket->conversation)
                                    <span>{{ __('visitors.history.support_code') }}: <span lang="">{{ $ticket->conversation->support_code }}</span></span>
                                @endif
                                <span>{{ __('visitors.history.updated', ['elapsed' => $ticket->updated_at->diffForHumans()]) }}</span>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
</x-layouts.app>
