<?php

namespace App\Models;

use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'slug', 'requires_two_factor'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'requires_two_factor' => 'boolean',
        ];
    }

    public function agents(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function customRoles(): HasMany
    {
        return $this->hasMany(CustomRole::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function slaPolicies(): HasMany
    {
        return $this->hasMany(SlaPolicy::class);
    }

    public function automationRules(): HasMany
    {
        return $this->hasMany(AutomationRule::class);
    }

    public function automationMacros(): HasMany
    {
        return $this->hasMany(AutomationMacro::class);
    }

    public function automationRuleExecutions(): HasMany
    {
        return $this->hasMany(AutomationRuleExecution::class);
    }

    public function ticketBulkActionRuns(): HasMany
    {
        return $this->hasMany(TicketBulkActionRun::class);
    }

    public function slaClocks(): HasMany
    {
        return $this->hasMany(SlaClock::class);
    }

    public function ticketLabels(): HasMany
    {
        return $this->hasMany(TicketLabel::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    public function replyTemplates(): HasMany
    {
        return $this->hasMany(ReplyTemplate::class);
    }

    public function ticketExternalLinks(): HasMany
    {
        return $this->hasMany(TicketExternalLink::class);
    }

    /**
     * Programmatic credentials for this account (ADR 0018).
     */
    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    /**
     * Destinations this account deliberately sends thin events to (ADR 0020).
     */
    public function outboundWebhookEndpoints(): HasMany
    {
        return $this->hasMany(OutboundWebhookEndpoint::class);
    }

    public function externalIssueProviderConnections(): HasMany
    {
        return $this->hasMany(ExternalIssueProviderConnection::class);
    }

    public function siteExternalIssueProjects(): HasMany
    {
        return $this->hasMany(SiteExternalIssueProject::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }

    public function oidcConnection(): HasOne
    {
        return $this->hasOne(OidcConnection::class);
    }
}
