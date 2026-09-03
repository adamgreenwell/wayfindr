@if ($status)
    <div class="notice-copy notice-copy-bordered">
        <p>
            <strong>
                @switch($status['status'] ?? '')
                    @case('succeeded') {{ __('operator.backups.restore_status.succeeded') }} @break
                    @case('failed') {{ __('operator.backups.restore_status.failed') }} @break
                    @default {{ __('operator.backups.restore_status.running') }} @break
                @endswitch
            </strong>
        </p>
        @if (! empty($status['message']))
            <p lang="">{{ $status['message'] }}</p>
        @endif
        <p class="lede">
            @if (! empty($status['archive']))<span lang="">{{ $status['archive'] }}</span> · @endif
            @if (! empty($status['triggered_by_name']))
                {!! __('operator.backups.restore_status.triggered_by', [
                    'name' => '<span lang="">'.e($status['triggered_by_name']).'</span>',
                ]) !!}
            @else
                {{ __('operator.backups.restore_status.triggered') }}
            @endif
            @if (! empty($status['updated_at'])) · {{ \Illuminate\Support\Carbon::parse($status['updated_at'])->diffForHumans() }}@endif
        </p>
    </div>
@endif
