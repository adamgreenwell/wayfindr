<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountPermission;
use Database\Factories\CustomRoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'name', 'name_key', 'permissions'])]
final class CustomRole extends Model
{
    /** @use HasFactory<CustomRoleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function hasPermission(AccountPermission $permission): bool
    {
        return in_array($permission->value, $this->permissionValues(), true);
    }

    /** @return list<string> */
    public function permissionValues(): array
    {
        $permissions = is_array($this->permissions) ? $this->permissions : [];

        return collect($permissions)
            ->filter(fn (mixed $permission): bool => is_string($permission))
            ->intersect(AccountPermission::delegableValues())
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
