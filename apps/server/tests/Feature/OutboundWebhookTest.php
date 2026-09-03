<?php

use App\Enums\AccountRole;
use App\Jobs\DeliverOutboundWebhook;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\OutboundWebhookDelivery;
use App\Models\OutboundWebhookEndpoint;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Support\SitePurge;
use App\Support\Webhooks\OutboundWebhookDestination;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;

uses(RefreshDatabase::class);

/** @return array{account: Account, admin: User, site: Site, otherSite: Site} */
function outboundWebhookWorld(): array
{
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['name' => 'Support']);
    $otherSite = Site::factory()->for($account)->create(['name' => 'Docs']);

    return compact('account', 'admin', 'site', 'otherSite');
}

function allowWebhookDns(array $answers = ['8.8.8.8']): OutboundWebhookDestination
{
    $destination = new OutboundWebhookDestination(fn (string $host): array => $answers);
    app()->instance(OutboundWebhookDestination::class, $destination);

    return $destination;
}

test('an admin creates a scoped endpoint and sees its encrypted signing secret once', function (): void {
    $world = outboundWebhookWorld();
    allowWebhookDns();

    $response = $this->actingAs($world['admin'])->post(route('dashboard.account.outbound-webhooks.store'), [
        'webhook' => [
            'name' => 'Warehouse listener',
            'url' => 'https://hooks.example.test/wayfindr',
            'events' => [OutboundWebhookEndpoint::EVENT_TICKET_CREATED],
            'site_ids' => [$world['site']->id],
        ],
    ]);

    $response->assertRedirect(route('dashboard.account.api-tokens.index'));

    $endpoint = OutboundWebhookEndpoint::query()->sole();
    $plainSecret = $endpoint->secret;

    expect($endpoint->url)->toBe('https://hooks.example.test/wayfindr')
        ->and($plainSecret)->toStartWith(OutboundWebhookEndpoint::SECRET_PREFIX)
        ->and($endpoint->sites()->pluck('sites.id')->all())->toBe([$world['site']->id])
        ->and($endpoint->events)->toBe([OutboundWebhookEndpoint::EVENT_TICKET_CREATED])
        ->and($endpoint->getRawOriginal('url'))->not->toContain('hooks.example.test')
        ->and($endpoint->getRawOriginal('secret'))->not->toContain($plainSecret)
        ->and(AuditEvent::query()->where('action', 'outbound_webhook.created')->count())->toBe(1);

    $this->get(route('dashboard.account.api-tokens.index'))
        ->assertOk()
        ->assertSee($plainSecret)
        ->assertSee('Warehouse listener');

    $this->get(route('dashboard.account.api-tokens.index'))
        ->assertOk()
        ->assertDontSee($plainSecret);
});

test('an endpoint is pinned to the issuer current site ceiling and never widens', function (): void {
    $world = outboundWebhookWorld();
    allowWebhookDns();
    $world['site']->supportAgents()->attach($world['admin']);
    $world['otherSite']->supportAgents()->attach(User::factory()->for($world['account'])->create());

    $this->actingAs($world['admin'])->post(route('dashboard.account.outbound-webhooks.store'), [
        'webhook' => [
            'name' => 'Scoped listener',
            'url' => 'https://hooks.example.test/wayfindr',
            'events' => OutboundWebhookEndpoint::EVENTS,
        ],
    ])->assertRedirect();

    $later = Site::factory()->for($world['account'])->create();
    $endpoint = OutboundWebhookEndpoint::query()->sole();

    expect($endpoint->restricts_sites)->toBeTrue()
        ->and($endpoint->sites()->pluck('sites.id')->all())->toBe([$world['site']->id])
        ->and($endpoint->sites()->whereKey($later->id)->exists())->toBeFalse();
});

test('endpoint creation refuses destinations that resolve inside the host network', function (): void {
    $world = outboundWebhookWorld();
    allowWebhookDns(['169.254.169.254']);

    $this->actingAs($world['admin'])->post(route('dashboard.account.outbound-webhooks.store'), [
        'webhook' => [
            'name' => 'Metadata tunnel',
            'url' => 'https://metadata.example.test/latest',
            'events' => [OutboundWebhookEndpoint::EVENT_TICKET_CREATED],
        ],
    ])->assertSessionHasErrors('webhook.url');

    expect(OutboundWebhookEndpoint::query()->count())->toBe(0);
});

