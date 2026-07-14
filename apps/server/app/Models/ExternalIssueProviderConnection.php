<?php

namespace App\Models;

use App\Support\ExternalIssueCapability;
use App\Support\ExternalIssueProvider;
use Database\Factories\ExternalIssueProviderConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'account_id',
    'provider',
    'name',
    'base_url',
    'credentials',
    'capabilities',
    'settings',
    'is_enabled',
    'last_checked_at',
])]
class ExternalIssueProviderConnection extends Model
{
    /** @use HasFactory<ExternalIssueProviderConnectionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'capabilities' => 'array',
            'settings' => 'array',
            'is_enabled' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function siteProjects(): HasMany
    {
        return $this->hasMany(SiteExternalIssueProject::class);
    }

    public function providerLabel(): string
    {
        return ExternalIssueProvider::label($this->provider);
    }

    public function hasCapability(string $capability): bool
    {
        return (bool) data_get(ExternalIssueCapability::flags($this->capabilities), $capability, false);
    }

    /**
     * @return list<string>
     */
    public function capabilityLabels(): array
    {
        return ExternalIssueCapability::activeLabels($this->capabilities);
    }

    public function hasWebhookSecret(): bool
    {
        return filled(data_get($this->credentials, 'webhook_secret'));
    }

    public function hasVerifiedInboundWebhook(): bool
    {
        return data_get($this->settings, 'inbound_webhook.verified') === true
            && $this->last_checked_at !== null;
    }

    /**
     * Record only safe aggregate evidence that a provider delivery passed
     * authentication. Payloads, signatures, tokens, and secrets never belong
     * in connection health metadata.
     */
    public function recordInboundWebhookDelivery(string $event, int $statusCode): void
    {
        $settings = $this->settings ?? [];
        $settings['inbound_webhook'] = [
            'verified' => true,
            'event' => trim($event) !== '' ? trim($event) : 'unknown',
            'status_code' => $statusCode,
        ];

        $this->forceFill([
            'settings' => $settings,
            'last_checked_at' => now(),
        ])->save();
    }

    /**
     * The inbound webhook receiver URL for this connection's provider, to
     * configure on the provider side. Null for providers without a receiver.
     * The URL is not a secret — inbound authenticity rests on the HMAC/token,
     * not the endpoint — so it is safe to display.
     */
    public function inboundWebhookUrl(): ?string
    {
        $routeName = match ($this->provider) {
            'github' => 'integrations.github.webhook',
            'gitlab' => 'integrations.gitlab.webhook',
            'jira' => 'integrations.jira.webhook',
            default => null,
        };

        return $routeName === null ? null : route($routeName, $this);
    }
}
