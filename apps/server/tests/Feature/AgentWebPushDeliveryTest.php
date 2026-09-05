<?php

use App\Events\AgentAlertStored;
use App\Listeners\SendAgentAlertWebPush;
use App\Models\Account;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\AgentAlertWebPush;
use App\Notifications\TicketAssigned;
use App\Support\AgentWebPushConfig;
use App\Support\AgentWebPushFactory;
use App\Support\Settings\OperatorSettings;
use App\Support\Webhooks\OutboundWebhookDestination;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\DatabaseNotification;
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
    $agent->pushSubscriptions()->create([
        'endpoint' => "https://push.example.test/subscriptions/{$suffix}",
        'public_key' => rtrim(strtr(base64_encode("\x04".str_repeat('p', 64)), '+/', '-_'), '='),
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

    readyAgentWebPushConfig();
    expect($listener->shouldQueue($event))->toBeFalse();

    subscribeAgentForPush($agent);
    expect($listener->shouldQueue($event))->toBeTrue();

    $agent->forceFill([
        'alert_preferences' => ['mode' => User::ALERT_MODE_QUIET, 'push' => true],
    ])->save();

    expect($listener->shouldQueue(new AgentAlertStored($agent->fresh(), $alert)))->toBeFalse();
});

test('the listener sends only the exact current unread alert version to an authorized agent', function (): void {
    readyAgentWebPushConfig();
    [$agent, $site, $alert] = pushAlertFixture();
    subscribeAgentForPush($agent);
    $listener = app(SendAgentAlertWebPush::class);
    $event = new AgentAlertStored($agent, $alert);

    Notification::fake();
    $listener->handle($event, app(AgentWebPushConfig::class));

    Notification::assertSentTo(
        $agent,
        AgentAlertWebPush::class,
        fn (AgentAlertWebPush $notification, array $channels): bool => $channels === [WebPushChannel::class]
            && $notification->alertId === (string) $alert->id
            && $notification->version === $event->version,
    );

    Notification::fake();
    $alert->markAsRead();
    $listener->handle($event, app(AgentWebPushConfig::class));
    Notification::assertNothingSent();

    $alert->forceFill([
        'read_at' => null,
        'agent_alert_version' => (string) Str::uuid(),
    ])->save();
    Notification::fake();
    $listener->handle($event, app(AgentWebPushConfig::class));
    Notification::assertNothingSent();

    $currentEvent = new AgentAlertStored($agent->fresh(), $alert->fresh());
    $remainingAgent = User::factory()->for($agent->account)->create();
    $site->supportAgents()->sync([$remainingAgent->id]);
    Notification::fake();
    $listener->handle($currentEvent, app(AgentWebPushConfig::class));
    Notification::assertNothingSent();
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
