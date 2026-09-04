<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['site_id', 'last_conversation_agent_id', 'last_ticket_agent_id'])]
class SiteRoutingState extends Model
{
    protected $primaryKey = 'site_id';

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function lastConversationAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_conversation_agent_id');
    }

    public function lastTicketAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_ticket_agent_id');
    }
}
