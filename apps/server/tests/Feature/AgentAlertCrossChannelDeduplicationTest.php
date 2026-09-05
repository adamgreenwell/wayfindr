<?php

use App\Events\AgentAlertStored;
use App\Exceptions\RetryableAgentWebPushException;
use App\Jobs\SendSlaDeadlineAlertDelivery;
use App\Listeners\SendAgentAlertWebPush;
use App\Models\Account;
use App\Models\AgentAlertDelivery;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\SlaAlertDelivery;
use App\Models\SlaClock;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\ConversationNeedsReply;
use App\Notifications\SlaDeadlineAlert;
use App\Notifications\TicketAssigned;
use App\Support\AgentAlertDeliveryCoordinator;
use App\Support\AgentWebPushConfig;
use App\Support\AlertDigestCandidateCollector;
use App\Support\Settings\OperatorSettings;
use App\Support\UnattendedConversationAlertCollector;
use App\Support\Webhooks\OutboundWebhookDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Minishlink\WebPush\VAPID;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(
        OutboundWebhookDestination::class,
        new OutboundWebhookDestination(fn (): array => ['8.8.8.8']),
    );
});

function configureCrossChannelWebPush(): void
{
    $keys = VAPID::createVapidKeys();
    $settings = app(OperatorSettings::class);

    foreach ([
        'webpush.subject' => 'mailto:alerts@example.test',
        'webpush.public_key' => $keys['publicKey'],
        'webpush.private_key' => $keys['privateKey'],
    ] as $key => $value) {
        $settings->set($key, $value);
    }

    $settings->applyOverrides();
}

function subscribeCrossChannelAgent(User $agent, string $suffix = 'primary'): void
{
    $keys = VAPID::createVapidKeys();

    $agent->pushSubscriptions()->create([
        'endpoint' => "https://push.example.test/subscriptions/{$suffix}",
        'public_key' => $keys['publicKey'],
        'auth_token' => rtrim(strtr(base64_encode(str_repeat('b', 16)), '+/', '-_'), '='),
        'content_encoding' => 'aes128gcm',
    ]);
}

function useCrossChannelArrayMailer(): void
{
    config()->set('mail.default', 'array');
    Mail::purge();
}

function storeCrossChannelTicketAlert(User $agent, TicketAssigned $notification): DatabaseNotification
{
    $notification->id = (string) Str::uuid();
    Notification::sendNow($agent, $notification, ['database']);

    return $agent->unreadNotifications()->whereKey($notification->id)->firstOrFail();
}

/** @return array{0: User, 1: User, 2: Ticket} */
function crossChannelTicketWorld(bool $push = true): array
{
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create([
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'push' => $push,
            'cadence' => User::ALERT_CADENCE_IMMEDIATE,
        ],
    ]);
    $assigner = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->for($agent, 'assignee')->create();

    return [$agent, $assigner, $ticket];
}

test('a successful Web Push suppresses the queued immediate email for the same alert version', function (): void {
    configureCrossChannelWebPush();
    [$agent, $assigner, $ticket] = crossChannelTicketWorld();
    subscribeCrossChannelAgent($agent);
    Http::fake(['push.example.test/*' => Http::response('', 201)]);
    useCrossChannelArrayMailer();
    $notification = new TicketAssigned($ticket, $assigner);
    $alert = storeCrossChannelTicketAlert($agent, $notification);

    app(SendAgentAlertWebPush::class)->handle(
        new AgentAlertStored($agent, $alert),
        app(AgentWebPushConfig::class),
    );
    Notification::sendNow($agent, $notification, ['mail']);

    Http::assertSentCount(1);
    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
    $delivery = AgentAlertDelivery::query()->sole();

    expect($delivery->channel)->toBe(AgentAlertDeliveryCoordinator::CHANNEL_PUSH)
        ->and($delivery->accepted_at)->not->toBeNull()
        ->and($delivery->notification_id)->toBe((string) $agent->unreadNotifications()->sole()->id);
});

