<x-layouts.app title="Ticket labels" :agent="$agent" :account="$account">
            <div class="section-header">
                <div>
                    <h1>Ticket labels</h1>
                    <p class="lede">Manage account-wide labels used for ticket triage and dashboard filters.</p>
                </div>
                <div class="section-actions">
                    <a class="button secondary" href="{{ route('dashboard.account.show') }}">Back to account</a>
                </div>
            </div>

            @if (session('status'))
                <p class="status-message">{{ session('status') }}</p>
            @endif

            @error('label_name')
                <p class="field-error">{{ $message }}</p>
            @enderror

            @error('label')
                <p class="field-error">{{ $message }}</p>
            @enderror

            <section class="section" aria-labelledby="new-ticket-label-heading">
                <div class="section-header">
                    <h2 id="new-ticket-label-heading">Create label</h2>
                    <span class="lede">Make a reusable triage label before a ticket needs it.</span>
                </div>

                <form class="section-form" method="POST" action="{{ route('dashboard.account.labels.store') }}">
                    @csrf

                    <div class="field">
                        <label for="new-label-name">Label name</label>
                        <input id="new-label-name" name="label_name" type="text" value="{{ old('label_name') }}" maxlength="64" placeholder="VIP Customer" required>
                    </div>

                    <button class="button" type="submit">Create label</button>
                </form>
            </section>

            <section class="section" aria-labelledby="ticket-labels-heading">
                <div class="section-header">
                    <h2 id="ticket-labels-heading">Labels</h2>
                    <span class="lede">{{ $ticketLabels->count() }} total</span>
                </div>

                @if ($ticketLabels->isEmpty())
                    <p class="empty">No ticket labels yet. Add labels from a ticket when a support thread needs triage context.</p>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">Label</th>
                                    <th scope="col">Slug</th>
                                    <th scope="col">Usage</th>
                                    <th scope="col">Manage</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($ticketLabels as $ticketLabel)
                                    @php
                                        $labelTicketsUrl = route('dashboard', [
                                            'ticket_status' => 'all',
                                            'ticket_label' => $ticketLabel->slug,
                                        ]).'#tickets';
                                    @endphp
                                    <tr>
                                        <td><strong>{{ $ticketLabel->name }}</strong></td>
                                        <td><code>{{ $ticketLabel->slug }}</code></td>
                                        <td>
                                            {{ $ticketLabel->tickets_count }} {{ \Illuminate\Support\Str::plural('ticket', $ticketLabel->tickets_count) }}
                                            @if ($ticketLabel->visible_tickets_count > 0)
                                                <a class="text-link" href="{{ $labelTicketsUrl }}">View {{ $ticketLabel->visible_tickets_count }} visible {{ \Illuminate\Support\Str::plural('ticket', $ticketLabel->visible_tickets_count) }}</a>
                                            @else
                                                <span class="lede">No visible tickets</span>
                                            @endif
                                        </td>
                                        <td>
                                            <form class="compact-form" method="POST" action="{{ route('dashboard.account.labels.update', $ticketLabel) }}">
                                                @csrf
                                                @method('PUT')
                                                <label class="sr-only" for="ticket-label-{{ $ticketLabel->id }}">Rename {{ $ticketLabel->name }}</label>
                                                <input id="ticket-label-{{ $ticketLabel->id }}" name="label_name" value="{{ old('label_name', $ticketLabel->name) }}" maxlength="64" required>
                                                <button class="button secondary" type="submit">Save label</button>
                                            </form>
                                            @if ($ticketLabel->tickets_count > 0)
                                                <span class="lede">In use on {{ $ticketLabel->tickets_count }} {{ \Illuminate\Support\Str::plural('ticket', $ticketLabel->tickets_count) }}</span>
                                            @else
                                                <form class="compact-form" method="POST" action="{{ route('dashboard.account.labels.destroy', $ticketLabel) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="button danger" type="submit">Delete unused</button>
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
