<?php

namespace App\Models;

use Database\Factories\OutboundWebhookEndpointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OutboundWebhookEndpoint extends Model
{
    /** @use HasFactory<OutboundWebhookEndpointFactory> */
    use HasFactory;

    public const EVENT_CONVERSATION_OPENED = 'conversation.opened';

    public const EVENT_CONVERSATION_MESSAGE_CREATED = 'conversation.message.created';

    public const EVENT_TICKET_CREATED = 'ticket.created';

    public const EVENT_TICKET_CLOSED = 'ticket.closed';

    /** @var list<string> */
    public const EVENTS = [
        self::EVENT_CONVERSATION_OPENED,
        self::EVENT_CONVERSATION_MESSAGE_CREATED,
        self::EVENT_TICKET_CREATED,
        self::EVENT_TICKET_CLOSED,
    ];

    public const SECRET_PREFIX = 'whsec_';

    protected $fillable = [
        'account_id',
        'created_by_id',
        'name',
        'url',
        'secret',
        'secret_last_four',
        'events',
        'restricts_sites',
        'next_sequence',
        'disabled_at',
    ];

    protected function casts(): array
    {
        return [
            'url' => 'encrypted',
            'secret' => 'encrypted',
            'events' => 'array',
            'restricts_sites' => 'boolean',
            'disabled_at' => 'immutable_datetime',
        ];
    }

    /** @return array{plain: string, last_four: string} */
    public static function generateSecret(): array
    {
        $random = Str::random(48);

        return [
            'plain' => self::SECRET_PREFIX.$random,
            'last_four' => substr($random, -4),
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'outbound_webhook_endpoint_site');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(OutboundWebhookDelivery::class);
    }

    public function isEnabled(): bool
    {
        return $this->disabled_at === null;
    }

    public function subscribesTo(string $event): bool
    {
        return in_array($event, (array) $this->events, true);
    }

    public function secretHint(): string
    {
        return self::SECRET_PREFIX.'…'.$this->secret_last_four;
    }
}
