<?php

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Support transcripts, reachable by a credential with nobody behind it. Most of
 * what follows asserts absence: the far more dangerous bug here is a row that
 * appears where it should not, and nothing about the response looks wrong when
 * it does.
 */
function readWorld(): array
{
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create(['email' => 'ada@example.test', 'external_id' => 'crm-1']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-MINE01',
        'subject' => 'A conversation of mine',
    ]);

    $generated = ApiToken::generate();
    $token = ApiToken::query()->create([
        'account_id' => $account->id,
        'name' => 'Integration',
        'token_hash' => $generated['hash'],
        'last_four' => $generated['last_four'],
        'abilities' => [ApiToken::ABILITY_READ],
    ]);

    return compact('account', 'site', 'visitor', 'conversation', 'token') + ['plain' => $generated['plain']];
}

/**
 * A whole second account, so every isolation test has something real to leak.
 */
function otherWorld(): array
{
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create(['email' => 'someone@else.test', 'external_id' => 'crm-1']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-THEIR1',
        'subject' => 'Somebody else entirely',
    ]);
    $ticket = Ticket::factory()->for($account)->for($site)->create(['subject' => 'Their ticket']);

    return compact('account', 'site', 'visitor', 'conversation', 'ticket');
}

function readGet($test, array $w, string $uri)
{
    return $test->getJson($uri, ['Authorization' => 'Bearer '.$w['plain']]);
}

test('a token lists only its own account conversations', function (): void {
    $w = readWorld();
    otherWorld();

    $codes = collect(readGet($this, $w, '/api/v1/conversations')->assertOk()->json('data'))->pluck('support_code');

    expect($codes->all())->toBe(['WF-MINE01']);
});

test('another account conversation is not found, rather than forbidden', function (): void {
    // 403 would confirm the support code exists. Support codes are eight
    // characters and appear in emails.
    $w = readWorld();
    otherWorld();

    readGet($this, $w, '/api/v1/conversations/WF-THEIR1')->assertNotFound();
});

test('another account messages cannot be read through a conversation route', function (): void {
    $w = readWorld();
    $other = otherWorld();

    ConversationMessage::factory()->for($other['conversation'])->create(['body' => 'Private to them']);

    readGet($this, $w, '/api/v1/conversations/WF-THEIR1/messages')->assertNotFound();
});

test('a transcript reads oldest first', function (): void {
    // A transcript in reverse is not a transcript.
    $w = readWorld();

    foreach (['first', 'second', 'third'] as $i => $body) {
        ConversationMessage::factory()->for($w['conversation'])->create([
            'body' => $body,
            'created_at' => now()->subMinutes(10 - $i),
        ]);
    }

    $bodies = collect(readGet($this, $w, '/api/v1/conversations/WF-MINE01/messages')->assertOk()->json('data'))->pluck('body');

    expect($bodies->all())->toBe(['first', 'second', 'third']);
});

test('a token lists only its own account tickets', function (): void {
    $w = readWorld();
    otherWorld();

    Ticket::factory()->for($w['account'])->for($w['site'])->create(['subject' => 'Mine']);

    $subjects = collect(readGet($this, $w, '/api/v1/tickets')->assertOk()->json('data'))->pluck('subject');

    expect($subjects->all())->toBe(['Mine']);
});

test('another account ticket is not found by id', function (): void {
    $w = readWorld();
    $other = otherWorld();

    readGet($this, $w, '/api/v1/tickets/'.$other['ticket']->id)->assertNotFound();
});

test('a visitor lookup by external id cannot reach another account', function (): void {
    // Both accounts use `crm-1`, which is exactly what happens in practice --
    // two customers of Wayfindr numbering their own users from one.
    $w = readWorld();
    otherWorld();

    $emails = collect(readGet($this, $w, '/api/v1/visitors?external_id=crm-1')->assertOk()->json('data'))->pluck('email');

    expect($emails->all())->toBe(['ada@example.test']);
});

