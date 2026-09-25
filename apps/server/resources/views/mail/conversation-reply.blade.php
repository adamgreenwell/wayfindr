{{-- Plain text, so {!! !!} throughout: {{ }} would put "We&#039;ve" in an inbox. --}}
{!! $reply->body !!}

--
{!! $site->name !!} support
@if ($site->inbound_address)

Reply to this email and it will reach the same conversation.
@endif
