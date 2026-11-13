<?php

namespace App\Models;

use App\Support\Sites\RatingTemplateDefinition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A site's editable rating template draft (#1157).
 *
 * Only the draft lives here. Editing it never reaches a visitor: what a visitor
 * sees is always a published {@see SiteRatingTemplateVersion}.
 */
#[Fillable(['site_id', 'draft'])]
class SiteRatingTemplate extends Model
{
    protected function casts(): array
    {
        return ['draft' => 'array'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function definition(): RatingTemplateDefinition
    {
        return RatingTemplateDefinition::fromStored($this->draft);
    }
}
