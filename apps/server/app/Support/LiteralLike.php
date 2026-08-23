<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * A LIKE that matches the characters a person typed, and only those.
 *
 * `%` and `_` are wildcards to LIKE and ordinary punctuation to everyone else,
 * so an unescaped search for "50%" quietly matches every row beginning "50".
 * The escape has to be declared, because the default escape character is not
 * portable across drivers.
 *
 * The column is wrapped through the connection's own grammar rather than
 * interpolated. `docs/development/testing.md` names this the house technique,
 * and it was private to ConversationQueueQuery until a second caller wanted it
 * -- a security rule with two implementations is a security rule with one
 * implementation and one bug waiting.
 *
 * Full-text search is deliberately not the answer here: PostgreSQL's `tsvector`
 * and SQLite's FTS5 are exactly the driver-specific split that testing doc calls
 * the sharpest edge in the repository, and this suite runs on SQLite while every
 * documented install runs PostgreSQL.
 */
final class LiteralLike
{
    public static function pattern(string $search): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
    }

    public static function where(Builder $query, string $column, string $pattern, string $boolean = 'and'): void
    {
        $wrappedColumn = $query->getQuery()->getGrammar()->wrap($column);

        $query->whereRaw(
            'LOWER('.$wrappedColumn.') LIKE LOWER(?) ESCAPE ?',
            [$pattern, '\\'],
            $boolean,
        );
    }
}
