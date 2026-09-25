<?php

// A malformed id in a URL is a 404, never a server error.
//
// Implicit model binding hands the raw route segment to the database. On
// PostgreSQL, comparing "not-a-number" with a bigint key -- or a string that is
// not a UUID with a uuid key, or a number too large for a bigint -- raises, so a
// mistyped or truncated link was a 500 where every other missing record is the
// not-found page. SQLite compares anything with anything and answers "no such
// row", so most of the HTTP cases below pass there whether or not the
// constraint exists: they prove something on PostgreSQL, which CI runs this
// suite against too. The route-table tests after them are the half SQLite can
// see.

use App\Enums\AccountRole;
use App\Enums\PlatformRole;
use App\Models\Account;
use App\Models\BreakGlassGrant;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Support\DatabaseKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('a malformed id in the url is a 404, not a server error', function (string $method, string $uri): void {
    // An account admin who is also a platform operator clears every middleware
    // that runs before binding on the dashboard and on the operator console, so
    // an unconstrained segment reaches the database instead of stopping at a
    // login redirect or a 403 that would hide the defect.
    $account = Account::factory()->create();
    $user = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'platform_role' => PlatformRole::Operator,
    ]);

    // Real parents for the routes whose malformed id is the SECOND segment.
    // Bindings resolve in order, so a missing parent would 404 first and the
    // malformed child would never reach the database.
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->create(['account_id' => $account->id, 'site_id' => $site->id]);
    $grant = BreakGlassGrant::factory()->activeFor($account, $user)->create();

    $uri = strtr($uri, [
        '{site}' => (string) $site->id,
        '{ticket}' => (string) $ticket->id,
        '{grant}' => (string) $grant->id,
    ]);

    $response = $this->actingAs($user)->call($method, $uri);

    expect($response->status())->toBe(404, sprintf(
        'A malformed id must be a 404, but %s %s answered %d%s',
        $method,
        $uri,
        $response->status(),
        $response->exception === null
            ? ''
            : ' after '.$response->exception::class.': '.Str::limit($response->exception->getMessage(), 200),
    ));
})->with([
    'site' => ['GET', '/dashboard/sites/not-a-number'],
    'site settings' => ['PUT', '/dashboard/sites/not-a-number/details'],
    'site purge' => ['DELETE', '/dashboard/sites/not-a-number'],
    'site proactive messages' => ['GET', '/dashboard/sites/not-a-number/proactive-messages'],
    'site external issue project' => ['DELETE', '/dashboard/sites/{site}/external-issue-projects/not-a-number'],
    'ticket' => ['GET', '/dashboard/tickets/not-a-number'],
    'ticket reply' => ['POST', '/dashboard/tickets/not-a-number/replies'],
    'ticket label removal' => ['DELETE', '/dashboard/tickets/{ticket}/labels/not-a-number'],
    'ticket external link' => ['DELETE', '/dashboard/tickets/{ticket}/external-links/not-a-number'],
    'ticket label' => ['PUT', '/dashboard/account/labels/not-a-number'],
    'article' => ['GET', '/dashboard/account/articles/not-a-number'],
    'article publish' => ['POST', '/dashboard/account/articles/not-a-number/publish'],
    'agent role' => ['PUT', '/dashboard/account/agents/not-a-number/role'],
    'agent deactivation' => ['POST', '/dashboard/account/agents/not-a-number/deactivate'],
    'provider connection' => ['PUT', '/dashboard/external-issue-provider-connections/not-a-number/capabilities'],
    'github webhook' => ['POST', '/api/integrations/github/webhook/not-a-number'],
    'gitlab webhook' => ['POST', '/api/integrations/gitlab/webhook/not-a-number'],
    'jira webhook' => ['POST', '/api/integrations/jira/webhook/not-a-number'],
    'operator access approval' => ['POST', '/dashboard/account/operator-access/not-a-number/approve'],
    'break-glass grant' => ['GET', '/operator/break-glass/not-a-number'],
    'break-glass close' => ['POST', '/operator/break-glass/not-a-number/close'],
    'break-glass conversation' => ['GET', '/operator/break-glass/{grant}/conversations/not-a-number'],
    'break-glass ticket' => ['GET', '/operator/break-glass/{grant}/tickets/not-a-number'],
    // A UUID key, so a non-UUID fails the same way a non-number does.
    'alert' => ['POST', '/dashboard/alerts/not-a-uuid/read'],
    // Digits, but one more than a bigint can hold: PostgreSQL refuses the cast,
    // and an `int` controller parameter throws a TypeError on either engine.
    // These routes were already numeric-only, which is not the same as usable.
    'site id past bigint' => ['GET', '/dashboard/sites/9999999999999999999'],
    'reply template id past bigint' => ['PUT', '/dashboard/account/reply-templates/9999999999999999999'],
    'visitor id past bigint' => ['GET', '/dashboard/visitors/9999999999999999999'],
    'agent attachment id past bigint' => ['GET', '/dashboard/conversations/WF-NOPE/attachments/9999999999999999999'],
    'widget attachment id past bigint' => ['GET', '/api/conversations/WF-NOPE/attachments/9999999999999999999'],
]);

