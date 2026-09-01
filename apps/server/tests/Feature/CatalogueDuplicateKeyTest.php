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
        // Long-form `array(...)` opens a scope this walker does not track: its
        // frames are pushed on `[`, so every key in a nested long-form array
        // would land in the parent's frame and sibling `statuses.open` and
        // `priorities.open` would be reported as one duplicated key.
        //
        // Refused rather than half-handled, the same call as the double-quoted
        // escapes above. Tracking it properly means counting parentheses that
        // also belong to every function call in the file, and a guard that
        // invents duplicates blocks a suite over a file that is fine. Every
        // catalogue uses short syntax; this fires if one stops.
        if (is_array($token) && $token[0] === T_ARRAY) {
            throw new RuntimeException(
                'A catalogue uses long-form array() syntax. The duplicate guard tracks key '
                .'scopes by short-array brackets and would report sibling arrays as duplicates. '
                .'Write the catalogue with [] instead.'
            );
        }

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

        $key = catalogueKeyValue($token[1]);
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

/**
 * The VALUE of a quoted PHP key -- and a refusal to guess.
 *
 * `'don\'t'` and `"don't"` are the same PHP key spelled two ways, so comparing
 * source literals let the duplicate this guard exists to find walk past it.
 *
 * Decoding the general case is a bigger job than it looks, and getting it
 * subtly wrong is worse than not doing it: `stripcslashes()` is the obvious
 * reach and does NOT match PHP, which keeps the backslash in an unknown escape
 * like `"a\qb"` while `stripcslashes()` returns `aqb` -- so two distinct keys
 * would be reported as one. PHP's `\u{...}` goes the other way, leaving two
 * spellings of one key looking distinct.
 *
 * So this decodes the two escapes a SINGLE-quoted string can contain, which is
 * the whole of that syntax, and refuses anything else. Every key in every
 * catalogue is `[a-z0-9_]`, so the refusal is unreachable today -- and if one
 * ever does need an escape, this fails loudly instead of quietly comparing the
 * wrong thing.
 */
function catalogueKeyValue(string $literal): string
{
    $quote = $literal[0] ?? "'";
    $inner = mb_substr($literal, 1, -1);

    if ($quote === "'") {
        return str_replace(['\\\\', "\\'"], ['\\', "'"], $inner);
    }

    // Double-quoted. Plain text is the same string either way; anything with a
    // backslash in it needs semantics this guard deliberately does not carry.
    if (str_contains($inner, '\\')) {
        throw new RuntimeException(
            "A catalogue key uses a double-quoted escape ({$literal}). The duplicate guard "
            .'compares evaluated keys and does not implement PHP\'s double-quoted escape rules, '
            .'because getting them subtly wrong reports duplicates that are not there. '
            .'Write the key as a plain single-quoted string.'
        );
    }

    return $inner;
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

    // Two spellings of ONE key. PHP keeps the last, so this is a duplicate --
    // and comparing the source text says it is not, which is how a guard that
    // looks strict passes on the thing it exists to find.
    $spellings = <<<'PHP'
    <?php
    return [
        'don\'t' => 'a',
        "don't" => 'b',
    ];
    PHP;

    file_put_contents($scratch, $spellings);

    expect(duplicateCatalogueKeys($scratch))
        ->toHaveCount(1, 'the detector compares how a key is spelled rather than what it is');

    // The single-quote rules, in both directions. `\b` is NOT an escape there,
    // so `'a\\b'` and `'a\b'` both evaluate to `a\b` and are one key --
    // which the first version of this test got backwards, asserting that two
    // spellings of the same key were distinct.
    $sameKeyTwoWays = <<<'PHP'
    <?php
    return [
        'a\\b' => 'one',
        'a\b' => 'two',
    ];
    PHP;

    file_put_contents($scratch, $sameKeyTwoWays);

    expect(duplicateCatalogueKeys($scratch))
        ->toHaveCount(1, 'the detector does not apply the single-quote escape rules');

    // And `'a\\\\b'` is `a\\b`, which really is a different key.
    $distinct = <<<'PHP'
    <?php
    return [
        'a\\\\b' => 'one',
        'a\\b' => 'two',
    ];
    PHP;

    file_put_contents($scratch, $distinct);

    expect(duplicateCatalogueKeys($scratch))
        ->toBe([], 'the detector merged two keys that PHP keeps apart');

    // A double-quoted escape is REFUSED rather than guessed at. `stripcslashes`
    // and PHP disagree about unknown escapes and about `\u{...}`, in opposite
    // directions, so a guard that half-implements the rules reports duplicates
    // that are not there and misses ones that are.
    $escaped = <<<'PHP'
    <?php
    return [
        "a\qb" => 'one',
    ];
    PHP;

    file_put_contents($scratch, $escaped);

    expect(fn () => duplicateCatalogueKeys($scratch))
        ->toThrow(RuntimeException::class);

    // Long-form `array()` is refused for the same reason: its keys would land
    // in the enclosing frame, and two sibling arrays that each contain `open`
    // -- which every catalogue here has -- would be reported as a duplicate.
    $longForm = <<<'PHP'
    <?php
    return array(
        'statuses' => array('open' => 'Open'),
        'priorities' => array('open' => 'Open'),
    );
    PHP;

    file_put_contents($scratch, $longForm);

    expect(fn () => duplicateCatalogueKeys($scratch))
        ->toThrow(RuntimeException::class);

    // And the short-array equivalent it is refusing on behalf of passes, so the
    // refusal is about syntax rather than about the shape being wrong.
    $shortForm = <<<'PHP'
    <?php
    return [
        'statuses' => ['open' => 'Open'],
        'priorities' => ['open' => 'Open'],
    ];
    PHP;

    file_put_contents($scratch, $shortForm);

    expect(duplicateCatalogueKeys($scratch))->toBe([]);

    @unlink($scratch);
});
