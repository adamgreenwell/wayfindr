<?php

declare(strict_types=1);

use App\Support\DashboardLanguage;

/**
 * An extracted page's ERROR paths speak the agent's language too.
 *
 * Extracting a view is the visible half and the easy one. The half that gets
 * left behind is `ValidationException::withMessages()`, which lives in the
 * controller, renders through `@error` on the page that was just translated,
 * and is only reached by an agent who does something wrong -- so nobody sees
 * it while the feature is being built.
 *
 * It has now been left behind twice in two slices. The reply-templates page
 * shipped with two English messages and they were live on an extracted route
 * until the ticket-labels review found the same defect one page over. That is
 * the shape of thing a guard is for: a rule I keep meaning to remember, whose
 * failure is invisible on the happy path.
 *
 * The check is structural rather than behavioural on purpose. Reaching every
 * validation branch of every extracted controller would need a fixture per
 * branch, and the mistake is not subtle -- it is a quoted English sentence
 * where a `__()` call belongs.
 */
test('no extracted route throws a hard-coded validation message', function (): void {
    $root = dirname(__DIR__, 2);

    // The controllers actually reachable through an extracted route, resolved
    // from the route table rather than listed here -- a list would go stale the
    // first time a slice extracted a page and forgot to add its controller,
    // which is precisely the failure being guarded.
    $controllers = [];

    foreach (DashboardLanguage::EXTRACTED_ROUTES as $name) {
        $route = app('router')->getRoutes()->getByName($name);

        if ($route === null) {
            continue;
        }

        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            continue;
        }

        $class = explode('@', $action)[0];
        $file = $root.'/app/'.str_replace(['App\\', '\\'], ['', '/'], $class).'.php';

        if (is_file($file)) {
            $controllers[$class] = $file;
        }
    }

    expect($controllers)->not->toBeEmpty('no extracted controller was resolved; the guard is checking nothing');

    $offenders = [];

    foreach ($controllers as $file) {
        foreach (hardCodedValidationMessages($file) as $offence) {
            $offenders[] = $offence;
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These throw an English sentence from a route whose page is translated:',
        ...$offenders,
        '',
        'Move the message into that page\'s catalogue and translate it at the',
        'throw, the way conversations.validation.* already is.',
    ]));
});

/**
 * Literal strings handed to `withMessages()` in one file.
 *
 * Tokenised rather than matched with a regex. The first version of this
 * anchored on a single-quoted value at the start of a line, which meant it
 * could not see a double-quoted message or the same-line
 * `withMessages(['title' => 'Give the article a title.'])` form -- and that
 * second shape is sitting in `AgentArticleController` right now, waiting for
 * the slice that extracts articles. A guard blind to the exact code it will
 * next be asked about is worse than no guard, because it reports success.
 *
 * @return list<string>
 */
function hardCodedValidationMessages(string $file): array
{
    $source = file_get_contents($file);

    if ($source === false) {
        return [];
    }

    $tokens = token_get_all($source);
    $offenders = [];
    $depth = null;

    foreach ($tokens as $index => $token) {
        // Entering a withMessages(...) call: start counting parentheses so the
        // scan ends where the call does, however many lines it spans.
        if (is_array($token) && $token[0] === T_STRING && $token[1] === 'withMessages') {
            $depth = 0;

            continue;
        }

        if ($depth === null) {
            continue;
        }

        if ($token === '(') {
            $depth++;

            continue;
        }

        if ($token === ')') {
            $depth--;

            if ($depth <= 0) {
                $depth = null;
            }

            continue;
        }

        // A quoted string on the value side of `=>`, in either quote style.
        // `__('...')` does not match: there the string follows `(`, not `=>`.
        if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        $previous = null;

        for ($back = $index - 1; $back >= 0; $back--) {
            if (is_array($tokens[$back]) && in_array($tokens[$back][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $previous = $tokens[$back];
            break;
        }

        if (is_array($previous) && $previous[0] === T_DOUBLE_ARROW) {
            $offenders[] = basename($file).':'.$token[2].'  '.trim($token[1]);
        }
    }

    return $offenders;
}

/**
 * The sweep above passes on a clean tree whether or not the recogniser still
 * works, so the recogniser is asserted here or not at all.
 *
 * Each shape below is one the first version missed. `AgentArticleController`
 * uses the same-line form today, and articles is a likely next slice -- so the
 * guard would have reported success on precisely the page it was written for.
 */
test('the guard recognises every shape a literal message takes', function (): void {
    $scratch = sys_get_temp_dir().'/wayfindr-validation-guard-'.bin2hex(random_bytes(4)).'.php';

    $shapes = [
        'same-line array' => "<?php throw ValidationException::withMessages(['title' => 'Give the article a title.']);",
        'double quotes' => "<?php throw ValidationException::withMessages(['title' => \"Give the article a title.\"]);",
        'multi-line' => "<?php\nthrow ValidationException::withMessages([\n    'title' => 'Give the article a title.',\n]);",
        'second entry only' => "<?php throw ValidationException::withMessages(['a' => __('x.y'), 'b' => 'English.']);",
    ];

    foreach ($shapes as $label => $source) {
        file_put_contents($scratch, $source);

        expect(hardCodedValidationMessages($scratch))
            ->not->toBeEmpty("the guard no longer sees a literal message written as a {$label}");
    }

    // And it does not fire on the translated forms, or it would make the fix
    // impossible rather than required.
    $clean = [
        'translated' => "<?php throw ValidationException::withMessages(['title' => __('articles.validation.title')]);",
        'translated with argument' => "<?php throw ValidationException::withMessages(['title' => __('articles.validation.title', ['name' => \$name])]);",
        'unrelated array' => "<?php \$config = ['title' => 'Not a validation message at all'];",
    ];

    foreach ($clean as $label => $source) {
        file_put_contents($scratch, $source);

        expect(hardCodedValidationMessages($scratch))
            ->toBe([], "the guard fires on a {$label}, which would make the fix impossible");
    }

    @unlink($scratch);
});
