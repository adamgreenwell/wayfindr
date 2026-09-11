#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace Wayfindr\DataModelCoverage;

use RuntimeException;
use Throwable;

/**
 * Every table a migration creates must be described in the data model.
 *
 * `docs/architecture/data-model.md` described 31 of 57 tables before #958, and
 * nothing anywhere reported it, because nothing read both files. This does.
 *
 * It fails rather than warns (#959). The document states that a table absent
 * from it is a gap in the document rather than a table that does not exist,
 * and a contract nobody enforces is a claim.
 *
 * PRESENCE ONLY. Whether an entry is *true* is not checkable here: #958 took
 * six review rounds to remove six wrong statements from entries that were all
 * present, including a column named on the wrong table and a delivery
 * guarantee stated backwards. That stays a review problem.
 */

/**
 * Laravel's own tables, which the data model deliberately does not describe.
 *
 * Add to this only for a table the framework creates. A Wayfindr table parked
 * here to quiet the check is the exact failure this script exists to catch,
 * and it would be silent.
 *
 * @var list<string>
 */
const FRAMEWORK_TABLES = [
    'cache',
    'cache_locks',
    'failed_jobs',
    'job_batches',
    'jobs',
    'migrations',
    'notifications',
    'password_reset_tokens',
    'personal_access_tokens',
    'sessions',
];

/** One level of nesting, so `connection(config('a.b'))` does not end the group early. */
const BALANCED_ARGUMENTS = '\((?:[^()]|\([^()]*\))*\)';

/**
 * Table names created by one migration file.
 *
 * Several call shapes reach `create`, and they are why this script is PHP
 * rather than a grep. `push_subscriptions` is created as
 * `Schema::connection($connection)->create($tableName, ...)` with both values
 * read from config, so a pattern matching a literal `Schema::create('name'`
 * misses it — which is how it stayed undocumented while a check certified the
 * document complete.
 *
 * Recognising shapes is not enough on its own. A shape this script does not
 * know produces no match, and no match reads as success — the same blind spot
 * in a different place. So every schema-builder `create(` in the file is
 * counted, and a count the recognised patterns cannot account for is fatal.
 *
 * @return list<string>
 */
function tablesCreatedBy(string $path, string $source): array
{
    $argument = '\s*(?:[\'"](?<literal>[A-Za-z0-9_]+)[\'"]|\$(?<variable>[A-Za-z_][A-Za-z0-9_]*))';
    $builders = builderVariables($source);
    $receivers = ['Schema::(?:connection'.BALANCED_ARGUMENTS.'\s*->\s*)?'];

    foreach ($builders as $builder) {
        $receivers[] = '\$'.preg_quote($builder, '/').'\s*->\s*';
    }

    $pattern = '/(?:'.implode('|', $receivers).')create\('.$argument.'/';

    preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);

    $tables = [];

    foreach ($matches as $match) {
        if (($match['literal'] ?? '') !== '') {
            $tables[] = $match['literal'];

            continue;
        }

        $variable = $match['variable'];
        $resolved = resolveVariableTableName($source, $variable);

        if ($resolved === null) {
            // Deliberately fatal. A dynamic name this script cannot read is
            // indistinguishable from a table nobody documented, and silently
            // skipping it is how the gap survives a second time.
            throw new RuntimeException(sprintf(
                '%s creates a table from $%s and this check cannot resolve its name. '
                    .'Give the variable a config() default it can read, or teach this script the new shape. '
                    .'Skipping it is not an option: an unreadable name hides a table from the contract.',
                basename($path),
                $variable,
            ));
        }

        $tables[] = $resolved;
    }

    assertEveryCreateWasRead($path, $source, $builders, count($tables));

    return $tables;
}

/**
 * Variables holding a schema builder, so `$schema->create(...)` is seen.
 *
 * @return list<string>
 */
function builderVariables(string $source): array
{
    $pattern = '/\$(?<name>[A-Za-z_][A-Za-z0-9_]*)\s*=\s*Schema::(?:connection'.BALANCED_ARGUMENTS.')?/';

    preg_match_all($pattern, $source, $matches);

    return array_values(array_unique($matches['name'] ?? []));
}

/**
 * Refuse a schema-builder `create(` the recognised patterns did not read.
 *
 * This is the guard the rest of the script is pointless without. A new call
 * shape — a builder held in a property, a helper that wraps `Schema` — would
 * otherwise match nothing, and a file that matches nothing looks exactly like
 * a file that creates no tables.
 */
