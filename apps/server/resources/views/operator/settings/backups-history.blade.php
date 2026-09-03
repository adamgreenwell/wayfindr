<x-layouts.operator :title="__('operator.backups.history.document_title')">
    <p><a class="text-link" href="{{ route('operator.settings.backups.edit') }}">{{ __('operator.backups.history.back') }}</a></p>

    <x-page-header
        :back-href="$backUrl ?? null"
        :back-label="$backLabel ?? __('operator.shell.back')"
        :title="__('operator.backups.history.title')"
        :subtitle="__('operator.backups.history.subtitle')" />

    <section class="section" aria-labelledby="backup-history-heading">
        <h2 id="backup-history-heading" class="sr-only">{{ __('operator.backups.history.heading') }}</h2>

        @if ($runs->isEmpty())
            <p class="empty">{{ __('operator.backups.history.empty') }}</p>
        @else
            @if ($atLimit)
                <p class="table-note">{{ __('operator.backups.history.limit', ['count' => \App\Support\ReaderNumber::count($limit)]) }}</p>
            @endif

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('operator.backups.history.status') }}</th>
                            <th scope="col">{{ __('operator.backups.started') }}</th>
                            <th scope="col">{{ __('operator.backups.size') }}</th>
                            <th scope="col">{{ __('operator.backups.offsite') }}</th>
                            <th scope="col">{{ __('operator.backups.history.triggered_by') }}</th>
                            <th scope="col">{{ __('operator.backups.history.details') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($runs as $run)
                            <tr>
                                <td>
                                    @switch($run->status)
                                        @case('succeeded') {{ __('operator.backups.succeeded') }} @break
                                        @case('failed') {{ __('operator.backups.failed') }} @break
                                        @default {{ __('operator.backups.running') }} @break
                                    @endswitch
                                </td>
                                <td>
                                    {{ $run->started_at === null ? '' : \App\Support\ReaderClock::dateTime($run->started_at) }}
                                    <span class="table-note">{{ $run->started_at?->diffForHumans() }}</span>
                                </td>
                                <td lang="">{{ $run->size_bytes ? \App\Support\ReaderNumber::decimal($run->size_bytes / 1048576, 1).' MB' : '—' }}</td>
                                <td>{!! $run->offsite_key
                                    ? __('operator.backups.uploaded_to', ['disk' => '<span lang="">'.e($run->offsite_disk).'</span>'])
                                    : e(__('operator.backups.local_only')) !!}</td>
                                <td>{!! $run->triggeredBy
                                    ? '<span lang="">'.e($run->triggeredBy->name).'</span>'
                                    : e(__('operator.backups.history.scheduled')) !!}</td>
                                <td style="white-space: normal; min-width: 240px;">
                                    @if ($run->message)<span lang="">{{ $run->message }}</span>@else — @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-layouts.operator>
