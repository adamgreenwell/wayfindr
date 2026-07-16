<x-layouts.app title="Agent Dashboard" :agent="$agent" :account="$account">
            <x-page-header :title="$account->name" :subtitle="'Signed in as '.$agent->email" />

            <section class="section" aria-labelledby="support-queues-heading">
                <div class="section-header">
                    <h2 id="support-queues-heading">Support queues</h2>
                </div>

                <div class="management-list">
                    <a class="management-link" href="{{ route('dashboard.conversations.index') }}">
                        <span>
                            <strong>Conversations</strong>
                            <span class="lede">
                                {{ $supportQueues['open_conversations_count'] }} open
                                · {{ $supportQueues['new_activity_conversations_count'] }} {{ $supportQueues['new_activity_conversations_count'] === 1 ? 'needs' : 'need' }} attention
                                · {{ $supportQueues['cobrowse_attention_conversations_count'] === 1 ? '1 cobrowse session needs attention' : $supportQueues['cobrowse_attention_conversations_count'].' cobrowse sessions need attention' }}
                            </span>
                        </span>
                        <span class="management-action">Open queue</span>
                    </a>
                    <a class="management-link" href="{{ route('dashboard.tickets.index') }}">
                        <span>
                            <strong>Tickets</strong>
                            <span class="lede">
                                {{ $supportQueues['open_tickets_count'] }} open
                                · {{ $supportQueues['unassigned_tickets_count'] }} unassigned
                            </span>
                        </span>
                        <span class="management-action">Open queue</span>
                    </a>
                </div>
            </section>

            <section class="section" aria-labelledby="conversation-next-steps-heading">
                <div class="section-header">
                    <div>
                        <h2 id="conversation-next-steps-heading">Conversation next steps</h2>
                        <p class="lede">
                            {{ $conversationNextSteps['open_count'] }} open {{ \Illuminate\Support\Str::plural('conversation', $conversationNextSteps['open_count']) }} needing movement
                        </p>
                    </div>
                    <a class="button secondary" href="{{ $conversationNextSteps['queue_href'] }}">Open conversation queue</a>
                </div>

                @if ($conversationNextSteps['items'] === [])
                    <p class="empty">No open conversations need movement right now.</p>
                @else
                    <div class="management-list">
                        @foreach ($conversationNextSteps['items'] as $conversationNextStep)
                            <a class="management-link" href="{{ $conversationNextStep['href'] }}">
                                <span>
                                    <strong>{{ $conversationNextStep['title'] }}</strong>
                                    <span class="lede">
                                        {{ $conversationNextStep['count'].' '.\Illuminate\Support\Str::plural($conversationNextStep['label'], $conversationNextStep['count']) }}
                                    </span>
                                    <span class="table-note">{{ $conversationNextStep['detail'] }}</span>
                                </span>
                                <span class="management-action">{{ $conversationNextStep['action'] }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="section" aria-labelledby="ticket-next-steps-heading">
                <div class="section-header">
                    <div>
                        <h2 id="ticket-next-steps-heading">Ticket next steps</h2>
                        <p class="lede">
                            {{ $ticketNextSteps['open_count'] }} open {{ \Illuminate\Support\Str::plural('ticket', $ticketNextSteps['open_count']) }} needing movement
                        </p>
                    </div>
                    <a class="button secondary" href="{{ $ticketNextSteps['queue_href'] }}">Open ticket queue</a>
                </div>

                @if ($ticketNextSteps['items'] === [])
                    <p class="empty">No open tickets need movement right now.</p>
                @else
                    <div class="management-list">
                        @foreach ($ticketNextSteps['items'] as $ticketNextStep)
                            <a class="management-link" href="{{ $ticketNextStep['href'] }}">
                                <span>
                                    <strong>{{ $ticketNextStep['title'] }}</strong>
                                    <span class="lede">
                                        {{ $ticketNextStep['count'].' '.\Illuminate\Support\Str::plural($ticketNextStep['label'], $ticketNextStep['count']) }}
                                    </span>
                                    <span class="table-note">{{ $ticketNextStep['detail'] }}</span>
                                </span>
                                <span class="management-action">{{ $ticketNextStep['action'] }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="section" aria-labelledby="visitor-support-readiness-heading">
                <div class="section-header">
                    <div>
                        <h2 id="visitor-support-readiness-heading">Visitor support readiness</h2>
                    </div>
                    <div class="section-actions">
                        <span class="readiness-status" data-status="{{ $visitorSupportReadiness['attention_count'] > 0 ? 'attention' : 'ready' }}">
                            {{ $visitorSupportReadiness['label'] }}
                        </span>
                        <span class="lede">
                            {{ $visitorSupportReadiness['ready_count'] }} ready
                            · {{ $visitorSupportReadiness['attention_count'] }} {{ $visitorSupportReadiness['attention_count'] === 1 ? 'needs' : 'need' }} attention
                            · {{ $visitorSupportReadiness['manual_count'] }} {{ \Illuminate\Support\Str::plural('manual check', $visitorSupportReadiness['manual_count']) }}
                        </span>
                    </div>
                </div>

                <x-details-disclosure :summary="'Support checks — '.$visitorSupportReadiness['label']">
                <div class="readiness-list">
                    @foreach ($visitorSupportReadiness['checks'] as $check)
                        <article class="readiness-check" data-status="{{ $check['status'] }}">
                            <div class="readiness-check-main">
                                <div>
                                    <h3>{{ $check['label'] }}</h3>
                                    <p>{{ $check['summary'] }}</p>
                                </div>
                                <span class="readiness-status" data-status="{{ $check['status'] }}">
                                    {{ $check['status_label'] }}
                                </span>
                            </div>

                            <p class="lede">{{ $check['detail'] }}</p>
                            <p class="readiness-action">
                                @if ($check['href'])
                                    <a class="text-link" href="{{ $check['href'] }}">{{ $check['action'] }}</a>
                                @else
                                    {{ $check['action'] }}
                                @endif
                            </p>
                        </article>
                    @endforeach
                </div>
                </x-details-disclosure>
            </section>
</x-layouts.app>
