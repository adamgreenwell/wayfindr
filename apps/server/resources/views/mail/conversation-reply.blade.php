{!! $reply->body !!}

--
{!! __('visitor_mail.reply.signature', ['site' => $site->name]) !!}

@if ($site->inbound_address)
{!! __('visitor_mail.reply.reply_by_email') !!}
@elseif ($site->domain)
{!! __('visitor_mail.reply.continue_at', ['domain' => $site->domain]) !!}
@else
{!! __('visitor_mail.reply.continue_in_chat') !!}
@endif