test('all four subscribed domain events produce ordered thin payloads', function (): void {
    Queue::fake();
    $world = outboundWebhookWorld();
    $endpoint = OutboundWebhookEndpoint::factory()->for($world['account'])->create();
    $endpoint->sites()->attach($world['site']);
    $visitor = Visitor::factory()->for($world['site'])->create(['metadata' => ['secret' => 'visitor metadata']]);

    $conversation = Conversation::factory()->for($world['site'])->for($visitor)->create([
        'support_code' => 'WF-WEBHOOK',
        'subject' => 'Private subject',
        'metadata' => ['secret' => 'conversation metadata'],
    ]);
    $message = ConversationMessage::factory()->for($conversation)->create(['body' => 'Private message body']);
    $ticket = Ticket::factory()->for($world['site'])->create([
        'subject' => 'Private ticket subject',
        'description' => 'Private ticket description',
    ]);
    $ticket->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
    $ticket->forceFill(['priority' => 'high'])->save();

    $deliveries = $endpoint->deliveries()->orderBy('sequence')->get();

    expect($deliveries)->toHaveCount(4)
        ->and($deliveries->pluck('event')->all())->toBe(OutboundWebhookEndpoint::EVENTS)
        ->and($deliveries->pluck('sequence')->all())->toBe([1, 2, 3, 4])
        ->and($endpoint->fresh()->next_sequence)->toBe(5)
        ->and($deliveries[0]->payload['resource'])->toBe([
            'type' => 'conversation',
            'support_code' => 'WF-WEBHOOK',
        ])
        ->and($deliveries[1]->payload['resource'])->toBe([
            'type' => 'conversation_message',
            'id' => $message->id,
            'conversation_support_code' => 'WF-WEBHOOK',
        ])
        ->and($deliveries[2]->payload['resource'])->toBe(['type' => 'ticket', 'id' => $ticket->id])
        ->and($deliveries[3]->payload['resource'])->toBe(['type' => 'ticket', 'id' => $ticket->id]);

    foreach ($deliveries as $delivery) {
        expect(array_keys($delivery->payload))->toBe(['id', 'event', 'sequence', 'occurred_at', 'site_id', 'resource'])
            ->and(json_encode($delivery->payload))->not->toContain('Private')
            ->and(json_encode($delivery->payload))->not->toContain('metadata')
            ->and(json_encode($delivery->payload))->not->toContain('anonymous_id');
    }
});

test('events honor subscription and site scope', function (): void {
    $world = outboundWebhookWorld();
    $endpoint = OutboundWebhookEndpoint::factory()->for($world['account'])->create([
        'events' => [OutboundWebhookEndpoint::EVENT_TICKET_CREATED],
    ]);
    $endpoint->sites()->attach($world['site']);

    Conversation::factory()
        ->for($world['site'])
        ->for(Visitor::factory()->for($world['site']))
        ->create();
    Ticket::factory()->for($world['otherSite'])->create();
    Ticket::factory()->for($world['site'])->create();

    expect($endpoint->deliveries()->count())->toBe(1)
        ->and($endpoint->deliveries()->sole()->event)->toBe(OutboundWebhookEndpoint::EVENT_TICKET_CREATED);
});

