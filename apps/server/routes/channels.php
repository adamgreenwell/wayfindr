<?php

use App\Broadcasting\ConversationChannel;
use App\Broadcasting\SitePresenceChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('conversations.{supportCode}', ConversationChannel::class);
Broadcast::channel('sites.{siteId}.presence', SitePresenceChannel::class);
