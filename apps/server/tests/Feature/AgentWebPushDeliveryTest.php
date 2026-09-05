<?php

use App\Events\AgentAlertStored;
use App\Exceptions\RetryableAgentWebPushException;
use App\Listeners\SendAgentAlertWebPush;
use App\Models\Account;
use App\Models\AgentAlertDelivery;
use App\Models\AgentPushSubscription;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\AgentAlertWebPush;
use App\Notifications\TicketAssigned;
use App\Support\AgentWebPushChannel;
use App\Support\AgentWebPushConfig;
use App\Support\AgentWebPushFactory;
use App\Support\Settings\OperatorSettings;
use App\Support\Webhooks\OutboundWebhookDestination;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Minishlink\WebPush\VAPID;
use NotificationChannels\WebPush\WebPushChannel;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(
        OutboundWebhookDestination::class,
        new OutboundWebhookDestination(fn (): array => ['8.8.8.8']),
    );
});

function readyAgentWebPushConfig(): array
{
    $keys = VAPID::createVapidKeys();
    config()->set('webpush.vapid', [
        'subject' => 'mailto:alerts@example.test',
        'public_key' => $keys['publicKey'],
        'private_key' => $keys['privateKey'],
        'pem_file' => null,
    ]);

    return $keys;
}

function subscribeAgentForPush(User $agent, string $suffix = 'one'): void
{
    $subscriptionKeys = VAPID::createVapidKeys();

    $agent->pushSubscriptions()->create([
        'endpoint' => "https://push.example.test/subscriptions/{$suffix}",
        'public_key' => $subscriptionKeys['publicKey'],
        'auth_token' => rtrim(strtr(base64_encode(str_repeat('a', 16)), '+/', '-_'), '='),
        'content_encoding' => 'aes128gcm',
    ]);
}

/** @return array{0: User, 1: Site, 2: DatabaseNotification} */
function pushAlertFixture(): array
{
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create([
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'push' => true,
        ],
    ]);
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create([
        'subject' => 'Sensitive customer ticket subject',
    ]);
    $alert = $agent->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => TicketAssigned::class,
        'data' => [
            'kind' => 'ticket_assigned',
            'ticket_id' => $ticket->id,
            'subject' => $ticket->subject,
            'site_name' => $site->name,
        ],
        'agent_alert_version' => (string) Str::uuid(),
        'read_at' => null,
    ]);

    return [$agent, $site, $alert];
}

test('the locked-screen payload is localized, generic, and stable for retries', function (): void {
    $agent = User::factory()->for(Account::factory())->create();
    $notification = new AgentAlertWebPush(
        alertId: 'alert-123',
        version: 'version-456',
        dashboardLocale: 'de',
    );
    $message = $notification->toWebPush($agent, $notification);
    $payload = $message->toArray();

    expect($notification->via($agent))->toBe([WebPushChannel::class])
        ->and($payload)->toMatchArray([
            'title' => 'Neue Wayfindr-Benachrichtigung',
            'body' => 'Öffnen Sie Wayfindr, um sie zu prüfen.',
            'lang' => 'de',
            'tag' => 'wayfindr-agent-alert-alert-123',
            'data' => [
                'url' => '/dashboard/alerts',
                'alert_id' => 'alert-123',
                'version' => 'version-456',
            ],
        ])
        ->and($message->getOptions())->toBe([
            'TTL' => 300,
            'urgency' => 'normal',
        ])
        ->and(json_encode($payload))->not->toContain('customer')
        ->and(json_encode($payload))->not->toContain('ticket subject');
});

test('the configured Web Push channel encrypts and posts a notification to the browser endpoint', function (): void {
    readyAgentWebPushConfig();
    $subscriptionKeys = VAPID::createVapidKeys();
    $agent = User::factory()->for(Account::factory())->create();
    $agent->pushSubscriptions()->create([
        'endpoint' => 'https://push.example.test/subscriptions/encrypted',
        'public_key' => $subscriptionKeys['publicKey'],
        'auth_token' => rtrim(strtr(base64_encode(str_repeat('a', 16)), '+/', '-_'), '='),
        'content_encoding' => 'aes128gcm',
    ]);
    Http::fake(['push.example.test/*' => Http::response('', 201)]);

    Notification::sendNow(
        $agent,
        new AgentAlertWebPush('alert-encrypted', 'version-encrypted', 'en'),
        [WebPushChannel::class],
    );

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://push.example.test/subscriptions/encrypted'
        && $request->hasHeader('Authorization')
        && $request->hasHeader('Content-Encoding', 'aes128gcm')
        && $request->hasHeader('TTL', '300')
        && $request->hasHeader('Urgency', 'normal')
        && ! str_contains($request->body(), 'New Wayfindr alert'));
});

