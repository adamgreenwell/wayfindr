<?php

use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\Visitor;
use App\Support\VisitorContextSanitizer;
use App\Support\Visitors\StoredPageUrlSweep;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The unit test proves the function. This proves the WIRING -- that the two
 * places a visitor's page address enters the product actually run it through.
 *
 * Worth separating, because the sanitiser existed as a class for some minutes
 * before anything called it, and a green unit test says nothing about that.
 */
function visitorPageUrlSite(): Site
{
    $account = Account::factory()->create();

    return Site::factory()->for($account)->create(['public_key' => 'site_public_pages']);
}

test('bootstrap stores the page without its query string', function (): void {
    $site = visitorPageUrlSite();

    $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-pages',
        'page_url' => 'https://shop.test/account/reset?reset_token=abc123&email=a@b.test',
    ])->assertSuccessful();

    $visitor = Visitor::query()->where('anonymous_id', 'anon-pages')->firstOrFail();

    expect($visitor->metadata['last_page_url'])->toBe('https://shop.test/account/reset');

    $this->assertStringNotContainsString('reset_token', json_encode($visitor->metadata) ?: '');
    $this->assertStringNotContainsString('a@b.test', json_encode($visitor->metadata) ?: '');
});

test('starting a conversation stores it sanitised too', function (): void {
    // The second writer. Fixing only bootstrap would leave the more likely
    // path open: somebody asks for help FROM the page that is going wrong.
    $site = visitorPageUrlSite();

    $token = $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-convo',
    ])->assertSuccessful()->json('data.visitor.token');

    $this->postJson('/api/conversations', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-convo',
        'visitor_token' => $token,
        'body' => 'This reset link is not working',
        'page_url' => 'https://shop.test/account/reset?reset_token=xyz789',
    ])->assertSuccessful();

    $visitor = Visitor::query()->where('anonymous_id', 'anon-convo')->firstOrFail();

    expect($visitor->metadata['last_page_url'])->toBe('https://shop.test/account/reset');

    $this->assertStringNotContainsString('xyz789', json_encode($visitor->metadata) ?: '');
});

test('a page with nothing to remove is stored unchanged', function (): void {
    // The sanitiser must not cost an agent the context the field exists for.
    $site = visitorPageUrlSite();

    $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-plain',
        'page_url' => 'https://shop.test/docs/install/forge',
    ])->assertSuccessful();

    expect(Visitor::query()->where('anonymous_id', 'anon-plain')->firstOrFail()->metadata['last_page_url'])
        ->toBe('https://shop.test/docs/install/forge');
});

test('page addresses already stored whole are rewritten', function (): void {
    // The forward fix does nothing for rows that already exist, and those are
    // the reason this matters: a token stored last month is on an agent's
    // screen the next time somebody opens that visitor.
    $site = visitorPageUrlSite();

    // Written past the model, the way the old code would have left it.
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-historical']);

    DB::table('visitors')->where('id', $visitor->id)->update([
        'metadata' => json_encode([
            'last_page_url' => 'https://shop.test/account/reset?reset_token=old-token&email=a@b.test',
            'context' => ['plan' => 'pro'],
        ]),
    ]);

    // The migration under test, re-run against the row just planted.
    StoredPageUrlSweep::run();

    $metadata = json_decode((string) DB::table('visitors')->where('id', $visitor->id)->value('metadata'), true);

    expect($metadata['last_page_url'])->toBe('https://shop.test/account/reset');

    // And it did not eat what it does not own.
    expect($metadata['context'])->toBe(['plan' => 'pro']);

    $this->assertStringNotContainsString('old-token', json_encode($metadata) ?: '');
});

test('the rewrite leaves a row it has nothing to do with alone', function (): void {
    $site = visitorPageUrlSite();
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-clean']);

    DB::table('visitors')->where('id', $visitor->id)->update([
        'metadata' => json_encode(['last_page_url' => 'https://shop.test/pricing']),
    ]);

    $before = DB::table('visitors')->where('id', $visitor->id)->first();

    StoredPageUrlSweep::run();

    $after = DB::table('visitors')->where('id', $visitor->id)->first();

    expect(json_decode((string) $after->metadata, true)['last_page_url'])
        ->toBe('https://shop.test/pricing')
        ->and($after->updated_at)->toBe($before->updated_at, 'an untouched row was still written');
});

test('the conversation entry page is sanitised too', function (): void {
    // The SECOND writer, and the likelier one: people ask for help FROM the
    // page that is going wrong. On a reset flow that is the page holding the
    // token, and this copy is what the agent panels label the entry page.
    $site = visitorPageUrlSite();

    $token = $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-entry',
    ])->assertSuccessful()->json('data.visitor.token');

    $this->postJson('/api/conversations', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-entry',
        'visitor_token' => $token,
        'body' => 'This link is broken',
        'page_url' => 'https://shop.test/invite/accept?invite=SECRETCODE',
    ])->assertSuccessful();

    $conversation = Conversation::query()->latest('id')->firstOrFail();

    expect($conversation->metadata['started_page_url'])->toBe('https://shop.test/invite/accept');

    $this->assertStringNotContainsString('SECRETCODE', json_encode($conversation->metadata) ?: '');
});

