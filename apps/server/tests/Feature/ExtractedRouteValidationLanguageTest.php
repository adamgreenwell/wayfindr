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

    foreach ($controllers as $class => $file) {
        $source = file_get_contents($file);

        if ($source === false) {
            continue;
        }

        // A quoted string on the value side of a `withMessages` array entry.
        // `__('...')` and `trans_choice('...')` do not match, because the
        // quote is preceded by `(` rather than `> `.
        foreach (file($file) as $number => $line) {
            if (preg_match("/^\s*'[a-z_]+' => '[^']/i", $line) !== 1) {
                continue;
            }

            // Only inside a withMessages call. Cheap proxy: the preceding few
            // lines mention it.
            $context = implode('', array_slice(file($file), max(0, $number - 4), 4));

            if (! str_contains($context, 'withMessages')) {
                continue;
            }

            $offenders[] = basename($file).':'.($number + 1).'  '.trim($line);
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
