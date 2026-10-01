<?php

declare(strict_types=1);

namespace App\Support\Visitors;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Id-list queries in chunks, so a long history stays under every driver's
 * bound-parameter limit.
 */
trait QueriesIdsInChunks
{
    /**
     * @param  EloquentBuilder<Model>  $query
     * @param  list<int>  $values
     * @return list<int>
     */
    private function idsIn(EloquentBuilder $query, string $column, array $values): array
    {
        $ids = [];

        foreach (array_chunk($values, 500) as $chunk) {
            $ids = [...$ids, ...$this->ints((clone $query)->whereIn($column, $chunk)->pluck('id'))];
        }

        return $ids;
    }

    /**
     * @param  EloquentBuilder<Model>  $query
     * @param  list<int>  $values
     */
    private function countIn(EloquentBuilder $query, string $column, array $values): int
    {
        $count = 0;

        foreach (array_chunk($values, 500) as $chunk) {
            $count += (clone $query)->whereIn($column, $chunk)->count();
        }

        return $count;
    }

    /**
     * Run a statement against an id list in chunks.
     *
     * @param  list<int>|list<string>  $values
     * @param  callable(Builder): mixed  $statement
     */
    private function whereInChunks(Builder $query, string $column, array $values, callable $statement): void
    {
        foreach (array_chunk($values, 500) as $chunk) {
            $statement((clone $query)->whereIn($column, $chunk));
        }
    }

    /**
     * @param  Collection<int, mixed>  $values
     * @return list<int>
     */
    private function ints(Collection $values): array
    {
        return $values->map(fn (mixed $value): int => (int) $value)->values()->all();
    }
}