test('a site filter can only narrow, never widen', function (): void {
    // The dangerous failure is "unknown filter is ignored", which turns a
    // request for somebody else's site into a request for everything.
    $w = readWorld();
    $other = otherWorld();

    $body = readGet($this, $w, '/api/v1/conversations?site_id='.$other['site']->id)->assertOk()->json('data');

    expect($body)->toBe([]);
});

test('a token restricted to one site cannot read the account other sites', function (): void {
    $w = readWorld();

    $otherSite = Site::factory()->for($w['account'])->create();
    $otherVisitor = Visitor::factory()->for($otherSite)->create();
    Conversation::factory()->for($otherSite)->for($otherVisitor)->create(['support_code' => 'WF-OTHER1']);

    $w['token']->forceFill(['restricts_sites' => true])->save();
    $w['token']->sites()->attach($w['site']->id);

    $codes = collect(readGet($this, $w, '/api/v1/conversations')->assertOk()->json('data'))->pluck('support_code');

    expect($codes->all())->toBe(['WF-MINE01']);

    readGet($this, $w, '/api/v1/conversations/WF-OTHER1')->assertNotFound();
});

test('metadata is never published', function (): void {
    // A free-form column written by the widget, the SDK and the host page. Its
    // contents are whatever somebody's website put there, and exporting it
    // would publish data the operator never chose to expose.
    $w = readWorld();
    $w['conversation']->forceFill(['metadata' => ['secret' => 'do-not-publish']])->save();
    $w['visitor']->forceFill(['metadata' => ['also' => 'do-not-publish']])->save();

    $conversation = readGet($this, $w, '/api/v1/conversations/WF-MINE01')->assertOk();
    $visitors = readGet($this, $w, '/api/v1/visitors')->assertOk();

    $conversation->assertJsonMissingPath('data.metadata');
    expect($conversation->getContent())->not->toContain('do-not-publish')
        ->and($visitors->getContent())->not->toContain('do-not-publish');
});

test('a visitor anonymous id is never published', function (): void {
    // It is the widget's browser-session handle, not an identifier for a
    // person: publishing it hands a caller the key half of a visitor session.
    $w = readWorld();
    $w['visitor']->forceFill(['anonymous_id' => 'anon-secret-handle'])->save();

    expect(readGet($this, $w, '/api/v1/visitors')->assertOk()->getContent())
        ->not->toContain('anon-secret-handle');
});

test('a list is cursor paginated, and the cursor walks it without repeating', function (): void {
    // Offset pagination silently skips and repeats rows as new conversations
    // arrive, and an integration walking pages loses some with no error.
    $w = readWorld();

    foreach (range(1, 5) as $i) {
        Conversation::factory()->for($w['site'])->for($w['visitor'])->create([
            'support_code' => 'WF-PAGE0'.$i,
            'created_at' => now()->subMinutes($i),
        ]);
    }

    $first = readGet($this, $w, '/api/v1/conversations?per_page=2')->assertOk();
    $cursor = $first->json('meta.next_cursor');

    expect($cursor)->not->toBeNull();

    $second = readGet($this, $w, '/api/v1/conversations?per_page=2&cursor='.$cursor)->assertOk();

    $firstCodes = collect($first->json('data'))->pluck('support_code');
    $secondCodes = collect($second->json('data'))->pluck('support_code');

    expect($firstCodes)->toHaveCount(2)
        ->and($secondCodes)->toHaveCount(2)
        ->and($firstCodes->intersect($secondCodes)->all())->toBe([]);
});

