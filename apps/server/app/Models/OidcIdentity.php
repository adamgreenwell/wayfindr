<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OidcIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['oidc_connection_id', 'user_id', 'subject', 'last_signed_in_at'])]
final class OidcIdentity extends Model
{
    /** @use HasFactory<OidcIdentityFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'last_signed_in_at' => 'immutable_datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(OidcConnection::class, 'oidc_connection_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