test('every route parameter that names an integer key carries the bounded pattern', function (): void {
    // The patterns are declared once, by parameter name, in AppServiceProvider.
    // Two ways that silently stops holding: a new route introduces a parameter
    // name the list does not know, or a route re-declares a known one with
    // whereNumber(), whose unbounded `[0-9]+` replaces the global pattern for
    // that route. Either leaves the HTTP test above green on SQLite.
    //
    // The parameters that are not integer keys, and why each is exempt, are
    // listed where the patterns are declared.
    $notIntegerKeys = [
        'supportCode', 'token', 'path', 'slug', 'notification',
        'connectionPublicId', 'deliveryPublicId', 'rulePublicId',
    ];

    $unbounded = [];

    foreach (Route::getRoutes() as $route) {
        foreach ($route->parameterNames() as $name) {
            if (in_array($name, $notIntegerKeys, true)) {
                continue;
            }

            $where = $route->wheres[$name] ?? null;

            if ($where !== DatabaseKey::ROUTE_PATTERN) {
                $unbounded[] = sprintf('%s /%s {%s} is %s', $route->methods()[0], $route->uri(), $name, $where ?? 'unconstrained');
            }
        }
    }

    expect($unbounded)->toBe([], "An integer-key route parameter does not carry DatabaseKey::ROUTE_PATTERN, so a malformed id reaches the database:\n".implode("\n", $unbounded));
});

test('the bounds admit a long real id and refuse what no key can be', function (): void {
    $routes = Route::getRoutes();
    $matched = fn (string $method, string $uri): ?string => rescue(
        fn () => $routes->match(Request::create($uri, $method))->getName(),
        null,
        report: false,
    );

    // Eighteen digits is the longest run that always fits a bigint, and it must
    // still reach the page: a bound tightened past it would 404 a real record.
    expect($matched('GET', '/dashboard/sites/'.str_repeat('9', 18)))
        ->toBe('dashboard.sites.show', 'An eighteen-digit id no longer reaches its route, so the bound refuses ids a real install can hold.');

    expect($matched('GET', '/dashboard/sites/'.str_repeat('9', 19)))
        ->toBeNull('A nineteen-digit id reached the route; 9999999999999999999 is past the largest bigint and fails there.');

    // The largest bigint is a real id an import or an advanced sequence can
    // hold; one past it is not. An eighteen-digit cap refused the first.
    expect($matched('GET', '/dashboard/sites/'.PHP_INT_MAX))
        ->toBe('dashboard.sites.show', 'The largest bigint no longer reaches its route, so the bound refuses a record PostgreSQL can hold.')
        ->and($matched('GET', '/dashboard/sites/00000000000000000001'))
        ->toBe('dashboard.sites.show', 'An id padded with leading zeroes no longer reaches its route, though it names a real record.')
        ->and($matched('GET', '/dashboard/sites/9223372036854775808'))
        ->toBeNull('One past the largest bigint reached the route, and PostgreSQL raises casting it.');

    expect($matched('POST', '/dashboard/alerts/'.Str::uuid()->toString().'/read'))
        ->toBe('dashboard.alerts.read', 'A well-formed alert id no longer reaches its route.');

    expect($matched('POST', '/dashboard/alerts/not-a-uuid/read'))
        ->toBeNull('An alert id that is not a UUID reached the route, and PostgreSQL raises comparing it with the uuid key.');
});

test('the route bound admits exactly the ids DatabaseKey::isValid admits', function (): void {
    // The pattern is a regex spelled out digit by digit, so check it against
    // the arithmetic it stands for, around the bound and across the range.
    $pattern = '/^'.DatabaseKey::ROUTE_PATTERN.'$/';
    mt_srand(1038);

    for ($i = 0; $i < 20000; $i++) {
        $value = $i % 2 === 0
            ? (string) (PHP_INT_MAX - mt_rand(0, 10 ** 7))
            : sprintf('%019d', mt_rand(0, PHP_INT_MAX));
        $value = substr_replace($value, (string) mt_rand(0, 9), mt_rand(0, 18), 1);
        // Leading zeroes change neither the number nor the answer.
        $value = str_repeat('0', mt_rand(0, 3) === 0 ? mt_rand(1, 6) : 0).$value;

        expect((bool) preg_match($pattern, $value))
            ->toBe(DatabaseKey::isValid($value), "The route bound and isValid() disagree on {$value}.");
    }
});