test('a failed Web Push leaves immediate email available as the fallback channel', function (): void {
    configureCrossChannelWebPush();
    [$agent, $assigner, $ticket] = crossChannelTicketWorld();
    subscribeCrossChannelAgent($agent);
    Http::fake(['push.example.test/*' => Http::response('', 503)]);
    useCrossChannelArrayMailer();
    $notification = new TicketAssigned($ticket, $assigner);
    $alert = storeCrossChannelTicketAlert($agent, $notification);

    expect(fn () => app(SendAgentAlertWebPush::class)->handle(
        new AgentAlertStored($agent, $alert),
        app(AgentWebPushConfig::class),
    ))->toThrow(RetryableAgentWebPushException::class);
    Notification::sendNow($agent, $notification, ['mail']);

    Http::assertSentCount(1);
    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1);
    $delivery = AgentAlertDelivery::query()->sole();

    expect($delivery->channel)->toBe(AgentAlertDeliveryCoordinator::CHANNEL_IMMEDIATE_MAIL)
        ->and($delivery->accepted_at)->not->toBeNull();
});

test('an immediate mail retry takes over a claim that never reached transport', function (): void {
    [$agent, $assigner, $ticket] = crossChannelTicketWorld(push: false);
    $notification = new TicketAssigned($ticket, $assigner);
    storeCrossChannelTicketAlert($agent, $notification);
    $coordinator = app(AgentAlertDeliveryCoordinator::class);
    $first = $coordinator->claimNotificationMail($agent, $notification);
    $second = $coordinator->claimNotificationMail($agent, $notification);

    expect($first['status'])->toBe('claimed')
        ->and($second['status'])->toBe('claimed')
        ->and($second['claim']['claim_token'])->not->toBe($first['claim']['claim_token'])
        ->and(AgentAlertDelivery::query()->count())->toBe(1)
        ->and(fn () => $coordinator->markMailTransportStarted($first['claim']))
        ->toThrow(LogicException::class);

    $coordinator->markMailTransportStarted($second['claim']);
    $coordinator->acceptMailClaim($second['claim']);

    expect(AgentAlertDelivery::query()->sole()->accepted_at)->not->toBeNull();
});

test('one accepted browser is enough to suppress email when a sibling push endpoint is transiently unavailable', function (): void {
    configureCrossChannelWebPush();
    [$agent, $assigner, $ticket] = crossChannelTicketWorld();
    subscribeCrossChannelAgent($agent, 'accepted');
    subscribeCrossChannelAgent($agent, 'transient');
    Http::fake([
        'push.example.test/subscriptions/accepted' => Http::response('', 201),
        'push.example.test/subscriptions/transient' => Http::response('', 503),
    ]);
    useCrossChannelArrayMailer();
    $notification = new TicketAssigned($ticket, $assigner);
    $alert = storeCrossChannelTicketAlert($agent, $notification);

    app(SendAgentAlertWebPush::class)->handle(
        new AgentAlertStored($agent, $alert),
        app(AgentWebPushConfig::class),
    );
    Notification::sendNow($agent, $notification, ['mail']);

    Http::assertSentCount(2);
    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
    expect(AgentAlertDelivery::query()->sole()->channel)
        ->toBe(AgentAlertDeliveryCoordinator::CHANNEL_PUSH);
});

test('a live dashboard receipt suppresses email before its transport boundary', function (): void {
    [$agent, $assigner, $ticket] = crossChannelTicketWorld(push: false);
    $version = (string) Str::uuid();
    $notification = new TicketAssigned($ticket, $assigner);
    $notification->id = (string) Str::uuid();
    $agent->notifications()->create([
        'id' => $notification->id,
        'type' => TicketAssigned::class,
        'data' => $notification->toArray($agent),
        'agent_alert_version' => $version,
        'agent_alert_realtime_received_version' => $version,
        'read_at' => null,
    ]);
    useCrossChannelArrayMailer();

    Notification::sendNow($agent, $notification, ['mail']);

    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0)
        ->and(AgentAlertDelivery::query()->count())->toBe(0);
});

test('a transport receipt keeps the earlier mail ambiguity boundary', function (): void {
    [$agent, $assigner, $ticket] = crossChannelTicketWorld(push: false);
    $notification = new TicketAssigned($ticket, $assigner);
    storeCrossChannelTicketAlert($agent, $notification);
    $coordinator = app(AgentAlertDeliveryCoordinator::class);
    $decision = $coordinator->claimNotificationMail($agent, $notification);

    expect($decision['status'])->toBe('claimed');
    $coordinator->markMailTransportStarted($decision['claim']);
    $startedAt = AgentAlertDelivery::query()->sole()->started_at;
    $this->travel(1)->second();
    $coordinator->acceptMailClaim($decision['claim']);

    $delivery = AgentAlertDelivery::query()->sole();
    expect($delivery->started_at->equalTo($startedAt))->toBeTrue()
        ->and($delivery->accepted_at->greaterThan($delivery->started_at))->toBeTrue();
});

