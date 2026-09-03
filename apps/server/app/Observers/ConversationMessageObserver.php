<?php

namespace App\Observers;

use App\Models\ConversationMessage;
use App\Support\Webhooks\OutboundWebhookPublisher;

class ConversationMessageObserver
{
    public function created(ConversationMessage $message): void
    {
        app(OutboundWebhookPublisher::class)->messageCreated($message);
    }
}
