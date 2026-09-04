<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ConversationCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Conversation $conversation) {}
}
