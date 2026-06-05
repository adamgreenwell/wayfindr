<p>Wayfindr mail is configured well enough to send this smoke test.</p>

<p>
    Application:
    <strong>{{ $appName }}</strong>
</p>

@if ($appUrl !== '')
    <p>URL: <a href="{{ $appUrl }}">{{ $appUrl }}</a></p>
@endif

<p>Sent at {{ $sentAt->toIso8601String() }}.</p>