function assertEveryCreateWasRead(string $path, string $source, array $builders, int $read): void
{
    // Every create() in the file, on any receiver — deliberately broader than
    // the shapes above. Counting only receivers already recognised would make
    // the guard blind to exactly the shapes it exists to catch: an aliased
    // builder (`$b = $schema; $b->create(...)`) matches no known receiver, so
    // a narrow count reads zero-of-zero and calls it success.
    //
    // Across all migrations today every create() is a schema create and no
    // non-Schema `::create(` receiver appears, so this over-counts nothing. A
    // data migration that one day calls `SomeModel::create([...])` will trip
    // it, and that is the right failure: the message says to teach the script
    // the shape, and a false alarm is cheap next to a table nobody documents.
    $total = preg_match_all('/(?:Schema::|->\s*)create\s*\(/', $source);

    if ($total > $read) {
        throw new RuntimeException(sprintf(
            '%s calls a schema builder\'s create() %d time(s) but this check could only read %d table name(s). '
                .'The unread call uses a shape it does not recognise. Teach it the shape rather than leaving it: '
                .'a create() nobody reads is a table nobody has to document.',
            basename($path),
            $total,
            $read,
        ));
    }
}

/**
 * The default a config-backed table-name variable falls back to.
 *
 * Only the documented shape is accepted — `$name = config('key', 'default')`.
 * An operator who overrides the key renames their own table; the default is
 * what the repository ships and therefore what the data model describes.
 */
function resolveVariableTableName(string $source, string $variable): ?string
{
    $pattern = '/\$'.preg_quote($variable, '/')
        .'\s*=\s*(?:\([a-z]+\)\s*)?config\(\s*[\'"][^\'"]+[\'"]\s*,\s*[\'"](?<default>[A-Za-z0-9_]+)[\'"]\s*\)/';

    if (preg_match($pattern, $source, $match) !== 1) {
        return null;
    }

    return $match['default'];
}

/** @return list<string> */
function migrationFiles(string $root): array
{
    $files = glob($root.'/apps/server/database/migrations/*.php');

    if ($files === false || $files === []) {
        throw new RuntimeException('no migrations found; this check is looking in the wrong place.');
    }

    sort($files);

    return $files;
}

function main(string $root): void
{
    $documentPath = $root.'/docs/architecture/data-model.md';
    $document = file_get_contents($documentPath);

    if ($document === false) {
        throw new RuntimeException('docs/architecture/data-model.md is not readable.');
    }

    $document = strtolower($document);
    $created = [];

    foreach (migrationFiles($root) as $path) {
        $source = file_get_contents($path);

        if ($source === false) {
            throw new RuntimeException(sprintf('%s is not readable.', basename($path)));
        }

        foreach (tablesCreatedBy($path, $source) as $table) {
            $created[$table] = basename($path);
        }
    }

    $undocumented = [];

    foreach ($created as $table => $migration) {
        if (in_array($table, FRAMEWORK_TABLES, true)) {
            continue;
        }

        // Its own bullet, not a mention. Entries cross-reference each other
        // freely -- `ticket_label_ticket` names `ticket_labels`, and the reply
        // outbox names the external-comment one -- so accepting any backticked
        // occurrence would let a table be "documented" by a passing reference
        // in somebody else's paragraph.
        if (preg_match('/^- `'.preg_quote($table, '/').'`:/m', $document) !== 1) {
            $undocumented[$table] = $migration;
        }
    }

    if ($undocumented !== []) {
        $lines = [];

        foreach ($undocumented as $table => $migration) {
            $lines[] = sprintf('  - `%s`  (created by %s)', $table, $migration);
        }

        throw new RuntimeException(sprintf(
            "%d table%s created by a migration %s not described in docs/architecture/data-model.md:\n%s\n\n"
                ."Add an entry under Core Records saying what the table holds and why it is shaped that way.\n"
                .'Describe the design point rather than listing columns; the entries already there are the pattern.',
            count($undocumented),
            count($undocumented) === 1 ? '' : 's',
            count($undocumented) === 1 ? 'is' : 'are',
            implode("\n", $lines),
        ));
    }

    $domainCount = count(array_diff(array_keys($created), FRAMEWORK_TABLES));

    printf(
        "Data model coverage passed: all %d domain tables created by migrations are described (%d framework tables excluded).\n",
        $domainCount,
        count($created) - $domainCount,
    );
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        if (array_slice($argv, 1) !== []) {
            throw new RuntimeException('unknown argument; this check takes none.');
        }

        main(dirname(__DIR__));
    } catch (Throwable $throwable) {
        fwrite(STDERR, 'Data model coverage failed: '.$throwable->getMessage()."\n");
        exit(1);
    }
}
