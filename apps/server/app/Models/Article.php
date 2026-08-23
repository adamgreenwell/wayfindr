<?php

namespace App\Models;

use App\Support\Knowledge\ArticleDocument;
use Database\Factories\ArticleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A published answer a visitor can find for themselves.
 *
 * The body is Markdown and stays Markdown: it is what the author wrote and what
 * they edit next. Blocks are derived on read (ArticleDocument), so widening the
 * supported subset later re-renders every article rather than migrating them.
 */
#[Fillable(['account_id', 'title', 'slug', 'body', 'published_at'])]
class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->published_at->isPast();
    }

    /**
     * Published, and published ALREADY.
     *
     * A future timestamp reads as scheduled rather than live, so the visitor
     * query and the "is this visible" check agree without a second rule.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function blocks(): array
    {
        return ArticleDocument::blocks((string) $this->body);
    }

    public function text(): string
    {
        return ArticleDocument::text((string) $this->body);
    }
}