test('the configured Web Push channel propagates transient reports to the queued listener', function (int $status): void {
    readyAgentWebPushConfig();
    [$agent, , $alert] = pushAlertFixture();
    subscribeAgentForPush($agent);
    Http::fake(['push.example.test/*' => Http::response('', $status)]);

    expect(app(WebPushChannel::class))->toBeInstanceOf(AgentWebPushChannel::class);

    expect(fn () => app(SendAgentAlertWebPush::class)->handle(
        new AgentAlertStored($agent, $alert),
        app(AgentWebPushConfig::class),
    ))->toThrow(RuntimeException::class, 'retryable failure');

    Http::assertSentCount(1);
})->with([429, 503]);

test('an expired Web Push report removes the endpoint without retrying the queued listener', function (): void {
    readyAgentWebPushConfig();
    [$agent, , $alert] = pushAlertFixture();
    subscribeAgentForPush($agent);
    Http::fake(['push.example.test/*' => Http::response('', 410)]);

    app(SendAgentAlertWebPush::class)->handle(
        new AgentAlertStored($agent, $alert),
        app(AgentWebPushConfig::class),
    );

    Http::assertSentCount(1);
    expect($agent->pushSubscriptions()->count())->toBe(0);
});

test('expired subscription cleanup commits before a transient sibling retries', function (): void {
    readyAgentWebPushConfig();
    [$agent, , $alert] = pushAlertFixture();
    subscribeAgentForPush($agent, 'expired');
    subscribeAgentForPush($agent, 'transient');
    Http::fake([
        'push.example.test/subscriptions/expired' => Http::response('', 410),
        'push.example.test/subscriptions/transient' => Http::response('', 503),
    ]);

    expect(fn () => app(SendAgentAlertWebPush::class)->handle(
        new AgentAlertStored($agent, $alert),
        app(AgentWebPushConfig::class),
    ))->toThrow(RetryableAgentWebPushException::class, 'retryable failure');

    expect($agent->pushSubscriptions()->pluck('endpoint')->all())->toBe([
        'https://push.example.test/subscriptions/transient',
    ]);
});

test('the Web Push transport pins public DNS and refuses private destinations', function (): void {
    readyAgentWebPushConfig();
    $subscriptionKeys = VAPID::createVapidKeys();
    $agent = User::factory()->for(Account::factory())->create();
    $agent->pushSubscriptions()->create([
        'endpoint' => 'https://push.example.test:8443/subscriptions/pinned',
        'public_key' => $subscriptionKeys['publicKey'],
        'auth_token' => rtrim(strtr(base64_encode(str_repeat('a', 16)), '+/', '-_'), '='),
        'content_encoding' => 'aes128gcm',
    ]);
    $options = null;
    $handler = function ($request, array $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Create::promiseFor(new Response(201));
    };
    $destination = new OutboundWebhookDestination(fn (): array => ['8.8.8.8']);
    app()->instance(AgentWebPushFactory::class, new AgentWebPushFactory($destination, $handler));

    Notification::sendNow(
        $agent,
        new AgentAlertWebPush('alert-pinned', 'version-pinned', 'en'),
        [WebPushChannel::class],
    );

    expect($options)->toBeArray()
        ->and($options['allow_redirects'])->toBeFalse()
        ->and($options['proxy'])->toBe('')
        ->and($options['curl'][CURLOPT_NOPROXY])->toBe('*')
        ->and($options['curl'][CURLOPT_PROTOCOLS])->toBe(CURLPROTO_HTTPS)
        ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['push.example.test:8443:8.8.8.8']);

    $reachedNetwork = false;
    $blockedHandler = function () use (&$reachedNetwork) {
        $reachedNetwork = true;

        return Create::promiseFor(new Response(201));
    };
    $blockedDestination = new OutboundWebhookDestination(fn (): array => ['10.0.0.8']);
    app()->instance(AgentWebPushFactory::class, new AgentWebPushFactory($blockedDestination, $blockedHandler));
    Notification::getFacadeRoot()->forgetDrivers();

    Notification::sendNow(
        $agent,
        new AgentAlertWebPush('alert-blocked', 'version-blocked', 'en'),
        [WebPushChannel::class],
    );

    expect($reachedNetwork)->toBeFalse();
});

