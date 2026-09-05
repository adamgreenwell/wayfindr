<?php

use App\Broadcasting\AccountAgentAlertChannel;
use App\Broadcasting\AgentConnectionChannel;
use App\Broadcasting\ConversationChannel;
use App\Broadcasting\SitePresenceChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('agents.{agentId}', AgentConnectionChannel::class);
Broadcast::channel('visible-agents.{agentId}', AgentConnectionChannel::class);
Broadcast::channel('accounts.{accountId}.agents.{agentId}.alerts', AccountAgentAlertChannel::class);
Broadcast::channel('conversations.{supportCode}', ConversationChannel::class);
Broadcast::channel('sites.{siteId}.presence', SitePresenceChannel::class);