test('delivery signs the exact thin body and records subscriber acceptance', function (): void {
    $http = new MockHandler([new PsrResponse(202, [], 'accepted')]);
    Http::globalOptions(['handler' => $http]);
    $destination = allowWebhookDns();
    $world = outboundWebhookWorld();
    $endpoint = OutboundWebhookEndpoint::factory()->for($world['account'])->create([
        'secret' => 'whsec_test_secret',
    ]);
    $delivery = OutboundWebhookDelivery::factory()->for($endpoint, 'endpoint')->create();

    (new DeliverOutboundWebhook($delivery->id))->handle($destination);

    $request = $http->getLastRequest();
    $body = json_encode($delivery->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    expect((string) $request?->getUri())->toBe('https://hooks.example.test/wayfindr')
        ->and((string) $request?->getBody())->toBe($body)
        ->and($request?->getHeader('X-Wayfindr-Event'))->toBe([$delivery->event])
        ->and($request?->getHeader('X-Wayfindr-Delivery'))->toBe([$delivery->public_id])
        ->and($request?->getHeader('X-Wayfindr-Signature'))->toBe(['sha256='.hash_hmac('sha256', $body, 'whsec_test_secret')]);

    $delivery->refresh();

    expect($delivery->attempts)->toBe(1)
        ->and($delivery->response_status)->toBe(202)
        ->and($delivery->response_body)->toBe('accepted')
        ->and($delivery->delivered_at)->not->toBeNull()
        ->and($delivery->getRawOriginal('response_body'))->not->toContain('accepted');
});

test('delivery holds its lifecycle transaction through the subscriber request', function (): void {
    $levels = [];
    $baseline = DB::transactionLevel();

    Http::fake(function () use (&$levels) {
        $levels[] = DB::transactionLevel();

        return Http::response('', 202);
    });

    $destination = allowWebhookDns();
    $world = outboundWebhookWorld();
    $endpoint = OutboundWebhookEndpoint::factory()->for($world['account'])->create();
    $delivery = OutboundWebhookDelivery::factory()->for($endpoint, 'endpoint')->create([
        'site_id' => $world['site']->id,
    ]);

    (new DeliverOutboundWebhook($delivery->id))->handle($destination);

    expect($levels)->toBe([$baseline + 1])
        ->and($delivery->fresh()->delivered_at)->not->toBeNull();
});

test('a disable that wins after the worker pointer read prevents the request', function (): void {
    Http::fake();
    $destination = allowWebhookDns();
    $world = outboundWebhookWorld();
    $endpoint = OutboundWebhookEndpoint::factory()->for($world['account'])->create();
    $delivery = OutboundWebhookDelivery::factory()->for($endpoint, 'endpoint')->create([
        'site_id' => $world['site']->id,
    ]);
    $disabled = false;
    $baseline = DB::transactionLevel();

    OutboundWebhookDelivery::retrieved(function (OutboundWebhookDelivery $observed) use (&$disabled, $baseline, $delivery, $endpoint): void {
        if ($disabled || (int) $observed->id !== (int) $delivery->id || DB::transactionLevel() !== $baseline) {
            return;
        }

        $disabled = true;
        OutboundWebhookEndpoint::query()->whereKey($endpoint->id)->update(['disabled_at' => now()]);
    });

    (new DeliverOutboundWebhook($delivery->id))->handle($destination);

    Http::assertNothingSent();
    expect($delivery->fresh()->cancelled_at)->not->toBeNull()
        ->and($delivery->fresh()->attempts)->toBe(0);
});

test('a purge that wins after the worker pointer read prevents the request', function (): void {
    Http::fake();
    $destination = allowWebhookDns();
    $world = outboundWebhookWorld();
    $endpoint = OutboundWebhookEndpoint::factory()->for($world['account'])->create();
    $delivery = OutboundWebhookDelivery::factory()->for($endpoint, 'endpoint')->create([
        'site_id' => $world['site']->id,
    ]);
    $purged = false;
    $baseline = DB::transactionLevel();

    OutboundWebhookDelivery::retrieved(function (OutboundWebhookDelivery $observed) use (&$purged, $baseline, $delivery, $world): void {
        if ($purged || (int) $observed->id !== (int) $delivery->id || DB::transactionLevel() !== $baseline) {
            return;
        }

        $purged = true;
        app(SitePurge::class)->purge($world['site'], $world['admin']);
    });

    (new DeliverOutboundWebhook($delivery->id))->handle($destination);

    Http::assertNothingSent();
    expect(OutboundWebhookDelivery::query()->whereKey($delivery->id)->exists())->toBeFalse();
});

test('delivery shares the site guard and exclusively locks only its own row', function (): void {
    // SQLite compiles SELECT FOR UPDATE away, so the two stale-pointer tests
    // prove the recheck and this invariant guard protects the synchronization
    // that PostgreSQL supplies in production and in the PostgreSQL CI lane. An
    // endpoint lock here would also block foreground sequence allocation.
    $source = file_get_contents(dirname(__DIR__, 2).'/app/Jobs/DeliverOutboundWebhook.php');

    expect($source)->not->toBeFalse()
        ->and(substr_count((string) $source, '->sharedLock()'))->toBe(1)
        ->and(substr_count((string) $source, '->lockForUpdate()'))->toBe(1)
        ->and($source)->not->toContain('OutboundWebhookEndpoint::query()');
});

test('subscriber failure is retryable and worker exhaustion becomes visible', function (): void {
    $http = new MockHandler([new PsrResponse(503, [], 'temporarily unavailable')]);
    Http::globalOptions(['handler' => $http]);
    $destination = allowWebhookDns();
    $world = outboundWebhookWorld();
    $endpoint = OutboundWebhookEndpoint::factory()->for($world['account'])->create();
    $delivery = OutboundWebhookDelivery::factory()->for($endpoint, 'endpoint')->create();
    $job = new DeliverOutboundWebhook($delivery->id);

    expect(fn () => $job->handle($destination))->toThrow(RuntimeException::class, 'Outbound webhook delivery failed.');

    $delivery->refresh();
    expect($delivery->attempts)->toBe(1)
        ->and($delivery->response_status)->toBe(503)
        ->and($delivery->response_body)->toBe('temporarily unavailable')
        ->and($delivery->last_error)->toBe('http_status')
        ->and($delivery->failed_at)->toBeNull();

    $job->failed(new RuntimeException('worker exhausted'));
    expect($delivery->fresh()->failed_at)->not->toBeNull();
});

test('subscriber response bodies are bounded while the transport writes them', function (): void {
    $http = new MockHandler([new PsrResponse(204, [], str_repeat('a', 1024 * 1024))]);
    Http::globalOptions(['handler' => $http]);
    $destination = allowWebhookDns();
    $world = outboundWebhookWorld();
    $endpoint = OutboundWebhookEndpoint::factory()->for($world['account'])->create();
    $delivery = OutboundWebhookDelivery::factory()->for($endpoint, 'endpoint')->create([
        'site_id' => $world['site']->id,
    ]);

    (new DeliverOutboundWebhook($delivery->id))->handle($destination);

    $sink = $http->getLastOptions()['sink'] ?? null;

    expect($sink)->not->toBeNull()
        ->and($sink->getSize())->toBe(4096)
        ->and(strlen((string) $delivery->fresh()->response_body))->toBe(4096);
});

test('destination checks reject internal and mixed DNS answers and pin a public answer', function (): void {
    expect((new OutboundWebhookDestination(fn (): array => ['127.0.0.1']))->isAllowed('https://hooks.example.test'))->toBeFalse()
        ->and((new OutboundWebhookDestination(fn (): array => ['::1']))->isAllowed('https://hooks.example.test'))->toBeFalse()
        ->and((new OutboundWebhookDestination(fn (): array => ['fec0::1234']))->isAllowed('https://hooks.example.test'))->toBeFalse()
        ->and((new OutboundWebhookDestination(fn (): array => ['100:0:0:1::1']))->isAllowed('https://hooks.example.test'))->toBeFalse()
        ->and((new OutboundWebhookDestination(fn (): array => ['4000::1']))->isAllowed('https://hooks.example.test'))->toBeFalse()
        ->and((new OutboundWebhookDestination(fn (): array => ['100.64.0.1']))->isAllowed('https://hooks.example.test'))->toBeFalse()
        ->and((new OutboundWebhookDestination(fn (): array => ['192.0.2.1']))->isAllowed('https://hooks.example.test'))->toBeFalse()
        ->and((new OutboundWebhookDestination(fn (): array => ['2001:db8::1']))->isAllowed('https://hooks.example.test'))->toBeFalse()
        ->and((new OutboundWebhookDestination(fn (): array => ['8.8.8.8', '10.0.0.5']))->isAllowed('https://hooks.example.test'))->toBeFalse()
        ->and((new OutboundWebhookDestination(fn (): array => ['8.8.8.8']))->isAllowed('http://hooks.example.test'))->toBeFalse()
        ->and((new OutboundWebhookDestination(fn (): array => ['8.8.8.8']))->isAllowed('https://user:pass@hooks.example.test'))->toBeFalse()
        ->and((new OutboundWebhookDestination(fn (): array => ['8.8.8.8']))->isAllowed('https://hooks.example.test./wayfindr'))->toBeFalse();

    $inspected = (new OutboundWebhookDestination(fn (): array => ['8.8.8.8', '2001:4860:4860::8888', '1.1.1.1']))
        ->inspect('https://hooks.example.test:8443/path');

    expect($inspected['ips'])->toBe(['1.1.1.1', '2001:4860:4860::8888', '8.8.8.8'])
        ->and($inspected['curl'][CURLOPT_RESOLVE])->toBe(['hooks.example.test:8443:1.1.1.1,[2001:4860:4860::8888],8.8.8.8'])
        ->and($inspected['curl'][CURLOPT_NOPROXY])->toBe('*');
});

test('destination accepts a bracketed public IPv6 literal without resolving it again', function (): void {
    $destination = new OutboundWebhookDestination;
    $url = 'https://[2001:4860:4860::8888]:8443/wayfindr';
    $inspected = $destination->inspect($url);

    expect($inspected['url'])->toBe($url)
        ->and($inspected['host'])->toBe('2001:4860:4860::8888')
        ->and($inspected['port'])->toBe(8443)
        ->and($inspected['ips'])->toBe(['2001:4860:4860::8888'])
        ->and($inspected['curl'])->toBe([CURLOPT_NOPROXY => '*'])
        ->and($destination->isAllowed('https://[::1]/wayfindr'))->toBeFalse();
});

test('purging a site deletes its delivery data before recovery can send it', function (): void {
    Queue::fake();
    $world = outboundWebhookWorld();
    $endpoint = OutboundWebhookEndpoint::factory()->for($world['account'])->create();
    $endpoint->sites()->attach($world['site']);
    $delivery = OutboundWebhookDelivery::factory()->for($endpoint, 'endpoint')->create([
        'site_id' => $world['site']->id,
        'response_body' => 'subscriber detail that must be purged',
    ]);

    app(SitePurge::class)->purge($world['site'], $world['admin']);
    $this->artisan('wayfindr:queue-outbound-webhooks')->assertSuccessful();

    expect(OutboundWebhookDelivery::query()->whereKey($delivery->id)->exists())->toBeFalse()
        ->and($endpoint->fresh()->sites()->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

test('dashboard ticket creation rolls back when its webhook outbox cannot be written', function (): void {
    $world = outboundWebhookWorld();
    $visitor = Visitor::factory()->for($world['site'])->create();
    $conversation = Conversation::factory()->for($world['site'])->for($visitor)->create([
        'support_code' => 'WF-TICKET-ATOMIC',
    ]);
    $endpoint = OutboundWebhookEndpoint::factory()->for($world['account'])->create([
        'events' => [OutboundWebhookEndpoint::EVENT_TICKET_CREATED],
        'next_sequence' => 1,
    ]);
    $endpoint->sites()->attach($world['site']);
    OutboundWebhookDelivery::factory()->for($endpoint, 'endpoint')->create([
        'site_id' => $world['site']->id,
        'sequence' => 1,
    ]);

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($world['admin'])->post(
        route('dashboard.conversations.tickets.store', $conversation->support_code),
    ))->toThrow(QueryException::class);

    expect(Ticket::query()->where('conversation_id', $conversation->id)->exists())->toBeFalse();
});

test('dashboard ticket replies roll back when their webhook outbox cannot be written', function (): void {
    $world = outboundWebhookWorld();
    $visitor = Visitor::factory()->for($world['site'])->create();
    $conversation = Conversation::factory()->for($world['site'])->for($visitor)->create([
        'support_code' => 'WF-REPLY-ATOMIC',
        'status' => 'closed',
        'closed_at' => now(),
    ]);
    $ticket = Ticket::factory()->for($world['account'])->for($world['site'])->for($conversation)->for($visitor, 'requester')->create();
    $endpoint = OutboundWebhookEndpoint::factory()->for($world['account'])->create([
        'events' => [OutboundWebhookEndpoint::EVENT_CONVERSATION_MESSAGE_CREATED],
        'next_sequence' => 1,
    ]);
    $endpoint->sites()->attach($world['site']);
    OutboundWebhookDelivery::factory()->for($endpoint, 'endpoint')->create([
        'site_id' => $world['site']->id,
        'sequence' => 1,
    ]);

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($world['admin'])->post(
        route('dashboard.tickets.replies.store', $ticket),
        ['message' => 'This must not commit without its outbox.'],
    ))->toThrow(QueryException::class);

    expect($conversation->messages()->exists())->toBeFalse()
        ->and($conversation->fresh()->status)->toBe('closed');
});

test('disabling keeps history, cancels pending rows, and stops new events', function (): void {
    $world = outboundWebhookWorld();
    $endpoint = OutboundWebhookEndpoint::factory()->for($world['account'])->create();
    $endpoint->sites()->attach($world['site']);
    $pending = OutboundWebhookDelivery::factory()->for($endpoint, 'endpoint')->create(['site_id' => $world['site']->id]);

    $this->actingAs($world['admin'])
        ->delete(route('dashboard.account.outbound-webhooks.destroy', $endpoint))
        ->assertRedirect(route('dashboard.account.api-tokens.index'));

    Ticket::factory()->for($world['site'])->create();

    expect($endpoint->fresh()->disabled_at)->not->toBeNull()
        ->and($pending->fresh()->cancelled_at)->not->toBeNull()
        ->and($endpoint->deliveries()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'outbound_webhook.disabled')->count())->toBe(1);
});

test('an admin can requeue a visible failed delivery but not another account delivery', function (): void {
    Queue::fake();
    $world = outboundWebhookWorld();
    $endpoint = OutboundWebhookEndpoint::factory()->for($world['account'])->create();
    $endpoint->sites()->attach($world['site']);
    $failed = OutboundWebhookDelivery::factory()->for($endpoint, 'endpoint')->create([
        'site_id' => $world['site']->id,
        'failed_at' => now(),
    ]);

    $this->actingAs($world['admin'])
        ->post(route('dashboard.account.outbound-webhooks.retry', $failed))
        ->assertRedirect(route('dashboard.account.api-tokens.index'));

    expect($failed->fresh()->failed_at)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'outbound_webhook.delivery_retried')->count())->toBe(1);
    Queue::assertPushed(DeliverOutboundWebhook::class, fn ($job): bool => $job->uniqueId() === (string) $failed->id);

    $otherEndpoint = OutboundWebhookEndpoint::factory()->create();
    $other = OutboundWebhookDelivery::factory()->for($otherEndpoint, 'endpoint')->create(['failed_at' => now()]);

    $this->post(route('dashboard.account.outbound-webhooks.retry', $other))->assertNotFound();
});

