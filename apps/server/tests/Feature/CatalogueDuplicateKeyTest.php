<?php

declare(strict_types=1);

/**
 * A translation catalogue may not declare the same key twice.
 *
 * PHP keeps the LAST of two identical keys and says nothing. So a duplicate is
 * invisible until somebody edits the first one, at which point their change
 * does not happen and nothing anywhere reports it -- not the parity checks, not
 * the policy scorer, not the render audit, because after parsing there is only
 * one key and its value is consistent.
 *
 * `lang/en/tickets.php` and `lang/de/tickets.php` each carried a duplicated
 * `statuses` block. They were byte-identical, so nothing was broken yet; the
 * defect was that a translator improving the first block would have watched
 * their work vanish.
 *
 * Source-level, because that is the only place the duplicate still exists.
 */
test('no catalogue declares the same key twice', function (): void {
    $offenders = [];

    foreach (glob(lang_path('*/*.php')) ?: [] as $path) {
        foreach (duplicateCatalogueKeys($path) as $key) {
            $offenders[] = str_replace(lang_path().'/', '', $path).': '.$key;
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These keys are declared twice. PHP keeps the last one silently, so an',
        'edit to the first is discarded without any error:',
        ...$offenders,
    ]));
});

/**
 * Keys declared more than once at the same level of one catalogue.
 *
 * Tokenised rather than matched on indentation: a regex over lines cannot tell
 * a key in a nested array from one in its parent, and both `statuses.open` and
 * `priorities.open` are legitimate.
 *
 * @return list<string>
 */
function duplicateCatalogueKeys(string $path): array
{
    $source = file_get_contents($path);

    if ($source === false) {
        return [];
    }

    $tokens = token_get_all($source);

    // One frame per open bracket. `$seen` counts keys in the current frame;
    // `$trail` remembers the key each frame was opened under, so a duplicate is
    // reported as `statuses.open` rather than as a bare `open`.
    $frames = [['seen' => [], 'name' => null]];
    $duplicates = [];
    $pendingKey = null;

    foreach ($tokens as $index => $token) {
        if ($token === '[') {
            $frames[] = ['seen' => [], 'name' => $pendingKey];
            $pendingKey = null;

            continue;
        }

        if ($token === ']') {
            if (count($frames) > 1) {
                array_pop($frames);
            }

            continue;
        }

        if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        // A key is a string followed by `=>`.
        $next = null;

        for ($ahead = $index + 1; $ahead < count($tokens); $ahead++) {
            if (is_array($tokens[$ahead]) && in_array($tokens[$ahead][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $next = $tokens[$ahead];
            break;
        }

        if (! is_array($next) || $next[0] !== T_DOUBLE_ARROW) {
            continue;
        }

        $key = trim($token[1], "'\"");
        $frame = count($frames) - 1;

        if (isset($frames[$frame]['seen'][$key])) {
            $path = array_values(array_filter(array_column($frames, 'name')));
            $path[] = $key;
            $duplicates[] = implode('.', $path).' (line '.$token[2].')';
        }

        $frames[$frame]['seen'][$key] = true;
        $pendingKey = $key;
    }

    return $duplicates;
}

test('the duplicate detector sees a duplicate, and does not invent one', function (): void {
    // The sweep above passes on a clean tree whether or not the detector works,
    // and a detector that always returns nothing makes every catalogue look
    // fine -- which is the failure this guard exists to prevent.
    $scratch = sys_get_temp_dir().'/wayfindr-catalogue-'.bin2hex(random_bytes(4)).'.php';

    $duplicated = <<<'PHP'
    <?php
    return [
        'statuses' => ['open' => 'Open'],
        'statuses' => ['open' => 'Open'],
    ];
    PHP;

    file_put_contents($scratch, $duplicated);

    expect(duplicateCatalogueKeys($scratch))->toHaveCount(1, 'the detector no longer sees a duplicated key');
    expect(duplicateCatalogueKeys($scratch)[0])->toContain('statuses');

    // The same key in two DIFFERENT arrays is not a duplicate, and reporting it
    // as one would make the guard unusable: every catalogue has an `open`
    // status and an `open` something else.
    $legitimate = <<<'PHP'
    <?php
    return [
        'statuses' => ['open' => 'Open', 'closed' => 'Closed'],
        'priorities' => ['open' => 'Open', 'closed' => 'Closed'],
    ];
    PHP;

    file_put_contents($scratch, $legitimate);

    expect(duplicateCatalogueKeys($scratch))->toBe([], 'the detector reports the same key in two different arrays');

    // And a duplicate NESTED one level down is still a duplicate.
    $nested = <<<'PHP'
    <?php
    return [
        'statuses' => ['open' => 'Open', 'open' => 'Offen'],
    ];
    PHP;

    file_put_contents($scratch, $nested);

    expect(duplicateCatalogueKeys($scratch))->toHaveCount(1, 'a duplicate inside a nested array is not seen');
    expect(duplicateCatalogueKeys($scratch)[0])->toContain('statuses.open');

    @unlink($scratch);
});