test('a realtime receipt cancels common and SLA mail claims atomically before transport', function (): void {
    [$agent, $assigner, $ticket] = crossChannelTicketWorld(push: false);
    $notification = new TicketAssigned($ticket, $assigner);
    $alert = storeCrossChannelTicketAlert($agent, $notification);
    $coordinator = app(AgentAlertDeliveryCoordinator::class);
    $decision = $coordinator->claimNotificationMail($agent, $notification);
    $visitor = Visitor::factory()->for($ticket->site)->create();
    $conversation = Conversation::factory()->for($ticket->site)->for($visitor)->create();
    $clock = $conversation->slaClocks()->create([
        'account_id' => $agent->account_id,
        'site_id' => $ticket->site_id,
        'metric' => SlaClock::METRIC_FIRST_RESPONSE,
        'priority' => 'normal',
        'target_seconds' => 600,
        'warning_seconds' => 480,
        'elapsed_seconds' => 480,
        'started_at' => now()->subMinutes(8),
        'last_counted_at' => now(),
        'warned_at' => now(),
    ]);
    $slaDelivery = SlaAlertDelivery::query()->create([
        'public_id' => (string) Str::uuid(),
        'sla_clock_id' => $clock->id,
        'user_id' => $agent->id,
        'stage' => 'warning',
        'channel' => 'mail',
        'claimed_at' => now(),
    ]);
    $alert->forceFill([
        'agent_alert_realtime_received_version' => $alert->getAttribute('agent_alert_version'),
    ])->save();

    expect($decision['status'])->toBe('claimed')
        ->and(fn () => $coordinator->markMailTransportStarted(
            $decision['claim'],
            $slaDelivery->public_id,
        ))->toThrow(LogicException::class)
        ->and(AgentAlertDelivery::query()->count())->toBe(0)
        ->and($slaDelivery->fresh()->started_at)->toBeNull();
});

test('an accepted immediate email suppresses Web Push retries but not a later alert version', function (): void {
    configureCrossChannelWebPush();
    [$agent, $assigner, $ticket] = crossChannelTicketWorld(push: false);
    $notification = new TicketAssigned($ticket, $assigner);
    $notification->id = (string) Str::uuid();
    $version = (string) Str::uuid();
    $alert = $agent->notifications()->create([
        'id' => $notification->id,
        'type' => TicketAssigned::class,
        'data' => $notification->toArray($agent),
        'agent_alert_version' => $version,
        'read_at' => null,
    ]);
    useCrossChannelArrayMailer();

    Notification::sendNow($agent, $notification, ['mail']);
    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1);

    subscribeCrossChannelAgent($agent);
    $agent->forceFill([
        'alert_preferences' => [
            ...$agent->alert_preferences,
            'push' => true,
        ],
    ])->save();
    Http::fake(['push.example.test/*' => Http::response('', 201)]);
    $listener = app(SendAgentAlertWebPush::class);

    $listener->handle(new AgentAlertStored($agent->fresh(), $alert), app(AgentWebPushConfig::class));
    Http::assertNothingSent();

    $nextVersion = (string) Str::uuid();
    $alert->forceFill(['agent_alert_version' => $nextVersion])->save();
    $listener->handle(new AgentAlertStored($agent->fresh(), $alert->fresh()), app(AgentWebPushConfig::class));

    Http::assertSentCount(1);
    expect(AgentAlertDelivery::query()->orderBy('id')->pluck('channel')->all())->toBe([
        AgentAlertDeliveryCoordinator::CHANNEL_IMMEDIATE_MAIL,
        AgentAlertDeliveryCoordinator::CHANNEL_PUSH,
    ]);
});

test('immediate mail waits behind realtime and Web Push selection', function (): void {
    [$agent, $assigner, $ticket] = crossChannelTicketWorld();

    expect((new TicketAssigned($ticket, $assigner))->withDelay($agent, 'database'))->toBeNull()
        ->and((new TicketAssigned($ticket, $assigner))->withDelay($agent, 'mail'))->toBe(5);
});

