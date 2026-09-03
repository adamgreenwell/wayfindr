<x-layouts.app :title="__('ticket_labels.title')" :agent="$agent" :account="$account">
            <x-page-header :title="__('ticket_labels.title')" :subtitle="__('ticket_labels.subtitle')" :back-href="route('dashboard.account.show')" :back-label="__('ticket_labels.back')" />

            @if (session('status'))
                {{-- A catalogue key rather than a sentence -- see AgentTicketLabelController. --}}
                <p class="status-message">{{ __(session('status')) }}</p>
            @endif

            @error('label_name')
                <p class="field-error">{{ $message }}</p>
            @enderror

            @error('label')
                <p class="field-error">{{ $message }}</p>
            @enderror

            <section class="section" aria-labelledby="new-ticket-label-heading">
                <div class="section-header">
                    <h2 id="new-ticket-label-heading">{{ __('ticket_labels.create.heading') }}</h2>
                    <span class="lede">{{ __('ticket_labels.create.lede') }}</span>
                </div>

                <form class="section-form" method="POST" action="{{ route('dashboard.account.labels.store') }}">
                    @csrf

                    <div class="field">
                        <label for="new-label-name">{{ __('ticket_labels.create.name') }}</label>
                        <input id="new-label-name" name="label_name" type="text" value="{{ old('label_name') }}" maxlength="64" placeholder="{{ __('ticket_labels.create.name_placeholder') }}" required>
                    </div>

                    <button class="button" type="submit">{{ __('ticket_labels.create.submit') }}</button>
                </form>
            </section>

            <section class="section" aria-labelledby="ticket-labels-heading">
                <div class="section-header">
                    <h2 id="ticket-labels-heading">{{ __('ticket_labels.list.heading') }}</h2>
                    <span class="lede">{{ __('ticket_labels.list.total', ['count' => \App\Support\ReaderNumber::count($ticketLabels->count())]) }}</span>
                </div>

                @if ($ticketLabels->isEmpty())
                    <div class="empty empty-state">
                        <strong>{{ __('ticket_labels.empty.heading') }}</strong>
                        {{ __('ticket_labels.empty.body') }}
                        <div class="empty-state-actions">
                            <a class="button secondary" href="#new-ticket-label-heading">{{ __('ticket_labels.empty.action') }}</a>
                        </div>
                    </div>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('ticket_labels.list.column_label') }}</th>
                                    <th scope="col">{{ __('ticket_labels.list.column_slug') }}</th>
                                    @if ($canManageTickets)
                                        <th scope="col">{{ __('ticket_labels.list.column_usage') }}</th>
                                    @endif
                                    <th scope="col">{{ __('ticket_labels.list.column_manage') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($ticketLabels as $ticketLabel)
                                    @if ($canManageTickets)
                                        @php
                                            $labelTicketsUrl = route('dashboard.tickets.index', [
                                                'ticket_status' => 'all',
                                                'ticket_label' => $ticketLabel->slug,
                                            ]);
                                        @endphp
                                    @endif
                                    <tr>
                                        <td><strong>{{ $ticketLabel->name }}</strong></td>
                                        <td><code>{{ $ticketLabel->slug }}</code></td>
                                        @if ($canManageTickets)
                                            <td>
                                                {{ trans_choice('ticket_labels.usage.tickets', $ticketLabel->tickets_count, ['count' => \App\Support\ReaderNumber::count($ticketLabel->tickets_count)]) }}
                                                @if ($ticketLabel->visible_tickets_count > 0)
                                                    <a class="text-link" href="{{ $labelTicketsUrl }}">{{ trans_choice('ticket_labels.usage.view_visible', $ticketLabel->visible_tickets_count, ['count' => \App\Support\ReaderNumber::count($ticketLabel->visible_tickets_count)]) }}</a>
                                                @else
                                                    <span class="lede">{{ __('ticket_labels.usage.none_visible') }}</span>
                                                @endif
                                            </td>
                                        @endif
                                        <td>
                                            <form class="compact-form" method="POST" action="{{ route('dashboard.account.labels.update', $ticketLabel) }}">
                                                @csrf
                                                @method('PUT')
                                                <label class="sr-only" for="ticket-label-{{ $ticketLabel->id }}">{{ __('ticket_labels.manage.rename', ['name' => $ticketLabel->name]) }}</label>
                                                <input id="ticket-label-{{ $ticketLabel->id }}" name="label_name" value="{{ old('label_name', $ticketLabel->name) }}" maxlength="64" required>
                                                <button class="button secondary" type="submit">{{ __('ticket_labels.manage.save') }}</button>
                                            </form>
                                            @if ($canManageTickets && $ticketLabel->tickets_count > 0)
                                                <span class="lede">{{ trans_choice('ticket_labels.manage.in_use', $ticketLabel->tickets_count, ['count' => \App\Support\ReaderNumber::count($ticketLabel->tickets_count)]) }}</span>
                                            @else
                                                <form class="compact-form" method="POST" action="{{ route('dashboard.account.labels.destroy', $ticketLabel) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="button danger" type="submit">{{ __('ticket_labels.manage.delete') }}</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
</x-layouts.app>
