<?php

namespace App\Models;

use App\Support\Sites\RatingTemplateDefinition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One published, immutable rating template definition (#1157).
 *
 * Responses point at the version they were answered under, so a version is
 * never rewritten: changing what a question means is publishing a new one.
 * The model refuses an update outright rather than trusting every caller to
 * remember that.
 */
#[Fillable(['site_id', 'version', 'definition', 'published_by_id', 'published_at'])]
class SiteRatingTemplateVersion extends Model
{
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('A published rating template version is immutable; publish a new version instead.');
        });
    }

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_id');
    }

    public function definition(): RatingTemplateDefinition
    {
        return RatingTemplateDefinition::fromStored($this->definition);
    }
}