test('a temporary Web Push DNS resolution failure reaches the queue retry policy', function (): void {
    readyAgentWebPushConfig();
    [$agent, , $alert] = pushAlertFixture();
    subscribeAgentForPush($agent, 'dns-outage');
    $reachedNetwork = false;
    $handler = function () use (&$reachedNetwork) {
        $reachedNetwork = true;

        return Create::promiseFor(new Response(201));
    };
    $unresolvedDestination = new OutboundWebhookDestination(fn (): array => []);
    app()->instance(AgentWebPushFactory::class, new AgentWebPushFactory($unresolvedDestination, $handler));
    Notification::getFacadeRoot()->forgetDrivers();

    expect(fn () => app(SendAgentAlertWebPush::class)->handle(
        new AgentAlertStored($agent, $alert),
        app(AgentWebPushConfig::class),
    ))->toThrow(RetryableAgentWebPushException::class, 'retryable failure')
        ->and($reachedNetwork)->toBeFalse();
});

test('a long-running worker rebuilds the Web Push channel after VAPID rotation', function (): void {
    $firstKeys = VAPID::createVapidKeys();
    $secondKeys = VAPID::createVapidKeys();
    $settings = app(OperatorSettings::class);

    foreach ([
        'webpush.subject' => 'mailto:alerts@example.test',
        'webpush.public_key' => $firstKeys['publicKey'],
        'webpush.private_key' => $firstKeys['privateKey'],
    ] as $key => $value) {
        $settings->set($key, $value);
    }

    $settings->applyOverrides();
    $subscriptionKeys = VAPID::createVapidKeys();
    $agent = User::factory()->for(Account::factory())->create();
    $agent->pushSubscriptions()->create([
        'endpoint' => 'https://push.example.test/subscriptions/rotated',
        'public_key' => $subscriptionKeys['publicKey'],
        'auth_token' => rtrim(strtr(base64_encode(str_repeat('a', 16)), '+/', '-_'), '='),
        'content_encoding' => 'aes128gcm',
    ]);
    Http::fake(['push.example.test/*' => Http::response('', 201)]);

    Notification::sendNow(
        $agent,
        new AgentAlertWebPush('alert-first', 'version-first', 'en'),
        [WebPushChannel::class],
    );

    foreach ([
        'webpush.public_key' => $secondKeys['publicKey'],
        'webpush.private_key' => $secondKeys['privateKey'],
    ] as $key => $value) {
        $settings->set($key, $value);
    }

    $settings->applyOverrides();
    AgentPushSubscription::purgeStaleFor($agent);
    subscribeAgentForPush($agent, 'rotated-new');
    Notification::sendNow(
        $agent,
        new AgentAlertWebPush('alert-second', 'version-second', 'en'),
        [WebPushChannel::class],
    );

    $authorization = Http::recorded()
        ->map(fn (array $exchange): string => $exchange[0]->header('Authorization')[0] ?? '')
        ->values();

    expect($authorization)->toHaveCount(2)
        ->and($authorization[0])->toContain('k='.$firstKeys['publicKey'])
        ->and($authorization[1])->toContain('k='.$secondKeys['publicKey'])
        ->and($authorization[1])->not->toContain('k='.$firstKeys['publicKey']);
});

