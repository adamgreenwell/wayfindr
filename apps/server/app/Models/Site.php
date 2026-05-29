<?php

namespace App\Models;

use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['account_id', 'name', 'domain', 'public_key', 'settings'])]
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function supportAgents(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function eligibleSupportAgents(): BelongsToMany
    {
        return $this->supportAgents()
            ->where('users.account_id', $this->account_id)
            ->whereNull('users.deactivated_at');
    }

    public function hasExplicitSupportAgents(): bool
    {
        return $this->eligibleSupportAgents()->exists();
    }

    public function supportsAgent(User $agent): bool
    {
        if ($agent->isDeactivated()) {
            return false;
        }

        if (! $agent->account_id || (int) $agent->account_id !== (int) $this->account_id) {
            return false;
        }

        if (! $this->hasExplicitSupportAgents()) {
            return true;
        }

        return $this->eligibleSupportAgents()->whereKey($agent->id)->exists();
    }

    /**
     * @return Builder<Site>
     */
    public function scopeVisibleToAgent(Builder $query, User $agent): Builder
    {
        return $query
            ->where('account_id', $agent->account_id)
            ->where(function (Builder $query) use ($agent): void {
                $query
                    ->whereDoesntHave('supportAgents', fn (Builder $query) => $query
                        ->where('users.account_id', $agent->account_id)
                        ->whereNull('users.deactivated_at'))
                    ->orWhereHas('supportAgents', fn (Builder $query) => $query
                        ->where('users.account_id', $agent->account_id)
                        ->whereNull('users.deactivated_at')
                        ->whereKey($agent->id));
            });
    }

    public function visitors(): HasMany
    {
        return $this->hasMany(Visitor::class);
    }

    public function latestVisitor(): HasOne
    {
        return $this->hasOne(Visitor::class)
            ->ofMany([
                'last_seen_at' => 'max',
                'id' => 'max',
            ], fn (Builder $query) => $query->where('anonymous_id', 'not like', 'tester-site-%'));
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function ticketExternalLinks(): HasMany
    {
        return $this->hasMany(TicketExternalLink::class);
    }

    public function externalIssueProjects(): HasMany
    {
        return $this->hasMany(SiteExternalIssueProject::class);
    }

    public function cobrowseSessions(): HasMany
    {
        return $this->hasMany(CobrowseSession::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }
}
