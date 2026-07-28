@if ($status)
    <div class="notice-copy notice-copy-bordered">
        <p>
            <strong>
                @switch($status['status'] ?? '')
                    @case('succeeded') Last restore: succeeded @break
                    @case('failed') Last restore: failed @break
                    @default Restore in progress… @break
                @endswitch
            </strong>
        </p>
        @if (! empty($status['message']))
            <p>{{ $status['message'] }}</p>
        @endif
        <p class="lede">
            @if (! empty($status['archive'])){{ $status['archive'] }} · @endif
            @if (! empty($status['triggered_by_name']))Triggered by {{ $status['triggered_by_name'] }}@else Triggered @endif
            @if (! empty($status['updated_at'])) · {{ \Illuminate\Support\Carbon::parse($status['updated_at'])->diffForHumans() }}@endif
        </p>
    </div>
@endif