test('delivery refreshes VAPID settings under the rotation lock before purging subscriptions', function (): void {
    $oldKeys = VAPID::createVapidKeys();
    $newKeys = VAPID::createVapidKeys();
    $settings = app(OperatorSettings::class);

    foreach ([
        'webpush.subject' => 'mailto:alerts@example.test',
        'webpush.public_key' => $oldKeys['publicKey'],
        'webpush.private_key' => $oldKeys['privateKey'],
    ] as $key => $value) {
        $settings->set($key, $value);
    }

    // This is the worker's JobProcessing snapshot. The database changes below
    // model a rotation committed by the operator in another process afterward.
    $settings->applyOverrides();
    [$agent, , $alert] = pushAlertFixture();

    foreach ([
        'webpush.public_key' => $newKeys['publicKey'],
        'webpush.private_key' => $newKeys['privateKey'],
    ] as $key => $value) {
        $settings->set($key, $value);
    }

    subscribeAgentForPush($agent, 'freshly-rotated');
    $subscription = AgentPushSubscription::withoutGlobalScopes()->sole();
    AgentPushSubscription::withoutGlobalScopes()
        ->whereKey($subscription->id)
        ->update(['vapid_public_key_hash' => hash('sha256', $newKeys['publicKey'])]);
    Http::fake(['push.example.test/*' => Http::response('', 201)]);

    expect(config('webpush.vapid.public_key'))->toBe($oldKeys['publicKey']);

    app(SendAgentAlertWebPush::class)->handle(
        new AgentAlertStored($agent, $alert),
        app(AgentWebPushConfig::class),
        settings: $settings,
    );

    $authorization = Http::recorded()->sole()[0]->header('Authorization')[0] ?? '';

    expect(config('webpush.vapid.public_key'))->toBe($newKeys['publicKey'])
        ->and(AgentPushSubscription::withoutGlobalScopes()->sole()->endpoint)
        ->toBe('https://push.example.test/subscriptions/freshly-rotated')
        ->and($authorization)->toContain('k='.$newKeys['publicKey'])
        ->and($authorization)->not->toContain('k='.$oldKeys['publicKey']);

    $source = file_get_contents(app_path('Listeners/SendAgentAlertWebPush.php'));
    $handle = Str::before(
        Str::after($source, 'public function handle('),
        'private function refreshWebPushSettingsUnderLock',
    );

    expect($source)
        ->toContain("->where('key', 'webpush.public_key')")
        ->toContain('->sharedLock()')
        ->toContain('$settings->refreshFromDatabase()');

    expect(strpos($handle, 'refreshWebPushSettingsUnderLock($settings)'))
        ->toBeLessThan(strpos($handle, 'AgentPushSubscription::purgeStaleFor($recipient)'));
});

test('an environment VAPID rotation retains other live-process generations without delivering them', function (): void {
    readyAgentWebPushConfig();
    [$agent, , $alert] = pushAlertFixture();
    subscribeAgentForPush($agent, 'old-environment');
    $oldHash = AgentPushSubscription::currentVapidPublicKeyHash();
    $replacement = VAPID::createVapidKeys();
    config()->set('webpush.vapid', [
        'subject' => 'mailto:alerts@example.test',
        'public_key' => $replacement['publicKey'],
        'private_key' => $replacement['privateKey'],
        'pem_file' => null,
    ]);
    Http::fake();
    $listener = app(SendAgentAlertWebPush::class);
    $event = new AgentAlertStored($agent, $alert);

    $listener->handle($event, app(AgentWebPushConfig::class));

    Http::assertNothingSent();
    expect(AgentPushSubscription::withoutGlobalScopes()->count())->toBe(1)
        ->and(AgentPushSubscription::currentVapidPublicKeyHash())->not->toBe($oldHash)
        ->and(AgentPushSubscription::canPurgeOtherVapidGenerations())->toBeFalse()
        ->and($listener->shouldQueue($event))->toBeFalse();
});

test('an exact realtime browser receipt suppresses Web Push before delivery', function (): void {
    readyAgentWebPushConfig();
    [$agent, , $alert] = pushAlertFixture();
    subscribeAgentForPush($agent, 'realtime-received');
    $alert->forceFill([
        'agent_alert_realtime_received_version' => $alert->getAttribute('agent_alert_version'),
    ])->save();
    Http::fake();

    $listener = app(SendAgentAlertWebPush::class);
    $listener->handle(
        new AgentAlertStored($agent, $alert),
        app(AgentWebPushConfig::class),
    );

    Http::assertNothingSent();
    expect($agent->pushSubscriptions()->count())->toBe(1)
        ->and($listener->withDelay(new AgentAlertStored($agent, $alert)))->toBe(1);
});

