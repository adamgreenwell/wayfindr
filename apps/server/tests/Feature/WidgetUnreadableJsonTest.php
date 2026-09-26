<?php

// A JSON body PHP cannot decode used to reach the widget's controllers as an
// EMPTY one: Request::json() casts the failed decode to an empty input bag. The
// site key went with it, so every widget endpoint answered 404 "Site not
// found." -- and the widget's Retry resent the same bytes into the same 404.
// A lone UTF-16 surrogate is enough: JavaScript's JSON.stringify writes one as
// a valid "\udXXX" escape, and PHP's decoder refuses the whole document.

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\ExternalIssueProviderConnection;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function unreadableJsonPost(string $uri, string $raw): TestResponse
{
    return test()->call('POST', $uri, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $raw);
}

// The subject the widget used to send when an emoji straddled its 255th unit.
function unreadableJsonLoneSurrogateBody(): string
{
    return '{"site_public_key":"site_public_demo","anonymous_id":"anon-1","subject":"'.str_repeat('a', 254).'\ud83d"}';
}

test('a body the server cannot decode is refused as unreadable, not as a missing site', function (): void {
    $response = unreadableJsonPost('/api/conversations', unreadableJsonLoneSurrogateBody());

    expect($response->status())->toBe(400, 'an undecodable body was answered as something other than a bad request: '.$response->getContent());
    expect($response->json('error_key'))->toBe('error.unreadableRequest', 'the refusal carries no key the widget can translate');
    expect($response->json('message'))->toBe('The request could not be read.');
});

test('the widget’s other endpoints refuse an unreadable body the same way', function (string $uri, string $raw): void {
    $response = unreadableJsonPost($uri, $raw);

    expect($response->status())->toBe(400, "{$uri} answered an undecodable body with {$response->status()}: {$response->getContent()}");
    expect($response->json('error_key'))->toBe('error.unreadableRequest');
})->with([
    'bootstrap, cut off mid-document' => ['/api/widget/bootstrap', '{"site_public_key":"site_public_demo",'],
    'a message with half an emoji' => ['/api/conversations/WF-TEST123/messages', '{"site_public_key":"site_public_demo","body":"hi \ud83d"}'],
    'presence, not JSON at all' => ['/api/widget/presence', 'site_public_key=site_public_demo'],
]);

test('a readable body is answered exactly as before', function (): void {
    // The same request, whole: the unknown site is still the answer.
    $readable = str_replace('\ud83d', '😀', unreadableJsonLoneSurrogateBody());

    $response = unreadableJsonPost('/api/conversations', $readable);

    $response->assertNotFound();
    expect($response->json('message'))->toBe('Site not found.');
});

test('an empty JSON body is not called unreadable', function (): void {
    $response = unreadableJsonPost('/api/widget/bootstrap', '');

    expect($response->json('error_key'))->not->toBe('error.unreadableRequest', 'an empty body was refused as unreadable');
});

test('the inbound webhooks answer an unreadable body as they always did', function (bool $realConnection, string $uri): void {
    // They verify a signature over the raw body inside their controllers,
    // which run after this check. A webhook's {connection} is route-bound, and
    // binding runs BEFORE this check: against a connection that does not exist
    // the answer is 404 either way, and the exemption would go untested.
    if ($realConnection) {
        $uri = str_replace('{connection}', (string) ExternalIssueProviderConnection::factory()->create()->id, $uri);
    }

    $response = unreadableJsonPost($uri, '{"broken":');

    // Inbound mail answers 404 from its controller, after this check, when no
    // inbound mail is configured; only a bound route's 404 comes before it.
    if ($realConnection) {
        expect($response->status())->not->toBe(404, "{$uri} never reached the check, so this proves nothing about the exemption");
    }

    expect($response->json('error_key'))->not->toBe('error.unreadableRequest', "{$uri} is exempt, but was refused by the widget's unreadable-body check");
    expect($response->status())->not->toBe(400, "{$uri} changed its answer to an undecodable body");
})->with([
    'GitHub webhook (raw-body signature)' => [true, '/api/integrations/github/webhook/{connection}'],
    'inbound mail' => [false, '/api/mail/inbound'],
]);

test('the public API answers an authenticated unreadable body as it always did', function (): void {
    // Authenticated, because the token middleware is sorted ahead of this
    // check: without a token the answer is 401 either way, and the exemption
    // would go untested. The public API is a frozen contract (ADR 0018).
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $generated = ApiToken::generate();
    $token = ApiToken::query()->create([
        'account_id' => $account->id,
        'created_by_id' => User::factory()->create(['account_id' => $account->id])->id,
        'name' => 'Unreadable body',
        'token_hash' => $generated['hash'],
        'last_four' => $generated['last_four'],
        'abilities' => [ApiToken::ABILITY_WRITE],
        'restricts_sites' => true,
    ]);
    $token->sites()->attach($site->id);

    $response = test()->call('POST', '/api/v1/conversations', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$generated['plain'],
        'HTTP_IDEMPOTENCY_KEY' => 'unreadable-body',
    ], '{"broken":');

    expect($response->status())->not->toBe(401, 'the token did not authenticate, so this test proves nothing about the exemption');
    expect($response->json('error_key'))->not->toBe('error.unreadableRequest', "the public API is exempt, but was refused by the widget's unreadable-body check");
    expect($response->status())->not->toBe(400, 'the public API changed its answer to an undecodable body');
});

test('the refusal is said in the install’s language when it is not English', function (): void {
    app()->setLocale('de');

    $response = unreadableJsonPost('/api/conversations', unreadableJsonLoneSurrogateBody());

    expect($response->json('message'))->toBe('Die Anfrage konnte nicht gelesen werden.', 'the refusal was not said in the install’s language');
    expect($response->json('error_key'))->toBe('error.unreadableRequest');
});