test('a delivered interruptive channel keeps the same event out of a later digest', function (): void {
    [$agent, $assigner, $ticket] = crossChannelTicketWorld(push: false);
    $notification = new TicketAssigned($ticket, $assigner);
    $alert = storeCrossChannelTicketAlert($agent, $notification);
    $version = (string) $alert->getAttribute('agent_alert_version');
    AgentAlertDelivery::query()->create([
        'notification_id' => (string) $alert->id,
        'alert_version' => $version,
        'state_key' => AgentAlertDeliveryCoordinator::STATE_EVENT,
        'channel' => AgentAlertDeliveryCoordinator::CHANNEL_PUSH,
        'started_at' => now(),
        'accepted_at' => now(),
    ]);
    $agent->forceFill([
        'alert_preferences' => [
            ...$agent->alert_preferences,
            'cadence' => User::ALERT_CADENCE_DIGEST,
        ],
    ])->save();

    expect(app(AlertDigestCandidateCollector::class)->forAgent($agent->fresh()))->toBeEmpty();

    $this->travel(1)->minutes();
    $ticket->forceFill(['priority' => 'urgent'])->save();

    expect(app(AlertDigestCandidateCollector::class)->forAgent($agent->fresh()))->toHaveCount(1);
});

test('SLA mail records a completed deduplication instead of retrying a push-delivered version', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create([
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_IMMEDIATE,
        ],
    ]);
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach($agent);
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();
    $clock = $conversation->slaClocks()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'metric' => SlaClock::METRIC_FIRST_RESPONSE,
        'priority' => 'normal',
        'target_seconds' => 600,
        'warning_seconds' => 480,
        'elapsed_seconds' => 480,
        'started_at' => now()->subMinutes(8),
        'last_counted_at' => now(),
        'warned_at' => now(),
    ]);
    $databaseNotification = new SlaDeadlineAlert($clock, 'warning', 'database');
    $databaseNotification->id = (string) Str::uuid();
    Notification::sendNow($agent, $databaseNotification, ['database']);
    $alert = $agent->unreadNotifications()->whereKey($databaseNotification->id)->firstOrFail();
    AgentAlertDelivery::query()->create([
        'notification_id' => (string) $alert->id,
        'alert_version' => (string) $alert->getAttribute('agent_alert_version'),
        'state_key' => AgentAlertDeliveryCoordinator::STATE_EVENT,
        'channel' => AgentAlertDeliveryCoordinator::CHANNEL_PUSH,
        'started_at' => now(),
        'accepted_at' => now(),
    ]);
    $mailDelivery = SlaAlertDelivery::query()->create([
        'public_id' => (string) Str::uuid(),
        'sla_clock_id' => $clock->id,
        'user_id' => $agent->id,
        'stage' => 'warning',
        'channel' => 'mail',
    ]);
    useCrossChannelArrayMailer();

    app()->call([(new SendSlaDeadlineAlertDelivery((int) $mailDelivery->id)), 'handle']);

    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0)
        ->and($mailDelivery->fresh()->deduplicated_at)->not->toBeNull()
        ->and(SlaAlertDelivery::query()->awaitingDispatch()->count())->toBe(0);
});

test('a push-delivered visitor alert does not reappear as unattended email', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create([
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_UNATTENDED,
        ],
    ]);
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach($agent);
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_id' => $visitor->id,
        'sender_type' => Visitor::class,
    ]);
    $conversation->forceFill(['last_message_at' => $message->created_at])->save();
    $agent->notify(new ConversationNeedsReply($message));
    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES + 1)->minutes();
    $alert = $agent->unreadNotifications()->sole();
    AgentAlertDelivery::query()->create([
        'notification_id' => (string) $alert->id,
        'alert_version' => (string) $alert->getAttribute('agent_alert_version'),
        'state_key' => AgentAlertDeliveryCoordinator::STATE_EVENT,
        'channel' => AgentAlertDeliveryCoordinator::CHANNEL_PUSH,
        'started_at' => now(),
        'accepted_at' => now(),
    ]);

    expect(app(UnattendedConversationAlertCollector::class)->forAgent($agent->fresh()))->toBeEmpty();
});