test('historical conversation and ticket copies are rewritten as well', function (): void {
    // The ticket copy is the one that survives everything else: it is a
    // point-in-time SNAPSHOT rather than a reference, so sanitising the sources
    // it was copied from never reaches it -- and tickets outlive the
    // conversations that produced them.
    $site = visitorPageUrlSite();
    $account = $site->account;
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-durable']);

    $conversation = Conversation::factory()->for($site)->for($visitor)->create();

    DB::table('conversations')->where('id', $conversation->id)->update([
        'metadata' => json_encode([
            'started_page_url' => 'https://shop.test/reset?reset_token=conv-token',
            'something_else' => 'kept',
        ]),
    ]);

    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->create();

    DB::table('tickets')->where('id', $ticket->id)->update([
        'metadata' => json_encode([
            'visitor_context' => [
                'started_page_url' => 'https://shop.test/reset?reset_token=ticket-token',
                'last_page_url' => 'https://shop.test/help?email=a@b.test',
                'host_context' => ['plan' => 'pro'],
            ],
        ]),
    ]);

    StoredPageUrlSweep::run();

    $conversationMetadata = json_decode((string) DB::table('conversations')->where('id', $conversation->id)->value('metadata'), true);
    $ticketMetadata = json_decode((string) DB::table('tickets')->where('id', $ticket->id)->value('metadata'), true);

    expect($conversationMetadata['started_page_url'])->toBe('https://shop.test/reset')
        ->and($conversationMetadata['something_else'])->toBe('kept');

    expect($ticketMetadata['visitor_context']['started_page_url'])->toBe('https://shop.test/reset')
        ->and($ticketMetadata['visitor_context']['last_page_url'])->toBe('https://shop.test/help')
        ->and($ticketMetadata['visitor_context']['host_context'])->toBe(['plan' => 'pro']);

    $all = json_encode([$conversationMetadata, $ticketMetadata]) ?: '';

    $this->assertStringNotContainsString('conv-token', $all);
    $this->assertStringNotContainsString('ticket-token', $all);
    $this->assertStringNotContainsString('a@b.test', $all);
});

test('the sweep catches a row written while the old code was still serving', function (): void {
    // The deploy runs `migrate` BEFORE activating the release, so the previous
    // release keeps accepting widget traffic with the unsanitised writers while
    // the migration sweeps. A row written after its chunk was passed keeps its
    // query string, and the migration reports success anyway.
    //
    // This is that row: written AFTER a full sweep has already run.
    $site = visitorPageUrlSite();
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-race']);

    StoredPageUrlSweep::run();

    // The old writer, landing during the window between migrate and activate.
    DB::table('visitors')->where('id', $visitor->id)->update([
        'metadata' => json_encode(['last_page_url' => 'https://shop.test/reset?reset_token=late-token']),
    ]);

    // The post-activation pass, which is the only thing that can see it.
    $rewritten = StoredPageUrlSweep::run();

    $metadata = json_decode((string) DB::table('visitors')->where('id', $visitor->id)->value('metadata'), true);

    expect($metadata['last_page_url'])->toBe('https://shop.test/reset')
        ->and($rewritten['visitors'])->toBe(1, 'the second pass reported nothing to do');
});

test('running the sweep again changes nothing and says so', function (): void {
    // Idempotent, because the deploy runs it on every release from here on. A
    // second run reporting work would mean it is rewriting its own output.
    $site = visitorPageUrlSite();
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-idempotent']);

    DB::table('visitors')->where('id', $visitor->id)->update([
        'metadata' => json_encode(['last_page_url' => 'https://shop.test/p?token=x']),
    ]);

    expect(StoredPageUrlSweep::run()['visitors'])->toBe(1);
    expect(StoredPageUrlSweep::run()['visitors'])->toBe(0, 'the sweep rewrote its own output');
});

test('the command is the same sweep, reachable by hand', function (): void {
    // Operators on install shapes that are not the Forge script need a way to
    // run the post-activation pass themselves.
    $site = visitorPageUrlSite();
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-command']);

    DB::table('visitors')->where('id', $visitor->id)->update([
        'metadata' => json_encode(['last_page_url' => 'https://shop.test/p?session=abc']),
    ]);

    $this->artisan('wayfindr:sanitise-page-urls')
        ->expectsOutputToContain('Rewrote 1 stored page address(es).')
        ->assertExitCode(0);

    expect(json_decode((string) DB::table('visitors')->where('id', $visitor->id)->value('metadata'), true)['last_page_url'])
        ->toBe('https://shop.test/p');
});

