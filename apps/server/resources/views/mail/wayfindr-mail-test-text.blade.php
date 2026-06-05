Wayfindr mail is configured well enough to send this smoke test.

Application: {{ $appName }}
@if ($appUrl !== '')
URL: {{ $appUrl }}
@endif
Sent at {{ $sentAt->toIso8601String() }}.