test('webhook administration is not visible or writable to non admins', function (): void {
    $world = outboundWebhookWorld();
    $agent = User::factory()->for($world['account'])->create(['account_role' => AccountRole::Agent]);
    $endpoint = OutboundWebhookEndpoint::factory()->for($world['account'])->create();

    $this->actingAs($agent)->get(route('dashboard.account.api-tokens.index'))->assertForbidden();
    $this->post(route('dashboard.account.outbound-webhooks.store'), ['webhook' => []])->assertForbidden();
    $this->delete(route('dashboard.account.outbound-webhooks.destroy', $endpoint))->assertForbidden();
});

test('the recovery command and minutely schedule cover a missed queue handoff', function (): void {
    Queue::fake();
    $world = outboundWebhookWorld();
    $endpoint = OutboundWebhookEndpoint::factory()->for($world['account'])->create();
    $pending = OutboundWebhookDelivery::factory()->for($endpoint, 'endpoint')->create();
    OutboundWebhookDelivery::factory()->for($endpoint, 'endpoint')->create(['sequence' => 2, 'failed_at' => now()]);

    $this->artisan('wayfindr:queue-outbound-webhooks')->assertSuccessful();

    Queue::assertPushed(DeliverOutboundWebhook::class, 1);
    Queue::assertPushed(DeliverOutboundWebhook::class, fn ($job): bool => $job->uniqueId() === (string) $pending->id);

    expect(collect(Schedule::events())->contains(
        fn ($event): bool => str_contains($event->command ?? '', 'wayfindr:queue-outbound-webhooks')
            && $event->expression === '* * * * *'
    ))->toBeTrue();
});

test('the delivery log shows payload, response, retry state, and hides inaccessible site rows', function (): void {
    $world = outboundWebhookWorld();
    $world['site']->supportAgents()->attach($world['admin']);
    $world['otherSite']->supportAgents()->attach(User::factory()->for($world['account'])->create());
    $endpoint = OutboundWebhookEndpoint::factory()->for($world['account'])->create(['name' => 'Visible endpoint']);
    $endpoint->sites()->attach([$world['site']->id, $world['otherSite']->id]);
    OutboundWebhookDelivery::factory()->for($endpoint, 'endpoint')->create([
        'site_id' => $world['site']->id,
        'attempts' => 2,
        'response_status' => 503,
        'response_body' => 'try later',
    ]);
    OutboundWebhookDelivery::factory()->for($endpoint, 'endpoint')->create([
        'site_id' => $world['otherSite']->id,
        'sequence' => 2,
        'payload' => ['hidden' => 'DO NOT SHOW'],
    ]);

    $this->actingAs($world['admin'])
        ->get(route('dashboard.account.api-tokens.index'))
        ->assertOk()
        ->assertSee('Visible endpoint')
        ->assertSee('try later')
        ->assertSee('Retrying with backoff')
        ->assertDontSee('DO NOT SHOW');
});
