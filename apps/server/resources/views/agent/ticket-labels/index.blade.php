<x-layouts.account :title="__('ticket_labels.title')">
            <x-page-header :title="__('ticket_labels.title')" :subtitle="__('ticket_labels.subtitle')" />

            @if (session('status'))
                {{-- A catalogue key rather than a sentence -- see AgentTicketLabelController. --}}
                <p class="status-message">{{ __(session('status')) }}</p>
            @endif

            @php
                // The create form and every row's rename form all post
                // `label_name`, so the flashed input cannot say which of them
                // failed. Each row form names its label, and only the form that
                // was submitted gets back the typed value, the message and
                // aria-invalid. Without it one rejected rename was painted into
                // every row and the create field, where a Save on any other row
                // would send it as that label's new name.
                $editingLabelId = is_scalar(old('editing_label')) ? (string) old('editing_label') : '';

                // A refused delete ("remove this label from …") belongs to the
                // row whose Delete sent it. At the top of the page it named no
                // label, so with several in use nobody could tell which one it
                // meant. It stays up here only when no row claims it.
                $deletingLabelId = is_scalar(old('deleting_label')) ? (string) old('deleting_label') : '';
                $deleteRefusalOnRow = $deletingLabelId !== ''
                    && $ticketLabels->contains(fn ($ticketLabel): bool => (string) $ticketLabel->id === $deletingLabelId);
            @endphp

            @unless ($deleteRefusalOnRow)
                @error('label')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            @endunless

            <section class="section" aria-labelledby="new-ticket-label-heading">
                <div class="section-header">
                    <div>
                        <h2 id="new-ticket-label-heading">{{ __('ticket_labels.create.heading') }}</h2>
                        <p class="lede">{{ __('ticket_labels.create.lede') }}</p>
                    </div>
                </div>

                <form class="section-form" method="POST" action="{{ route('dashboard.account.labels.store') }}">
                    @csrf

                    <div class="field">
                        <label for="new-label-name">{{ __('ticket_labels.create.name') }}</label>
                        <input id="new-label-name" name="label_name" type="text" value="{{ $editingLabelId === '' ? old('label_name') : '' }}" maxlength="64" placeholder="{{ __('ticket_labels.create.name_placeholder') }}" @if ($editingLabelId === '') @error('label_name') aria-invalid="true" aria-describedby="new-label-name-error" autofocus @enderror @endif required>
                        @if ($editingLabelId === '')
                            @error('label_name')<p id="new-label-name-error" class="field-error">{{ $message }}</p>@enderror
                        @endif
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
                                    @php
                                        $isEditingLabel = $editingLabelId === (string) $ticketLabel->id;
                                        $isRefusedDelete = $deleteRefusalOnRow && $deletingLabelId === (string) $ticketLabel->id && $errors->has('label');
                                    @endphp
                                    @if ($canManageTickets)
                                        @php
                                            $labelTicketsUrl = route('dashboard.tickets.index', [
                                                'ticket_status' => 'all',
                                                'ticket_label' => $ticketLabel->slug,
                                            ]);
                                        @endphp
                                    @endif
                                    <tr>
                                        <td><strong lang="">{{ $ticketLabel->name }}</strong></td>
                                        <td><code lang="">{{ $ticketLabel->slug }}</code></td>
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
                                                <input type="hidden" name="editing_label" value="{{ $ticketLabel->id }}">
                                                <label class="sr-only" for="ticket-label-{{ $ticketLabel->id }}">{{ __('ticket_labels.manage.rename', ['name' => $ticketLabel->name]) }}</label>
                                                <input id="ticket-label-{{ $ticketLabel->id }}" name="label_name" value="{{ $isEditingLabel ? old('label_name') : $ticketLabel->name }}" maxlength="64" @if ($isEditingLabel) @error('label_name') aria-invalid="true" aria-describedby="ticket-label-{{ $ticketLabel->id }}-error" autofocus @enderror @endif lang="" required>
                                                <button class="button secondary" type="submit">{{ __('ticket_labels.manage.save') }}</button>
                                            </form>
                                            @if ($isEditingLabel)
                                                @error('label_name')<p id="ticket-label-{{ $ticketLabel->id }}-error" class="field-error">{{ $message }}</p>@enderror
                                            @endif
                                            @if ($canManageTickets && $ticketLabel->tickets_count > 0)
                                                <span class="lede">{{ trans_choice('ticket_labels.manage.in_use', $ticketLabel->tickets_count, ['count' => \App\Support\ReaderNumber::count($ticketLabel->tickets_count)]) }}</span>
                                            @else
                                                <form class="compact-form" method="POST" action="{{ route('dashboard.account.labels.destroy', $ticketLabel) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <input type="hidden" name="deleting_label" value="{{ $ticketLabel->id }}">
                                                    <button class="button danger" type="submit" @if ($isRefusedDelete) aria-describedby="ticket-label-{{ $ticketLabel->id }}-delete-error" autofocus @endif>{{ __('ticket_labels.manage.delete') }}</button>
                                                </form>
                                            @endif
                                            @if ($isRefusedDelete)
                                                {{-- A sentence in a nowrap cell: without cell-wrap it ran
                                                     past the card's edge instead of under the button. --}}
                                                <p id="ticket-label-{{ $ticketLabel->id }}-delete-error" class="field-error cell-wrap">{{ $errors->first('label') }}</p>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
</x-layouts.account>
