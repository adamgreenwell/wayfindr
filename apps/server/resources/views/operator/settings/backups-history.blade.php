<x-layouts.app title="Backup history">
    <p><a class="text-link" href="{{ route('operator.settings.backups.edit') }}">Back to backup settings</a></p>

    <x-page-header
        title="Backup history"
        subtitle="Every recorded backup run — the scheduled backup and the operator “run now” both land here." />

    <section class="section" aria-labelledby="backup-history-heading">
        <h2 id="backup-history-heading" class="sr-only">Recorded runs</h2>

        @if ($runs->isEmpty())
            <p class="empty">No backup runs recorded yet. The scheduled backup and the “Run a backup now” button both record here.</p>
        @else
            @if ($atLimit)
                <p class="table-note">Showing the {{ $limit }} most recent runs.</p>
            @endif

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Status</th>
                            <th scope="col">Started</th>
                            <th scope="col">Size</th>
                            <th scope="col">Offsite</th>
                            <th scope="col">Triggered by</th>
                            <th scope="col">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($runs as $run)
                            <tr>
                                <td>
                                    @switch($run->status)
                                        @case('succeeded') Succeeded @break
                                        @case('failed') Failed @break
                                        @default Running @break
                                    @endswitch
                                </td>
                                <td>
                                    {{ $run->started_at?->toDayDateTimeString() }}
                                    <span class="table-note">{{ $run->started_at?->diffForHumans() }}</span>
                                </td>
                                <td>{{ $run->size_bytes ? number_format($run->size_bytes / 1048576, 1).' MB' : '—' }}</td>
                                <td>{{ $run->offsite_key ? 'Uploaded to ['.$run->offsite_disk.']' : 'Local only' }}</td>
                                <td>{{ $run->triggeredBy?->name ?? 'Scheduled' }}</td>
                                <td style="white-space: normal; min-width: 240px;">{{ $run->message ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-layouts.app>
