<x-layouts.app :title="__('tickets.bulk.confirm.document_title')" :agent="$agent" :account="$account">
    <x-page-header
        :title="__('tickets.bulk.confirm.title')"
        :subtitle="__('tickets.bulk.confirm.subtitle', ['action' => $actionLabel])" />

    <section aria-labelledby="ticket-bulk-review-heading">
        <div class="section-heading">
            <div>
                <h2 id="ticket-bulk-review-heading">{{ __('tickets.bulk.confirm.heading') }}</h2>
                <p>{{ __('tickets.bulk.confirm.summary', ['changed' => $changedCount, 'selected' => $items->count()]) }}</p>
            </div>
        </div>

        <p class="wf-bulk-caution">{{ __('tickets.bulk.confirm.undo_note') }}</p>

        <div class="table-wrap">
            <table class="wf-queue wf-bulk-review-table">
                <thead>
                    <tr>
                        <th scope="col">{{ __('tickets.columns.subject') }}</th>
                        <th scope="col">{{ __('tickets.columns.site') }}</th>
                        <th scope="col">{{ __('tickets.bulk.confirm.before') }}</th>
                        <th scope="col">{{ __('tickets.bulk.confirm.after') }}</th>
                        <th scope="col">{{ __('tickets.bulk.confirm.result') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <td class="wf-queue-subject" data-label="{{ __('tickets.columns.subject') }}">
                                <a href="{{ route('dashboard.tickets.show', ['ticket' => $item['ticket_id']] + $returnQuery) }}">{{ $item['subject'] }}</a>
                            </td>
                            <td data-label="{{ __('tickets.columns.site') }}">{{ $item['site'] }}</td>
                            <td data-label="{{ __('tickets.bulk.confirm.before') }}">{{ $item['before'] }}</td>
                            <td data-label="{{ __('tickets.bulk.confirm.after') }}">{{ $item['after'] }}</td>
                            <td data-label="{{ __('tickets.bulk.confirm.result') }}">
                                <span class="wf-queue-cobrowse" @if ($item['changed']) data-tone="attention" @endif>
                                    {{ $item['changed'] ? __('tickets.bulk.confirm.will_change') : __('tickets.bulk.confirm.no_change') }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="wf-bulk-confirm-actions">
            @if ($changedCount > 0)
                <form method="POST" action="{{ route('dashboard.tickets.bulk.store') }}">
                    @csrf
                    <input type="hidden" name="preview_token" value="{{ $token }}">
                    <button class="button" type="submit">{{ trans_choice('tickets.bulk.confirm.apply', $changedCount, ['count' => $changedCount]) }}</button>
                </form>
            @endif
            <a class="button secondary" href="{{ route('dashboard.tickets.index', $returnQuery) }}">{{ __('tickets.bulk.confirm.cancel') }}</a>
        </div>
    </section>
</x-layouts.app>
