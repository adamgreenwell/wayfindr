<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OidcConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'account_id',
    'public_id',
    'configuration_version',
    'name',
    'issuer_url',
    'client_id',
    'client_secret',
    'is_enabled',
    'role_claim',
    'jit_provisioning_enabled',
])]
#[Hidden(['client_secret'])]
final class OidcConnection extends Model
{
    /** @use HasFactory<OidcConnectionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'is_enabled' => 'boolean',
            'jit_provisioning_enabled' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function identities(): HasMany
    {
        return $this->hasMany(OidcIdentity::class);
    }

    public function roleMappings(): HasMany
    {
        return $this->hasMany(OidcRoleMapping::class);
    }
}