test('a receipt for an older alert version cannot suppress Web Push', function (): void {
    readyAgentWebPushConfig();
    [$agent, , $alert] = pushAlertFixture();
    subscribeAgentForPush($agent, 'stale-receipt');
    $alert->forceFill(['agent_alert_realtime_received_version' => (string) Str::uuid()])->save();
    Http::fake(['push.example.test/*' => Http::response('', 201)]);

    app(SendAgentAlertWebPush::class)->handle(
        new AgentAlertStored($agent, $alert),
        app(AgentWebPushConfig::class),
    );

    Http::assertSentCount(1);
    expect(AgentAlertDelivery::query()->sole())
        ->channel->toBe('push')
        ->alert_version->toBe($alert->getAttribute('agent_alert_version'));
});

test('the queued Web Push listener is selected only for ready opted-in recipients', function (): void {
    [$agent, , $alert] = pushAlertFixture();
    $listener = app(SendAgentAlertWebPush::class);
    $event = new AgentAlertStored($agent, $alert);

    config()->set('webpush.vapid', [
        'subject' => null,
        'public_key' => null,
        'private_key' => null,
        'pem_file' => null,
    ]);
    expect($listener->shouldQueue($event))->toBeFalse();

    $keys = readyAgentWebPushConfig();
    $settings = app(OperatorSettings::class);

    foreach ([
        'webpush.subject' => 'mailto:alerts@example.test',
        'webpush.public_key' => $keys['publicKey'],
        'webpush.private_key' => $keys['privateKey'],
    ] as $key => $value) {
        $settings->set($key, $value);
    }

    $settings->applyOverrides();
    expect($listener->shouldQueue($event))->toBeFalse();

    subscribeAgentForPush($agent);
    expect($listener->shouldQueue($event))->toBeTrue();

    $agent->forceFill([
        'alert_preferences' => ['mode' => User::ALERT_MODE_QUIET, 'push' => true],
    ])->save();

    expect($listener->shouldQueue(new AgentAlertStored($agent->fresh(), $alert)))->toBeFalse();
});

