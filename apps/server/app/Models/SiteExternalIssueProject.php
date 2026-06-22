<?php

namespace App\Models;

use Database\Factories\SiteExternalIssueProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'account_id',
    'site_id',
    'external_issue_provider_connection_id',
    'project_key',
    'project_name',
    'web_url',
    'settings',
])]
class SiteExternalIssueProject extends Model
{
    /** @use HasFactory<SiteExternalIssueProjectFactory> */
    use HasFactory;

    private const ISSUE_CREATION_PROVIDERS = ['github', 'gitlab'];

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

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function providerConnection(): BelongsTo
    {
        return $this->belongsTo(ExternalIssueProviderConnection::class, 'external_issue_provider_connection_id');
    }

    public function providerLabel(): string
    {
        return $this->providerConnection?->providerLabel() ?? 'External tracker';
    }

    public function hasCapability(string $capability): bool
    {
        return $this->providerConnection?->hasCapability($capability) ?? false;
    }

    public function hasSupportedIssueCreationProvider(): bool
    {
        return in_array($this->providerConnection?->provider, self::ISSUE_CREATION_PROVIDERS, true);
    }

    public function supportsIssueCreationHandoff(): bool
    {
        return $this->hasSupportedIssueCreationProvider()
            && $this->providerConnection?->is_enabled === true
            && $this->hasCapability('create_issue');
    }

    /**
     * @return list<string>
     */
    public function capabilityLabels(): array
    {
        return $this->providerConnection?->capabilityLabels() ?? [];
    }
}
