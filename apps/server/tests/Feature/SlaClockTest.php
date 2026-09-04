<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\SlaClock;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\SlaDeadlineAlert;
use App\Support\Sla\SlaClockManager;
use App\Support\Sla\SlaStatePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function slaWorld(array $availability = []): array
{
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => false,
            'cadence' => User::ALERT_CADENCE_IMMEDIATE,
        ],
    ]);
    $site = Site::factory()->for($account)->create([
        'settings' => ['availability' => array_replace([
            'enabled' => true,
            'timezone' => 'UTC',
            'weekdays' => [
                'mon' => ['09:00', '17:00'],
                'tue' => ['09:00', '17:00'],
                'wed' => ['09:00', '17:00'],
                'thu' => ['09:00', '17:00'],
                'fri' => ['09:00', '17:00'],
                'sat' => null,
                'sun' => null,
            ],
        ], $availability)],
    ]);
    $site->supportAgents()->attach($agent);

    return compact('account', 'agent', 'site');
}

function configureNormalSla(Account $account, int $response = 60, int $resolution = 480): SlaPolicy
{
    return SlaPolicy::factory()->for($account)->create([
        'priority' => 'normal',
        'first_response_minutes' => $response,
        'resolution_minutes' => $resolution,
        'effective_at' => now(),
    ]);
}

test('new conversations start response and resolution clocks from account policy', function (): void {
    $world = slaWorld();
    configureNormalSla($world['account']);
    $this->travelTo(CarbonImmutable::parse('2026-08-26 10:00', 'UTC'));

    $visitor = Visitor::factory()->for($world['site'])->create();
    $conversation = Conversation::factory()->for($world['site'])->for($visitor)->create();

    expect($conversation->slaClocks()->orderBy('metric')->pluck('metric')->all())
        ->toBe([SlaClock::METRIC_FIRST_RESPONSE, SlaClock::METRIC_RESOLUTION])
        ->and($conversation->slaClocks()->where('metric', SlaClock::METRIC_FIRST_RESPONSE)->first()->target_seconds)
        ->toBe(60 * 60);
});

test('reply and close settle their matching clocks with business time only', function (): void {
    $world = slaWorld();
    configureNormalSla($world['account']);
    $this->travelTo(CarbonImmutable::parse('2026-08-28 16:00', 'UTC'));
    $visitor = Visitor::factory()->for($world['site'])->create();
    $conversation = Conversation::factory()->for($world['site'])->for($visitor)->create();

    $this->travelTo(CarbonImmutable::parse('2026-08-31 10:00', 'UTC'));
    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => User::class,
        'sender_id' => $world['agent']->id,
    ]);

    $response = $conversation->slaClocks()->where('metric', SlaClock::METRIC_FIRST_RESPONSE)->firstOrFail();
    expect($response->elapsed_seconds)->toBe(2 * 60 * 60)
        ->and($response->satisfied_at)->not->toBeNull();

    $conversation->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
    $resolution = $conversation->slaClocks()->where('metric', SlaClock::METRIC_RESOLUTION)->firstOrFail();
    expect($resolution->elapsed_seconds)->toBe(2 * 60 * 60)
        ->and($resolution->satisfied_at)->not->toBeNull();
});

test('closing without a reply cancels its response clock and reopening starts a new episode', function (): void {
    Notification::fake();
    $world = slaWorld(['enabled' => false]);
    configureNormalSla($world['account'], response: 5, resolution: 60);
    $visitor = Visitor::factory()->for($world['site'])->create();
    $conversation = Conversation::factory()->for($world['site'])->for($visitor)->create();

    $this->travel(3)->minutes();
    $conversation->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

    $firstEpisode = $conversation->slaClocks()
        ->where('metric', SlaClock::METRIC_FIRST_RESPONSE)
        ->sole();

    expect($firstEpisode->cancelled_at)->not->toBeNull()
        ->and($firstEpisode->satisfied_at)->toBeNull()
        ->and($conversation->slaClocks()->whereNull('satisfied_at')->whereNull('cancelled_at')->count())->toBe(0);

    $this->travel(10)->minutes();
    Artisan::call('wayfindr:evaluate-sla-clocks');
    Notification::assertNothingSent();

    $conversation->forceFill(['status' => 'open', 'closed_at' => null])->save();

    expect($conversation->slaClocks()->where('metric', SlaClock::METRIC_FIRST_RESPONSE)->count())->toBe(2)
        ->and($conversation->slaClocks()->where('metric', SlaClock::METRIC_FIRST_RESPONSE)->whereNull('satisfied_at')->whereNull('cancelled_at')->count())->toBe(1);
});

test('a reopen starts a new resolution episode without rewriting the old one', function (): void {
    $world = slaWorld();
    configureNormalSla($world['account']);
    $this->travelTo(CarbonImmutable::parse('2026-08-26 10:00', 'UTC'));
    $ticket = Ticket::factory()->for($world['account'])->for($world['site'])->create();

    $this->travel(30)->minutes();
    $ticket->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
    $this->travel(10)->minutes();
    $ticket->forceFill(['status' => 'open', 'closed_at' => null])->save();

    $clocks = $ticket->slaClocks()->orderBy('id')->get();
    expect($clocks)->toHaveCount(2)
        ->and($clocks[0]->satisfied_at)->not->toBeNull()
        ->and($clocks[0]->elapsed_seconds)->toBe(30 * 60)
        ->and($clocks[1]->satisfied_at)->toBeNull()
        ->and($clocks[1]->elapsed_seconds)->toBe(0);
});