test('Web Push pauses inside agent quiet hours and resumes at the end boundary', function (): void {
    readyAgentWebPushConfig();
    [$agent, , $alert] = pushAlertFixture();
    subscribeAgentForPush($agent, 'quiet-hours');
    $agent->forceFill([
        'timezone' => 'America/New_York',
        'alert_preferences' => [
            ...$agent->alert_preferences,
            'push' => true,
            'quiet_hours' => [
                'enabled' => true,
                'start' => '22:00',
                'end' => '07:00',
            ],
        ],
    ])->save();
    $listener = app(SendAgentAlertWebPush::class);

    try {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06 02:30:00', 'UTC'));
        $quietEvent = new AgentAlertStored($agent->fresh(), $alert);
        Http::fake(['push.example.test/*' => Http::response('', 201)]);

        expect($listener->shouldQueue($quietEvent))->toBeFalse();
        $listener->handle($quietEvent, app(AgentWebPushConfig::class));
        Http::assertNothingSent();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06 11:00:00', 'UTC'));
        $resumedEvent = new AgentAlertStored($agent->fresh(), $alert);

        expect($listener->shouldQueue($resumedEvent))->toBeTrue();
        $listener->handle($resumedEvent, app(AgentWebPushConfig::class));
        Http::assertSentCount(1);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('temporarily unavailable Web Push settings retain a retryable delivery attempt', function (): void {
    readyAgentWebPushConfig();
    [$agent, , $alert] = pushAlertFixture();
    subscribeAgentForPush($agent, 'settings-recovery');
    readyAgentWebPushConfig();
    $settings = Mockery::mock(OperatorSettings::class);
    $settings->shouldReceive('valuesAreAuthoritative')->twice()->andReturnFalse();
    $webPush = new AgentWebPushConfig($settings);
    app()->instance(AgentWebPushConfig::class, $webPush);
    $listener = app(SendAgentAlertWebPush::class);
    $event = new AgentAlertStored($agent, $alert);
    Notification::fake();

    expect($agent->pushSubscriptions()->exists())->toBeFalse()
        ->and($listener->shouldQueue($event))->toBeTrue()
        ->and(fn () => $listener->handle($event, $webPush))
        ->toThrow(RetryableAgentWebPushException::class, 'temporarily unavailable');

    Notification::assertNothingSent();
    expect(AgentPushSubscription::withoutGlobalScope(AgentPushSubscription::CURRENT_VAPID_SCOPE)
        ->where('subscribable_type', $agent->getMorphClass())
        ->where('subscribable_id', $agent->getKey())
        ->count())->toBe(1);
});

test('an unavailable subscription database retains a retry unless its schema is confirmed missing', function (): void {
    readyAgentWebPushConfig();
    [$agent, , $alert] = pushAlertFixture();
    subscribeAgentForPush($agent, 'database-recovery');
    $listener = app(SendAgentAlertWebPush::class);
    $event = new AgentAlertStored($agent, $alert);

    config()->set('database.connections.webpush-unavailable', [
        'driver' => 'sqlite',
        'database' => storage_path('framework/testing/webpush-unavailable/push.sqlite'),
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('webpush.database_connection', 'webpush-unavailable');

    expect($listener->shouldQueue($event))->toBeTrue();

    config()->set('database.connections.webpush-empty', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('webpush.database_connection', 'webpush-empty');

    expect($listener->shouldQueue($event))->toBeFalse();
});

test('the listener sends only the exact current unread alert version to an authorized agent', function (): void {
    readyAgentWebPushConfig();
    [$agent, $site, $alert] = pushAlertFixture();
    subscribeAgentForPush($agent);
    $listener = app(SendAgentAlertWebPush::class);
    $event = new AgentAlertStored($agent, $alert);

    Http::fake(['push.example.test/*' => Http::response('', 201)]);
    $listener->handle($event, app(AgentWebPushConfig::class));

    Http::assertSentCount(1);
    expect(AgentAlertDelivery::query()->sole())
        ->notification_id->toBe((string) $alert->id)
        ->alert_version->toBe($event->version)
        ->channel->toBe('push');

    $alert->markAsRead();
    $listener->handle($event, app(AgentWebPushConfig::class));
    Http::assertSentCount(1);

    $alert->forceFill([
        'read_at' => null,
        'agent_alert_version' => (string) Str::uuid(),
    ])->save();
    $listener->handle($event, app(AgentWebPushConfig::class));
    Http::assertSentCount(1);

    $currentEvent = new AgentAlertStored($agent->fresh(), $alert->fresh());
    $remainingAgent = User::factory()->for($agent->account)->create();
    $site->supportAgents()->sync([$remainingAgent->id]);
    $listener->handle($currentEvent, app(AgentWebPushConfig::class));
    Http::assertSentCount(1);
});

test('the listener holds eligibility locks through the Web Push delivery call', function (): void {
    readyAgentWebPushConfig();
    [$agent, , $alert] = pushAlertFixture();
    subscribeAgentForPush($agent);
    $event = new AgentAlertStored($agent, $alert);

    Http::fake(function (Request $request) use ($alert) {
        expect(DB::transactionLevel())->toBeGreaterThan(0)
            ->and($request->url())->toContain('push.example.test/subscriptions/one')
            ->and($alert->fresh()->read_at)->toBeNull();

        return Http::response('', 201);
    });

    app(SendAgentAlertWebPush::class)->handle($event, app(AgentWebPushConfig::class));

    $source = file_get_contents(app_path('Listeners/SendAgentAlertWebPush.php'));

    expect($source)
        ->toContain('Account::query()')
        ->toContain('DatabaseNotification::query()')
        ->and(substr_count($source, '->lockForUpdate()'))->toBe(3)
        ->and(strpos($source, '$deliveryChannel->send('))->toBeGreaterThan(strpos($source, 'DB::transaction('));
});

test('a queued event preserves the version it claimed before model rehydration', function (): void {
    [$agent, , $alert] = pushAlertFixture();
    $event = new AgentAlertStored($agent, $alert);
    $claimedVersion = $event->version;

    $alert->forceFill(['agent_alert_version' => (string) Str::uuid()])->save();
    $serialized = unserialize(serialize($event));

    expect($serialized)->toBeInstanceOf(AgentAlertStored::class)
        ->and($serialized->version)->toBe($claimedVersion)
        ->and($serialized->alert->fresh()->agent_alert_version)->not->toBe($claimedVersion);
});
