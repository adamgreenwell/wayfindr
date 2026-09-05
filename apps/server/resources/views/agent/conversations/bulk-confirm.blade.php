<x-layouts.app :title="__('conversations.bulk.confirm.document_title')" :agent="$agent" :account="$account">
    <x-page-header
        :title="__('conversations.bulk.confirm.title')"
        :subtitle="__('conversations.bulk.confirm.subtitle', ['action' => $actionLabel])" />

    <section aria-labelledby="conversation-bulk-review-heading">
        <div class="section-heading">
            <div>
                <h2 id="conversation-bulk-review-heading">{{ __('conversations.bulk.confirm.heading') }}</h2>
                <p>{{ __('conversations.bulk.confirm.summary', ['changed' => $changedCount, 'selected' => $items->count()]) }}</p>
            </div>
        </div>

        <p class="wf-bulk-caution">{{ __('conversations.bulk.confirm.undo_note') }}</p>

        <div class="table-wrap">
            <table class="wf-queue wf-bulk-review-table">
                <thead>
                    <tr>
                        <th scope="col">{{ __('conversations.columns.subject') }}</th>
                        <th scope="col">{{ __('conversations.columns.site') }}</th>
                        <th scope="col">{{ __('conversations.bulk.confirm.before') }}</th>
                        <th scope="col">{{ __('conversations.bulk.confirm.after') }}</th>
                        <th scope="col">{{ __('conversations.bulk.confirm.result') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <td class="wf-queue-subject" data-label="{{ __('conversations.columns.subject') }}">
                                <a href="{{ route('dashboard.conversations.show', ['supportCode' => $item['support_code'], 'from_queue' => '1'] + $returnQuery) }}">{{ $item['subject'] }}</a>
                            </td>
                            <td data-label="{{ __('conversations.columns.site') }}">{{ $item['site'] }}</td>
                            <td data-label="{{ __('conversations.bulk.confirm.before') }}">{{ $item['before'] }}</td>
                            <td data-label="{{ __('conversations.bulk.confirm.after') }}">{{ $item['after'] }}</td>
                            <td data-label="{{ __('conversations.bulk.confirm.result') }}">
                                <span class="wf-queue-cobrowse" @if ($item['changed']) data-tone="attention" @endif>
                                    {{ $item['changed'] ? __('conversations.bulk.confirm.will_change') : __('conversations.bulk.confirm.no_change') }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="wf-bulk-confirm-actions">
            @if ($changedCount > 0)
                <form method="POST" action="{{ route('dashboard.conversations.bulk.store') }}">
                    @csrf
                    <input type="hidden" name="preview_token" value="{{ $token }}">
                    <button class="button" type="submit">{{ trans_choice('conversations.bulk.confirm.apply', $changedCount, ['count' => $changedCount]) }}</button>
                </form>
            @endif
            <a class="button secondary" href="{{ route('dashboard.conversations.index', $returnQuery) }}">{{ __('conversations.bulk.confirm.cancel') }}</a>
        </div>
    </section>
</x-layouts.app>
