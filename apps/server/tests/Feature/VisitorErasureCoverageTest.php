<?php

// The erasure map is only as good as its coverage of the schema (ADR 0026).
// Its Context table lists what a plain delete left behind; every one of those
// was a table nobody had asked "what happens to this when the person goes?".
// This holds the question to the live schema, so the next migration that can
// reach a visitor has to answer it in VisitorEraser::COVERAGE to pass.

use App\Support\Visitors\VisitorEraser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/** Tables whose rows are, or are derived from, one visitor. */
const ERASURE_ROOTS = [
    'visitors',
    'conversations',
    'conversation_messages',
    'conversation_message_attachments',
    'cobrowse_sessions',
    'tickets',
    'notifications',
    'sla_clocks',
];

/** @return list<string> */
function erasureSchemaTables(): array
{
    return collect(Schema::getTableListing())
        ->map(fn (string $table): string => str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table)
        ->all();
}

/**
 * Every table that can reach a visitor-derived row: by foreign key to one of
 * the roots, or through a polymorphic pair, which has no foreign key at all
 * and so is exactly where a plain delete leaves things behind.
 *
 * @return array<string, list<string>>
 */
function erasureReachingTables(): array
{
    $reaching = [];

    foreach (erasureSchemaTables() as $table) {
        foreach (Schema::getForeignKeys($table) as $key) {
            $foreign = str_contains($key['foreign_table'], '.') ? substr($key['foreign_table'], strrpos($key['foreign_table'], '.') + 1) : $key['foreign_table'];

            if (in_array($foreign, ERASURE_ROOTS, true)) {
                $reaching[$table][] = implode(',', $key['columns']).' -> '.$foreign;
            }
        }

        $columns = collect(Schema::getColumnListing($table));
        foreach ($columns as $column) {
            if (str_ends_with($column, '_type') && $columns->contains(substr($column, 0, -5).'_id')) {
                $reaching[$table][] = substr($column, 0, -5).' (polymorphic)';
            }
        }
    }

    return $reaching;
}

test('every table that can reach a visitor says what erasure does to it', function (): void {
    $reaching = erasureReachingTables();

    // Known instances, so a sweep that stopped matching fails here instead
    // of passing with nothing checked: one foreign key, one polymorphic pair.
    expect($reaching['tickets'] ?? [])->toContain('requester_id -> visitors')
        ->and($reaching['audit_events'] ?? [])->toContain('subject (polymorphic)');

    $unanswered = collect($reaching)
        ->reject(fn (array $paths, string $table): bool => array_key_exists($table, VisitorEraser::COVERAGE))
        ->map(fn (array $paths, string $table): string => $table.' ('.implode('; ', $paths).')')
        ->values()
        ->all();

    expect($unanswered)->toBe([], 'these tables can reach a visitor but VisitorEraser::COVERAGE does not say what erasure does to them: '.implode(' | ', $unanswered));

    foreach (ERASURE_ROOTS as $root) {
        expect(array_key_exists($root, VisitorEraser::COVERAGE))->toBeTrue("the root table {$root} is not in VisitorEraser::COVERAGE");
    }
});

test('the erasure map names only tables that exist, so it cannot rot', function (): void {
    $tables = erasureSchemaTables();

    foreach (array_keys(VisitorEraser::COVERAGE) as $table) {
        expect(in_array($table, $tables, true))->toBeTrue("VisitorEraser::COVERAGE names {$table}, which is not in the schema");
    }
});