test('reopened work without an active target does not present an old resolution episode as current', function (): void {
    $world = slaWorld(['enabled' => false]);
    $policy = configureNormalSla($world['account']);
    $ticket = Ticket::factory()->for($world['account'])->for($world['site'])->create();

    $ticket->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
    $policy->delete();
    $ticket->forceFill(['status' => 'open', 'closed_at' => null])->save();

    expect($ticket->slaClocks()->count())->toBe(1)
        ->and($ticket->slaClocks()->whereNull('satisfied_at')->whereNull('cancelled_at')->count())->toBe(0)
        ->and(app(SlaStatePresenter::class)->all($ticket->fresh()))->toBeEmpty();

    $this->actingAs($world['agent'])
        ->get(route('dashboard.tickets.index'))
        ->assertOk()
        ->assertDontSee('Resolution: Met');
});

test('the evaluator warns once and breaches once', function (): void {
    Notification::fake();
    $world = slaWorld(['enabled' => false]);
    configureNormalSla($world['account'], response: 10);
    $this->travelTo(CarbonImmutable::parse('2026-08-26 10:00', 'UTC'));
    $visitor = Visitor::factory()->for($world['site'])->create();
    $conversation = Conversation::factory()->for($world['site'])->for($visitor)->create();

    $this->travel(8)->minutes();
    expect(Artisan::call('wayfindr:evaluate-sla-clocks'))->toBe(0);
    Notification::assertSentTo($world['agent'], SlaDeadlineAlert::class, fn ($notification) => data_get($notification->toArray($world['agent']), 'stage') === 'warning');

    Artisan::call('wayfindr:evaluate-sla-clocks');
    Notification::assertSentToTimes($world['agent'], SlaDeadlineAlert::class, 1);

    $this->travel(2)->minutes();
    Artisan::call('wayfindr:evaluate-sla-clocks');
    Notification::assertSentToTimes($world['agent'], SlaDeadlineAlert::class, 2);

    $clock = $conversation->slaClocks()->where('metric', SlaClock::METRIC_FIRST_RESPONSE)->firstOrFail();
    expect($clock->warned_at)->not->toBeNull()
        ->and($clock->breached_at)->not->toBeNull();
});

test('a quiet assigned agent does not turn one SLA alert into a team-wide fallback', function (): void {
    Notification::fake();
    $world = slaWorld(['enabled' => false]);
    configureNormalSla($world['account'], response: 5);
    $quiet = User::factory()->for($world['account'])->create([
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_QUIET,
            'email' => false,
            'cadence' => User::ALERT_CADENCE_IMMEDIATE,
        ],
    ]);
    $world['site']->supportAgents()->attach($quiet);
    $visitor = Visitor::factory()->for($world['site'])->create();
    Conversation::factory()->for($world['site'])->for($visitor)->create([
        'assigned_agent_id' => $quiet->id,
    ]);

    $this->travel(4)->minutes();
    Artisan::call('wayfindr:evaluate-sla-clocks');

    Notification::assertNothingSent();
});

test('assigned-only agents do not receive SLA alerts for unassigned tickets', function (): void {
    Notification::fake();
    $world = slaWorld(['enabled' => false]);
    configureNormalSla($world['account'], resolution: 5);
    $assignedOnly = User::factory()->for($world['account'])->create([
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ASSIGNED,
            'email' => false,
            'cadence' => User::ALERT_CADENCE_IMMEDIATE,
        ],
    ]);
    $world['site']->supportAgents()->attach($assignedOnly);
    Ticket::factory()->for($world['account'])->for($world['site'])->create(['assignee_id' => null]);

    $this->travel(4)->minutes();
    Artisan::call('wayfindr:evaluate-sla-clocks');

    Notification::assertSentTo($world['agent'], SlaDeadlineAlert::class);
    Notification::assertNotSentTo($assignedOnly, SlaDeadlineAlert::class);
});

test('queued SLA mail rechecks assignment and quiet mode before delivery', function (): void {
    $world = slaWorld(['enabled' => false]);
    $world['agent']->forceFill([
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ASSIGNED,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_IMMEDIATE,
        ],
    ])->save();
    $replacement = User::factory()->for($world['account'])->create();
    $world['site']->supportAgents()->attach($replacement);
    configureNormalSla($world['account'], response: 10);
    $visitor = Visitor::factory()->for($world['site'])->create();
    $conversation = Conversation::factory()->for($world['site'])->for($visitor)->create([
        'assigned_agent_id' => $world['agent']->id,
    ]);
    $clock = $conversation->slaClocks()->where('metric', SlaClock::METRIC_FIRST_RESPONSE)->sole();
    $notification = new SlaDeadlineAlert($clock, 'warning');

    $conversation->forceFill(['assigned_agent_id' => $replacement->id])->save();
    expect($notification->shouldSend($world['agent'], 'mail'))->toBeFalse();

    $conversation->forceFill(['assigned_agent_id' => $world['agent']->id])->save();
    $world['agent']->forceFill([
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_QUIET,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_IMMEDIATE,
        ],
    ])->save();

    expect($notification->shouldSend($world['agent'], 'mail'))->toBeFalse();
});

