<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AccountRole;
use App\Enums\PlatformRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['account_id', 'account_role', 'platform_role', 'name', 'email', 'password', 'deactivated_at', 'alert_preferences'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ALERT_MODE_ALL = 'all';

    public const ALERT_MODE_ASSIGNED = 'assigned';

    public const ALERT_MODE_QUIET = 'quiet';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_role' => AccountRole::class,
            'platform_role' => PlatformRole::class,
            'alert_preferences' => 'array',
            'deactivated_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function alertModeOptions(): array
    {
        return [
            self::ALERT_MODE_ALL => 'All site alerts I can support',
            self::ALERT_MODE_ASSIGNED => 'Only conversations and tickets assigned to me',
            self::ALERT_MODE_QUIET => 'Quiet mode',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function hasAccountRole(AccountRole $role): bool
    {
        return $this->account_role === $role;
    }

    public function isOwner(): bool
    {
        return $this->hasAccountRole(AccountRole::Owner);
    }

    public function isAdmin(): bool
    {
        return $this->isOwner() || $this->hasAccountRole(AccountRole::Admin);
    }

    public function isAgent(): bool
    {
        return $this->isAdmin() || $this->hasAccountRole(AccountRole::Agent);
    }

    public function isPlatformOperator(): bool
    {
        return $this->platform_role === PlatformRole::Operator;
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    public function alertMode(): string
    {
        $mode = data_get($this->alert_preferences, 'mode');

        return is_string($mode) && array_key_exists($mode, self::alertModeOptions())
            ? $mode
            : self::ALERT_MODE_ALL;
    }

    public function alertEmailEnabled(): bool
    {
        return data_get($this->alert_preferences, 'email') === true;
    }

    public function shouldReceiveConversationAlert(Conversation $conversation): bool
    {
        if ($this->isDeactivated() || $this->alertMode() === self::ALERT_MODE_QUIET) {
            return false;
        }

        if ($this->alertMode() === self::ALERT_MODE_ASSIGNED) {
            return (int) $conversation->assigned_agent_id === $this->id;
        }

        return true;
    }

    public function shouldReceiveTicketAssignmentAlert(Ticket $ticket): bool
    {
        return ! $this->isDeactivated()
            && $this->alertMode() !== self::ALERT_MODE_QUIET
            && (int) $ticket->assignee_id === $this->id;
    }

    public function assignedConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'assigned_agent_id');
    }

    public function supportedSites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class)->withTimestamps();
    }

    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assignee_id');
    }

    public function requestedCobrowseSessions(): HasMany
    {
        return $this->hasMany(CobrowseSession::class, 'requested_by_id');
    }

    public function sentConversationMessages(): MorphMany
    {
        return $this->morphMany(ConversationMessage::class, 'sender');
    }

    public function auditEvents(): MorphMany
    {
        return $this->morphMany(AuditEvent::class, 'actor');
    }
}
