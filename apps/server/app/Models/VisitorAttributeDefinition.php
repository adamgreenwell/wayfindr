<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VisitorAttributeType;
use Database\Factories\VisitorAttributeDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'key', 'label', 'type'])]
final class VisitorAttributeDefinition extends Model
{
    /** @use HasFactory<VisitorAttributeDefinitionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['type' => VisitorAttributeType::class];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function valueFor(Visitor $visitor): ?string
    {
        $context = data_get($visitor->metadata, 'context');

        if (! is_array($context) || ! array_key_exists($this->key, $context)) {
            return null;
        }

        return $this->type->normalize($context[$this->key]);
    }
}
