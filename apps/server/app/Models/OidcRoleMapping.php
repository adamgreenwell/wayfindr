<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountRole;
use Database\Factories\OidcRoleMappingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'oidc_connection_id',
    'claim_value',
    'built_in_role',
    'custom_role_id',
])]
final class OidcRoleMapping extends Model
{
    /** @use HasFactory<OidcRoleMappingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['built_in_role' => AccountRole::class];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(OidcConnection::class, 'oidc_connection_id');
    }

    public function customRole(): BelongsTo
    {
        return $this->belongsTo(CustomRole::class);
    }
}
