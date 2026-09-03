<?php

namespace App\Models;

use App\Enums\AccountRole;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\PlatformRole;
use App\Notifications\ResetPasswordLink;
use Carbon\CarbonImmutable;
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
use Throwable;

#[Fillable(['account_id', 'account_role', 'platform_role', 'name', 'email', 'password', 'deactivated_at', 'alert_preferences', 'locale', 'timezone'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ALERT_MODE_ALL = 'all';

    public const ALERT_MODE_ASSIGNED = 'assigned';

    public const ALERT_MODE_QUIET = 'quiet';

    public const ALERT_CADENCE_IMMEDIATE = 'immediate';

    public const ALERT_CADENCE_UNATTENDED = 'unattended';

    public const ALERT_CADENCE_DIGEST = 'digest';

    public const ALERT_DIGEST_DELIVERY_NOT_RUN = 'not_run';

    public const ALERT_DIGEST_DELIVERY_QUEUED = 'queued';

    public const ALERT_DIGEST_DELIVERY_NO_ALERTS = 'no_alerts';

    public const ALERT_DIGEST_DELIVERY_FAILED = 'failed';

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
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_used_timestep' => 'integer',
        ];
    }

    public function hasTwoFactorAuthentication(): bool
    {
        return $this->two_factor_confirmed_at !== null
            && is_string($this->two_factor_secret)
            && $this->two_factor_secret !== '';
    }

    /**
     * @return array<string, string>
     */
    public static function alertModeOptions(): array
    {
        return [
            self::ALERT_MODE_ALL => __('profile.alerts.modes.all'),
            self::ALERT_MODE_ASSIGNED => __('profile.alerts.modes.assigned'),
            self::ALERT_MODE_QUIET => __('profile.alerts.modes.quiet'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function alertCadenceOptions(): array
    {
        return [
            self::ALERT_CADENCE_IMMEDIATE => __('profile.alerts.cadences.immediate'),
            self::ALERT_CADENCE_UNATTENDED => __('profile.alerts.cadences.unattended'),
            self::ALERT_CADENCE_DIGEST => __('profile.alerts.cadences.digest'),
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function oidcIdentities(): HasMany
    {
        return $this->hasMany(OidcIdentity::class);
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

    /**
     * Send the reset link off the request rather than inside it.
     *
     * Overridden so the forgot-password form answers in the same time, and the
     * same way, whether or not the address belongs to anybody.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordLink($token));
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

    public function alertCadence(): string
    {
        $cadence = data_get($this->alert_preferences, 'cadence');

        return is_string($cadence) && array_key_exists($cadence, self::alertCadenceOptions())
            ? $cadence
            : self::ALERT_CADENCE_IMMEDIATE;
    }

    public function wantsImmediateAlertEmail(): bool
    {
        return $this->alertEmailEnabled()
            && $this->alertCadence() === self::ALERT_CADENCE_IMMEDIATE;
    }

    public function wantsUnattendedAlertEmail(): bool
    {
        return $this->alertEmailEnabled()
            && $this->alertCadence() === self::ALERT_CADENCE_UNATTENDED;
    }

    /**
     * @param  array{status: string, candidate_count?: int, message?: string, error?: string|null, last_attempted_at?: string}  $delivery
     */
    public function recordAlertDigestDelivery(array $delivery): void
    {
        $preferences = $this->alert_preferences ?? [];

        $this->forceFill([
            'alert_preferences' => [
                ...$preferences,
                'digest_delivery' => array_filter([
                    'status' => $delivery['status'],
                    'candidate_count' => (int) ($delivery['candidate_count'] ?? 0),
                    'message' => $delivery['message'] ?? null,
                    'error' => $delivery['error'] ?? null,
                    'last_attempted_at' => $delivery['last_attempted_at'] ?? now()->toISOString(),
                ], fn ($value): bool => $value !== null),
            ],
        ])->save();
    }

    /**
     * @return array{status: string, label: string, candidate_count: int, message: string, error: string|null, last_attempted_at: CarbonImmutable|null}
     */
    public function alertDigestDeliveryStatus(): array
    {
        $delivery = data_get($this->alert_preferences, 'digest_delivery', []);
        $status = data_get($delivery, 'status');
        $status = is_string($status) && in_array($status, [
            self::ALERT_DIGEST_DELIVERY_QUEUED,
            self::ALERT_DIGEST_DELIVERY_NO_ALERTS,
            self::ALERT_DIGEST_DELIVERY_FAILED,
        ], true)
            ? $status
            : self::ALERT_DIGEST_DELIVERY_NOT_RUN;

        $candidateCount = (int) data_get($delivery, 'candidate_count', 0);
        // Derived from the STATUS, never read back from the stored prose. The
        // scheduler persists an English sentence with every run, so printing
        // what it stored means a German agent reads English for every digest
        // that has actually happened -- and the translated version would only
        // ever appear on an install where the scheduler had never run, which is
        // exactly backwards.
        $message = match ($status) {
            self::ALERT_DIGEST_DELIVERY_QUEUED => $this->digestQueuedMessage($candidateCount),
            self::ALERT_DIGEST_DELIVERY_NO_ALERTS => __('profile.digest.no_alerts_message'),
            self::ALERT_DIGEST_DELIVERY_FAILED => __('profile.digest.failed_message'),
            default => __('profile.digest.never_message'),
        };

        $error = data_get($delivery, 'error');
        $attemptedAt = data_get($delivery, 'last_attempted_at');

        return [
            'status' => $status,
            'label' => match ($status) {
                self::ALERT_DIGEST_DELIVERY_QUEUED => __('profile.digest.queued_label'),
                self::ALERT_DIGEST_DELIVERY_NO_ALERTS => __('profile.digest.no_alerts_label'),
                self::ALERT_DIGEST_DELIVERY_FAILED => __('profile.digest.failed_label'),
                default => __('profile.digest.never_label'),
            },
            'candidate_count' => $candidateCount,
            'message' => $message,
            'error' => is_string($error) && trim($error) !== '' ? $error : null,
            'last_attempted_at' => $this->parseAlertDigestAttemptedAt($attemptedAt),
        ];
    }

    public static function digestQueuedMessage(int $candidateCount): string
    {
        // `trans_choice` rather than a hand-built plural: English pluralises by
        // adding an s and German does not, so the rule has to live in the
        // catalogue with the words rather than in the code beside them.
        return trans_choice('profile.digest.queued_message', $candidateCount, ['count' => $candidateCount]);
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

    public function conversationReadStates(): HasMany
    {
        return $this->hasMany(ConversationReadState::class);
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

    private function parseAlertDigestAttemptedAt(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