test('advancing before an hours edit preserves time already counted', function (): void {
    $world = slaWorld();
    configureNormalSla($world['account']);
    $this->travelTo(CarbonImmutable::parse('2026-08-26 10:00', 'UTC'));
    $ticket = Ticket::factory()->for($world['account'])->for($world['site'])->create();

    $this->travel(30)->minutes();
    app(SlaClockManager::class)->advanceSite($world['site'], now());
    $world['site']->update(['settings' => ['availability' => ['enabled' => true, 'timezone' => 'UTC', 'weekdays' => array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], null)]]]);
    $this->travel(30)->minutes();
    app(SlaClockManager::class)->advanceSite($world['site']->fresh(), now());

    expect($ticket->slaClocks()->firstOrFail()->elapsed_seconds)->toBe(30 * 60);
});

test('conversation queues and detail show the same approaching deadline', function (): void {
    $world = slaWorld(['enabled' => false]);
    configureNormalSla($world['account'], response: 10);
    $this->travelTo(CarbonImmutable::parse('2026-08-26 10:00', 'UTC'));
    $visitor = Visitor::factory()->for($world['site'])->create();
    $conversation = Conversation::factory()->for($world['site'])->for($visitor)->create([
        'support_code' => 'WF-SLA-VISIBLE',
        'subject' => 'Deadline visibility',
    ]);

    $this->travel(8)->minutes();

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.index'))
        ->assertOk()
        ->assertSee('First response: Approaching breach')
        ->assertSee('2 working minutes remain.');

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk()
        ->assertSee('SLA state')
        ->assertSee('Approaching breach')
        ->assertSee('2 working minutes remain.');
});

test('changing conversation priority applies the matching active targets', function (): void {
    $world = slaWorld(['enabled' => false]);
    configureNormalSla($world['account'], response: 60);
    SlaPolicy::factory()->for($world['account'])->create([
        'priority' => 'urgent',
        'first_response_minutes' => 15,
        'resolution_minutes' => 120,
        'effective_at' => now(),
    ]);
    $visitor = Visitor::factory()->for($world['site'])->create();
    $conversation = Conversation::factory()->for($world['site'])->for($visitor)->create([
        'support_code' => 'WF-SLA-PRIORITY',
    ]);

    $this->actingAs($world['agent'])
        ->put(route('dashboard.conversations.priority.update', $conversation->support_code), [
            'priority' => 'urgent',
        ])
        ->assertRedirect(route('dashboard.conversations.show', $conversation->support_code));

    expect($conversation->fresh()->priority)->toBe('urgent')
        ->and($conversation->slaClocks()->whereNull('cancelled_at')->where('metric', SlaClock::METRIC_FIRST_RESPONSE)->sole()->target_seconds)
        ->toBe(15 * 60)
        ->and($conversation->slaClocks()->whereNull('cancelled_at')->where('metric', SlaClock::METRIC_RESOLUTION)->sole()->target_seconds)
        ->toBe(120 * 60);
});

test('ticket queues and detail show resolution breaches', function (): void {
    $world = slaWorld(['enabled' => false]);
    configureNormalSla($world['account'], resolution: 10);
    $this->travelTo(CarbonImmutable::parse('2026-08-26 10:00', 'UTC'));
    $ticket = Ticket::factory()->for($world['account'])->for($world['site'])->create([
        'subject' => 'Ticket deadline visibility',
    ]);

    $this->travel(11)->minutes();

    $this->actingAs($world['agent'])
        ->get(route('dashboard.tickets.index'))
        ->assertOk()
        ->assertSee('Resolution: Breached')
        ->assertSee('Over target by 1 working minute.');

    $this->actingAs($world['agent'])
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Resolution')
        ->assertSee('Breached')
        ->assertSee('Over target by 1 working minute.');
});

test('completing exactly on the target is recorded as met', function (): void {
    $world = slaWorld(['enabled' => false]);
    configureNormalSla($world['account'], response: 10);
    $this->travelTo(CarbonImmutable::parse('2026-08-26 10:00', 'UTC'));
    $visitor = Visitor::factory()->for($world['site'])->create();
    $conversation = Conversation::factory()->for($world['site'])->for($visitor)->create();

    $this->travel(10)->minutes();
    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => User::class,
        'sender_id' => $world['agent']->id,
    ]);

    $clock = $conversation->slaClocks()->where('metric', SlaClock::METRIC_FIRST_RESPONSE)->sole();

    expect($clock->elapsed_seconds)->toBe(10 * 60)
        ->and($clock->satisfied_at)->not->toBeNull()
        ->and($clock->breached_at)->toBeNull();
});
