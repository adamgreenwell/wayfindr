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

        [$class, $method] = explode('@', $action);
        $file = $root.'/app/'.str_replace(['App\\', '\\'], ['', '/'], $class).'.php';

        if (is_file($file)) {
            // Keyed by class AND method, because a controller is not the unit
            // of extraction. `AgentSiteController` serves fourteen routes and
            // exactly one of them is extracted; scanning the whole file
            // reported the site-settings messages, which render on pages that
            // are correctly still English.
            $controllers[$class.'@'.$method] = [$file, $method];
        }
    }

    expect($controllers)->not->toBeEmpty('no extracted controller was resolved; the guard is checking nothing');

    $offenders = [];

    foreach ($controllers as [$file, $method]) {
        foreach (hardCodedValidationMessages($file, $method) as $offence) {
            $offenders[] = $offence;
        }
    }

    // Deduped: two routed methods sharing one helper reach the same line, and
    // one defect reported twice reads as two.
    $offenders = array_values(array_unique($offenders));

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
function hardCodedValidationMessages(string $file, ?string $method = null): array
{
    $source = file_get_contents($file);

    if ($source === false) {
        return [];
    }

    $tokens = token_get_all($source);

    if ($method !== null) {
        $tokens = extractedRouteReachableTokens($tokens, $method);
    }
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

/**
 * The tokens a route can REACH: its method, and every method that one calls.
 *
 * A controller is not the unit of extraction. `AgentSiteController` serves
 * fourteen routes and one of them is extracted, so scanning the whole file
 * reported two validation messages belonging to the site-settings pages --
 * which render `lang="en"` and are correctly English there.
 *
 * Slicing the route's own method is not enough either, and the controller this
 * guard was written for proves it: `AgentArticleController` throws both of its
 * messages from `validatedArticleInput()`, a private helper that `store()` and
 * `update()` share. A method-only slice reported that controller clean with an
 * English literal deliberately put back into it.
 *
 * So calls are followed, transitively, by name. `$this->foo(` and `self::foo(`
 * both count. What that cannot see is a message thrown from a collaborator
 * object rather than from this class -- a form request, an action class -- and
 * that is a real gap rather than a theoretical one; it is simply not how any
 * controller here is written today.
 *
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 * @return list<array{0: int, 1: string, 2: int}|string>
 */
function extractedRouteReachableTokens(array $tokens, string $method): array
{
    $reached = [];
    $pending = [$method];
    $seen = [];

    while ($pending !== []) {
        $name = array_shift($pending);

        if (isset($seen[$name])) {
            continue;
        }

        $seen[$name] = true;
        $slice = extractedRouteMethodTokens($tokens, $name);

        if ($slice === []) {
            continue;
        }

        foreach ($slice as $token) {
            $reached[] = $token;
        }

        foreach (methodsCalledOnSelf($slice) as $called) {
            $pending[] = $called;
        }
    }

    return $reached;
}

/**
 * Names called as `$this->name(` or `self::name(` inside one slice.
 *
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 * @return list<string>
 */
function methodsCalledOnSelf(array $tokens): array
{
    $called = [];

    foreach ($tokens as $index => $token) {
        if (! is_array($token) || $token[0] !== T_STRING) {
            continue;
        }

        $previous = $tokens[$index - 1] ?? null;

        if (! is_array($previous)) {
            continue;
        }

        if (! in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
            continue;
        }

        // `$this->` or `self::`, not `$someModel->update(`, which is a
        // different class and not this file's business.
        $subject = $tokens[$index - 2] ?? null;

        if (! is_array($subject)) {
            continue;
        }

        if (! in_array($subject[1], ['$this', 'self', 'static'], true)) {
            continue;
        }

        $called[] = $token[1];
    }

    return array_values(array_unique($called));
}

/**
 * The tokens of one method, brace-counted.
 *
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 * @return list<array{0: int, 1: string, 2: int}|string>
 */
function extractedRouteMethodTokens(array $tokens, string $method): array
{
    $found = null;

    foreach ($tokens as $index => $token) {
        if (is_array($token) && $token[0] === T_STRING && $token[1] === $method) {
            // The NAME in a declaration, not a call: `function foo(`.
            for ($back = $index - 1; $back >= 0; $back--) {
                if (is_array($tokens[$back]) && $tokens[$back][0] === T_WHITESPACE) {
                    continue;
                }

                if (is_array($tokens[$back]) && $tokens[$back][0] === T_FUNCTION) {
                    $found = $index;
                }

                break;
            }
        }

        if ($found !== null) {
            break;
        }
    }

    if ($found === null) {
        return [];
    }

    $slice = [];
    $depth = 0;
    $open = false;

    for ($i = $found, $total = count($tokens); $i < $total; $i++) {
        $token = $tokens[$i];

        if ($token === '{') {
            $depth++;
            $open = true;
        }

        if ($open) {
            $slice[] = $token;
        }

        if ($token === '}') {
            $depth--;

            if ($depth <= 0 && $open) {
                break;
            }
        }
    }

    return $slice;
}

test('the method slicer finds the method it was asked for', function (): void {
    // The sweep passes on a clean tree whether or not the slicer works, and a
    // slicer that returns nothing makes every controller look clean -- which is
    // exactly the failure this guard exists to prevent.
    $source = <<<'PHP'
    <?php
    class Example {
        public function untouched() {
            throw ValidationException::withMessages(['a' => 'English in another method.']);
        }
        public function extracted() {
            throw ValidationException::withMessages(['b' => 'English in the extracted one.']);
        }
    }
    PHP;

    $scratch = sys_get_temp_dir().'/wayfindr-method-slice-'.bin2hex(random_bytes(4)).'.php';
    file_put_contents($scratch, $source);

    expect(hardCodedValidationMessages($scratch, 'extracted'))
        ->toHaveCount(1, 'the slicer did not find the method, so every controller reads as clean');

    expect(hardCodedValidationMessages($scratch, 'extracted')[0])
        ->toContain('English in the extracted one.');

    expect(hardCodedValidationMessages($scratch, 'untouched'))
        ->toHaveCount(1, 'the slicer cannot see a method that is not the last one');

    // A method that does not exist yields nothing rather than the whole file.
    expect(hardCodedValidationMessages($scratch, 'absent'))->toBe([]);

    // And the shape that actually ships. `AgentArticleController` throws both
    // of its messages from a private helper that two routed methods share, so a
    // slicer that stops at the routed method reports that controller clean with
    // an English literal deliberately put back into it -- which is precisely
    // what happened, on the controller this guard was first written for.
    $delegating = <<<'PHP'
    <?php
    class Example {
        public function store() {
            return $this->validated();
        }
        public function elsewhere() {
            throw ValidationException::withMessages(['x' => 'Belongs to an unextracted page.']);
        }
        private function validated() {
            throw ValidationException::withMessages(['a' => 'English behind a helper.']);
        }
    }
    PHP;

    file_put_contents($scratch, $delegating);

    $reached = hardCodedValidationMessages($scratch, 'store');

    expect($reached)->toHaveCount(1, 'the guard does not follow a call into the helper that throws');
    expect($reached[0])->toContain('English behind a helper.');

    // And it stops at what the route can reach: the other method's message is
    // on a page that is still English, where an English sentence is correct.
    foreach ($reached as $offence) {
        expect($offence)->not->toContain('Belongs to an unextracted page.');
    }

    @unlink($scratch);
});
