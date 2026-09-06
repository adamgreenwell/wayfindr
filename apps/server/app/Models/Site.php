<?php

namespace App\Models;

use App\Enums\SiteColor;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Fillable(['account_id', 'name', 'domain', 'color', 'public_key', 'settings', 'inbound_address'])]
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'archived_at' => 'datetime',
            'color' => SiteColor::class,
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * The colour this site is recognised by (ADR 0014).
     *
     * Falls back deterministically rather than returning null, because every
     * surface that shows a site -- queue rail, transcript chip, widget accent --
     * needs an answer, and a site that changed colour between page loads would
     * defeat the point of an agent learning it.
     */
    public function resolvedColor(): SiteColor
    {
        return $this->color ?? SiteColor::forPosition((int) $this->id);
    }

    /**
     * The colour a new site should take, so one account's sites stay distinct.
     */
    public static function nextColorForAccount(int $accountId): SiteColor
    {
        return SiteColor::forPosition(
            static::query()->where('account_id', $accountId)->count()
        );
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
        if ($this->relationLoaded('supportAgents')) {
            return $this->eligibleLoadedSupportAgents()->isNotEmpty();
        }

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

        if ($this->relationLoaded('supportAgents')) {
            return $this->eligibleLoadedSupportAgents()->contains('id', $agent->id);
        }

        return $this->eligibleSupportAgents()->whereKey($agent->id)->exists();
    }

    /** @return Collection<int, User> */
    private function eligibleLoadedSupportAgents(): Collection
    {
        return $this->supportAgents->filter(fn (User $candidate): bool => ! $candidate->isDeactivated()
            && (int) $candidate->account_id === (int) $this->account_id);
    }

    /**
     * Change this site's settings under a row lock.
     *
     * `settings` is one JSON column, so every writer of it does a read, a
     * modify and a write of the WHOLE value. Two settings forms saved close
     * together therefore lose one of the changes -- and worse than losing it,
     * a request that loaded the site before a revocation and saved after it
     * puts the revoked value back. An operator switching presence off and then
     * saving the rating prompt could restore `presence.enabled` and start
     * collecting again, having done nothing that looks like enabling it.
     *
     * The mutation receives the settings as they are AT THE MOMENT OF THE
     * WRITE, read under the lock, rather than as they were when the request
     * arrived. Callers that also need to act on the result -- deleting rows a
     * setting was collecting -- pass a callback that does both, so their work
     * is inside the same transaction and the same lock.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    public function mutateSettings(callable $mutate): array
    {
        return DB::transaction(function () use ($mutate): array {
            $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->first() ?? $this;

            $settings = $mutate(is_array($locked->settings) ? $locked->settings : []);

            $locked->forceFill(['settings' => $settings])->save();

            // The caller holds this instance and will keep reading it.
            $this->setRawAttributes($locked->getAttributes(), true);

            return $settings;
        });
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Sites the widget will still serve.
     *
     * Archiving a site takes it out of service without destroying anything, so
     * every public entry point resolves through this scope rather than testing
     * the column itself - see App\Support\WidgetSiteResolver.
     *
     * @return Builder<Site>
     */
    public function scopeServable(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * @return Builder<Site>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    /**
     * Sites this agent works in.
     *
     * Excludes archived sites, because almost every caller is an operational
     * surface - queues, dashboards, lookups - where retired work must not
     * appear. The default is the safe one on purpose: a new caller that forgets
     * to think about archiving gets the right behaviour, and the two surfaces
     * that genuinely need retired sites ask for them by name below.
     *
     * @return Builder<Site>
     */
    public function scopeVisibleToAgent(Builder $query, User $agent): Builder
    {
        return $query->servable()->visibleToAgentIncludingArchived($agent);
    }

    /**
     * The same visibility rules, but including archived sites.
     *
     * Only for surfaces that are about a site's history rather than its current
     * support work: the site list itself, which can filter to archived, and the
     * audit log, whose records outlive the site being in service.
     *
     * @return Builder<Site>
     */
    public function scopeVisibleToAgentIncludingArchived(Builder $query, User $agent): Builder
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

    public function proactiveMessageRules(): HasMany
    {
        return $this->hasMany(ProactiveMessageRule::class);
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

    public function routingState(): HasOne
    {
        return $this->hasOne(SiteRoutingState::class);
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