test('a concurrent write during the sweep is not discarded', function (): void {
    // Both passes run while requests are being served. The chunk SELECT decides
    // which rows are worth touching; if the same snapshot were also what got
    // written, a widget updating that row in between would have its change
    // replaced by the stale document -- losing a page change, or a whole
    // context blob.
    //
    // Interposed for real: a listener fires on the chunk SELECT and writes the
    // row before the sweep reaches it, which is exactly the window. The sweep
    // re-reads under a lock, so what lands is the sanitised form of the value
    // that is actually there.
    $site = visitorPageUrlSite();
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-concurrent']);

    DB::table('visitors')->where('id', $visitor->id)->update([
        'metadata' => json_encode(['last_page_url' => 'https://shop.test/old?token=stale']),
    ]);

    $interposed = false;

    DB::listen(function ($query) use ($visitor, &$interposed): void {
        // The chunk read, not the locking re-read: once, and only for visitors.
        if ($interposed
            || ! str_contains($query->sql, 'from "visitors"')
            || ! str_contains($query->sql, 'order by "id" asc')) {
            return;
        }

        $interposed = true;

        DB::table('visitors')->where('id', $visitor->id)->update([
            'metadata' => json_encode([
                'last_page_url' => 'https://shop.test/new?token=fresh',
                'context' => ['plan' => 'written-during-sweep'],
            ]),
        ]);
    });

    StoredPageUrlSweep::run();

    expect($interposed)->toBeTrue('nothing was written during the sweep, so this proves nothing');

    $metadata = json_decode((string) DB::table('visitors')->where('id', $visitor->id)->value('metadata'), true);

    // Sanitised from what was ACTUALLY there, and the concurrent context kept.
    expect($metadata['last_page_url'])->toBe('https://shop.test/new')
        ->and($metadata['context'])->toBe(['plan' => 'written-during-sweep']);
});

test('a writer that omits the page url does not restore a token the sweep removed', function (): void {
    // The ordering the row lock cannot reach. A request reads the visitor
    // BEFORE the sweep locks that row, so it holds the old tokenised URL. It
    // omits `page_url` -- optional on both bootstrap and conversation start --
    // and its ordinary save() lands after the sweep commits, putting the token
    // straight back. The lock is irrelevant: the read that matters happened
    // before it existed.
    $site = visitorPageUrlSite();
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-restore']);

    DB::table('visitors')->where('id', $visitor->id)->update([
        'metadata' => json_encode(['last_page_url' => 'https://shop.test/reset?reset_token=restored']),
    ]);

    // The request's read, taken before the sweep runs.
    $stale = Visitor::query()->findOrFail($visitor->id);
    $staleMetadata = $stale->metadata;

    StoredPageUrlSweep::run();

    // The request finishes and writes back what it read. It supplies CONTEXT
    // and no page_url, which is an ordinary bootstrap for a host page that
    // passes attributes -- and it is what makes this reachable at all.
    //
    // Two earlier versions of this test proved nothing, both because Eloquent
    // writes only DIRTY attributes: with the metadata unchanged, the column is
    // simply absent from the UPDATE and the sweep's value survives on its own.
    // Something has to change metadata for the retained URL to ride along, and
    // `context` is the everyday thing that does.
    $stale->forceFill([
        'metadata' => app(VisitorContextSanitizer::class)->mergeMetadata(
            $staleMetadata,
            null,
            true,
            ['plan' => 'pro'],
        ),
        'last_seen_at' => now(),
    ])->save();

    $metadata = json_decode((string) DB::table('visitors')->where('id', $visitor->id)->value('metadata'), true);

    expect($metadata['last_page_url'])->toBe('https://shop.test/reset');

    $this->assertStringNotContainsString('restored', json_encode($metadata) ?: '');
});

test('a retained page url that is already clean is left exactly as it is', function (): void {
    // Sanitising on the way out must not cost the field its value.
    $merged = app(VisitorContextSanitizer::class)->mergeMetadata(
        ['last_page_url' => 'https://shop.test/docs/install'],
        null,
        false,
        null,
    );

    expect($merged['last_page_url'])->toBe('https://shop.test/docs/install');
});

test('the sweep is scheduled, not only run at deploy time', function (): void {
    // The deploy's own pass cannot be the last word: activation stops NEW
    // requests reaching the old release but does not cancel ones already
    // executing, and those still run the unsanitised writer. A request that
    // began before activation can write after the sweep passed its row.
    //
    // It also covers Docker and Compose installs, which upgrade without ever
    // running the Forge script.
    $events = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command);

    expect($events->filter(fn (string $c): bool => str_contains($c, 'wayfindr:sanitise-page-urls')))
        ->not->toBeEmpty('the sweep is not scheduled, so a straggler write is never cleaned');
});