test('a restriction naming a site outside the account grants nothing', function (): void {
    // Defence in depth, and not hypothetical: sites can be archived and purged,
    // and a restriction row is a plain foreign key that outlives the assumption
    // that it points somewhere this account owns. The account is the boundary
    // that has to hold whatever the restriction says.
    $w = readWorld();
    $other = otherWorld();

    // The pivot is attached directly, exactly as a stale or tampered row would
    // look. The dashboard would never offer this.
    $w['token']->forceFill(['restricts_sites' => true])->save();
    $w['token']->sites()->attach($other['site']->id);

    expect(readGet($this, $w, '/api/v1/me')->assertOk()->json('data.site_ids'))->toBe([]);

    readGet($this, $w, '/api/v1/conversations/WF-THEIR1')->assertNotFound();

    expect(readGet($this, $w, '/api/v1/conversations')->assertOk()->json('data'))->toBe([]);
});

test('scoping does not bind one parameter per site', function (): void {
    // An agency account can have hundreds of sites. Loading their ids and
    // passing them to whereIn costs one bind parameter each, and past the
    // driver limit every endpoint fails outright rather than being slow -- the
    // same shape that had to be fixed in the ticket reporting walk. Behaviour
    // is identical either way, so only the parameter count shows it.
    $w = readWorld();

    $sites = [];

    for ($i = 0; $i < 800; $i++) {
        $sites[] = [
            'account_id' => $w['account']->id,
            'name' => 'Site '.$i,
            'public_key' => 'site_public_bulk_'.$i,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    DB::table('sites')->insert($sites);

    $widest = 0;

    DB::listen(function ($query) use (&$widest): void {
        $widest = max($widest, count($query->bindings));
    });

    readGet($this, $w, '/api/v1/conversations')->assertOk();
    readGet($this, $w, '/api/v1/tickets')->assertOk();
    readGet($this, $w, '/api/v1/visitors')->assertOk();

    expect($widest)->toBeLessThan(20);

    // And again RESTRICTED, because that is a second whereIn on a second list
    // of ids -- and the first version of this test used an unrestricted token,
    // so the restriction branch never ran and the mutation survived.
    $w['token']->forceFill(['restricts_sites' => true])->save();
    $w['token']->sites()->sync(DB::table('sites')->where('account_id', $w['account']->id)->pluck('id'));

    $widest = 0;

    readGet($this, $w, '/api/v1/conversations')->assertOk();

    expect($widest)->toBeLessThan(20);
});

test('archiving a site does not make its history disappear from the API', function (): void {
    // Archiving takes a site out of service; it does not delete what happened
    // on it. A read surface that dropped archived sites would make a year of
    // transcripts vanish from an integration the day somebody tidied up --
    // and the dashboard would still be showing them.
    //
    // Matches ReportingScope, which includes archived sites for the same
    // reason. Purging is the operation that removes data.
    $w = readWorld();

    $w['site']->forceFill(['archived_at' => now()])->save();

    $codes = collect(readGet($this, $w, '/api/v1/conversations')->assertOk()->json('data'))->pluck('support_code');

    expect($codes->all())->toBe(['WF-MINE01'])
        ->and(readGet($this, $w, '/api/v1/me')->json('data.site_ids'))->toBe([$w['site']->id]);

    readGet($this, $w, '/api/v1/conversations/WF-MINE01')->assertOk();
});

test('purging a site does remove it from the API', function (): void {
    // The distinction the test above depends on: archive hides a site from
    // service, purge destroys its data. If purge did not clear the API too,
    // "purged" would mean something different to an integration than it does
    // to the dashboard.
    $w = readWorld();

    $w['site']->delete();

    expect(readGet($this, $w, '/api/v1/conversations')->assertOk()->json('data'))->toBe([])
        ->and(readGet($this, $w, '/api/v1/me')->json('data.site_ids'))->toBe([]);

    readGet($this, $w, '/api/v1/conversations/WF-MINE01')->assertNotFound();
});

test('the API answers in JSON even when the caller does not ask for it', function (): void {
    // Laravel decides that from `Accept`, so a client omitting the header --
    // including the curl example in our own docs -- got an HTML error page or a
    // redirect where the contract promises JSON. Every other test here uses
    // `getJson()`, which sets the header, so the suite could never show it.
    $w = readWorld();

    $response = $this->get('/api/v1/conversations?per_page=0', [
        'Authorization' => 'Bearer '.$w['plain'],
    ]);

    $response->assertStatus(422);

    expect($response->headers->get('content-type'))->toContain('application/json')
        ->and($response->json('message'))->not->toBeNull();

    // And a record outside the token's reach is a JSON 404, not an HTML one.
    $missing = $this->get('/api/v1/conversations/WF-NOSUCH', [
        'Authorization' => 'Bearer '.$w['plain'],
    ]);

    $missing->assertStatus(404);

    expect($missing->headers->get('content-type'))->toContain('application/json');
});

test('a corrupted cursor is refused, not silently restarted', function (): void {
    // Laravel reads an undecodable cursor as no cursor, so an integration
    // holding a truncated one quietly receives page one again -- reprocessing
    // rows it has already seen, or looping, with a 200 every time.
    $w = readWorld();

    foreach (['not-a-cursor', 'eyJ0cnVuY2F0ZWQ', base64_encode('{"broken":')] as $bad) {
        readGet($this, $w, '/api/v1/conversations?cursor='.urlencode($bad))->assertStatus(422);
    }

    // Every list endpoint, not just the one it was reported against.
    readGet($this, $w, '/api/v1/tickets?cursor=not-a-cursor')->assertStatus(422);
    readGet($this, $w, '/api/v1/visitors?cursor=not-a-cursor')->assertStatus(422);
    readGet($this, $w, '/api/v1/conversations/WF-MINE01/messages?cursor=not-a-cursor')->assertStatus(422);

    // A cursor the API itself issued still works.
    $cursor = readGet($this, $w, '/api/v1/conversations?per_page=1')->json('meta.next_cursor');

    if ($cursor !== null) {
        readGet($this, $w, '/api/v1/conversations?per_page=1&cursor='.urlencode($cursor))->assertOk();
    }
});

test('a decodable cursor missing its ordering columns is a 422, not a 500', function (): void {
    // `Cursor::fromEncoded()` returns a cursor for any decodable JSON object,
    // so this one passes a decode check and then fails inside cursorPaginate()
    // reaching for a column that is not there -- turning a malformed parameter
    // into a server error where the contract promises a 422.
    $w = readWorld();

    $crafted = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode(['_pointsToNextItems' => true])));

    foreach (['conversations', 'tickets', 'visitors'] as $collection) {
        readGet($this, $w, '/api/v1/'.$collection.'?cursor='.urlencode($crafted))->assertStatus(422);
    }

    readGet($this, $w, '/api/v1/conversations/WF-MINE01/messages?cursor='.urlencode($crafted))->assertStatus(422);
});

test('a cursor whose ordering values are not scalars is a 422, not a 500', function (): void {
    // Present is not usable: `Cursor::parameter()` only checks the key exists,
    // so an array under `created_at` passed the presence check and then broke
    // when the paginator bound it to the query.
    $w = readWorld();

    $crafted = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode([
        'created_at' => ['not', 'a', 'timestamp'],
        'id' => ['also' => 'not an id'],
        '_pointsToNextItems' => true,
    ])));

    foreach (['conversations', 'tickets', 'visitors'] as $collection) {
        readGet($this, $w, '/api/v1/'.$collection.'?cursor='.urlencode($crafted))->assertStatus(422);
    }
});

test('a cursor whose ordering values are null is a 422, not a 500', function (): void {
    // Decodes, has the keys, and then asks the database to order against
    // nothing -- the same server error by a shorter route.
    $w = readWorld();

    $crafted = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode([
        'created_at' => null,
        'id' => null,
        '_pointsToNextItems' => true,
    ])));

    readGet($this, $w, '/api/v1/conversations?cursor='.urlencode($crafted))->assertStatus(422);
    readGet($this, $w, '/api/v1/tickets?cursor='.urlencode($crafted))->assertStatus(422);
});
