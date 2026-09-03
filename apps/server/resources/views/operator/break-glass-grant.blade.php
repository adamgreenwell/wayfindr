<x-layouts.operator :title="__('operator_break_glass.grant.document_title')">
    @php
        $unknownLanguage = static fn (mixed $value): string => '<span lang="">'.e((string) $value).'</span>';
        $scopeHtml = static function (array $scope) use ($unknownLanguage): string {
            $html = e($scope['label']);

            if ($scope['value'] !== null && $scope['value'] !== '') {
                $html .= ' '.$unknownLanguage($scope['value']);
            }

            return $html;
        };
        $valueHtml = static fn (array $value): string => $value['language'] === ''
            ? $unknownLanguage($value['label'])
            : e($value['label']);
    @endphp

    <p><a class="text-link" href="{{ route('operator.break-glass.index') }}">{{ __('operator_break_glass.grant.back') }}</a></p>
    <h1>{!! $scopeHtml($grantItem['scope']) !!}</h1>
    <p class="lede">
        {!! __('operator_break_glass.grant.summary', [
            'until' => e(\App\Support\ReaderClock::timeWithZone($grant->expires_at)),
            'elapsed' => e($grantItem['expires_at']),
            'account' => $unknownLanguage($grant->account?->name),
        ]) !!}
    </p>

    <section class="section" aria-labelledby="break-glass-grant-conversations-heading">
        <div class="section-header">
            <h2 id="break-glass-grant-conversations-heading">{{ __('operator_break_glass.grant.conversations.heading') }}</h2>
            <span class="lede">{{ trans_choice('operator_break_glass.grant.conversations.count', $coveredConversations->count(), ['count' => \App\Support\ReaderNumber::count($coveredConversations->count())]) }}</span>
        </div>

        @if ($coveredConversations->isEmpty())
            <div class="notice-copy">
                <p>{{ __('operator_break_glass.grant.conversations.empty') }}</p>
            </div>
        @else
            <div class="management-list">
                @foreach ($coveredConversations as $conversation)
                    <a class="management-link" href="{{ route('operator.break-glass.conversations.show', [$grant, $conversation]) }}">
                        <span>
                            <strong lang="">{{ $conversation->support_code }}</strong>
                            <span class="lede">{!! __('operator_break_glass.grant.conversations.row', [
                                'site' => $unknownLanguage($conversation->site?->name),
                                'elapsed' => e($conversation->created_at->diffForHumans()),
                            ]) !!}</span>
                        </span>
                        <span class="management-action">{{ __('operator_break_glass.grant.conversations.view') }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    <section class="section" aria-labelledby="break-glass-grant-tickets-heading">
        <div class="section-header">
            <h2 id="break-glass-grant-tickets-heading">{{ __('operator_break_glass.grant.tickets.heading') }}</h2>
            <span class="lede">{{ trans_choice('operator_break_glass.grant.tickets.count', $coveredTickets->count(), ['count' => \App\Support\ReaderNumber::count($coveredTickets->count())]) }}</span>
        </div>

        @if ($coveredTickets->isEmpty())
            <div class="notice-copy">
                <p>{{ __('operator_break_glass.grant.tickets.empty') }}</p>
            </div>
        @else
            <div class="management-list">
                {{-- References only: subjects are customer content and render
                     on the ticket page, where the view is audited per resource. --}}
                @foreach ($coveredTickets as $ticket)
                    <a class="management-link" href="{{ route('operator.break-glass.tickets.show', [$grant, $ticket]) }}">
                        <span>
                            <strong>{{ __('operator_break_glass.ticket.reference', ['id' => \App\Support\ReaderNumber::count($ticket->id)]) }}</strong>
                            <span class="lede">{!! __('operator_break_glass.grant.tickets.row', [
                                'status' => $valueHtml($ticketStatuses[$ticket->id]),
                                'elapsed' => e($ticket->created_at->diffForHumans()),
                            ]) !!}</span>
                        </span>
                        <span class="management-action">{{ __('operator_break_glass.grant.tickets.view') }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
</x-layouts.operator>
