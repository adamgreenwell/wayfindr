<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ProactiveMessageDelivery;
use App\Models\ProactiveMessageRule;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/** @return array{0: Site, 1: Visitor, 2: ProactiveMessageRule} */
function proactiveDeliveryWorld(array $rule = [], array $siteSettings = []): array
{
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create([
        'public_key' => 'site_public_proactive',
        'settings' => array_replace_recursive([
            'presence' => ['enabled' => true],
        ], $siteSettings),
    ]);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-proactive',
        'last_web_seen_at' => now(),
    ]);
    $rule = ProactiveMessageRule::factory()->for($site)->create(array_replace([
        'name' => 'Pricing invitation',
        'message' => 'Questions about plans? We are here if you would like a hand.',
        'is_enabled' => true,
        'requires_available_agent' => false,
        'frequency_cap_minutes' => 60,
        'dismissal_snooze_minutes' => 1440,
    ], $rule));

    return [$site, $visitor, $rule];
}

function claimProactiveDelivery(Site $site, ProactiveMessageRule $rule, string $claimKey, string $anonymousId = 'anon-proactive'): TestResponse
{
    return test()->postJson(route('widget.proactive-messages.authorize', $rule->public_id), [
        'site_public_key' => $site->public_key,
        'anonymous_id' => $anonymousId,
        'claim_key' => $claimKey,
    ]);
}

function recordProactiveOutcome(Site $site, string $deliveryId, string $outcome, string $anonymousId = 'anon-proactive'): TestResponse
{
    return test()->postJson(route('widget.proactive-messages.outcomes.store', $deliveryId), [
        'site_public_key' => $site->public_key,
        'anonymous_id' => $anonymousId,
        'outcome' => $outcome,
    ]);
}

test('the delivery factory creates one internally consistent site scope', function (): void {
    $delivery = ProactiveMessageDelivery::factory()->create();

    expect($delivery->site_id)->toBe($delivery->rule()->value('site_id'))
        ->and($delivery->site_id)->toBe($delivery->visitor()->value('site_id'))
        ->and($delivery->rule_public_id)->toBe($delivery->rule()->value('public_id'));
});

test('appearance publishes enabled rules in stable order only while presence is on', function (): void {
    [$site, , $later] = proactiveDeliveryWorld([
        'name' => 'Later',
        'position' => 20,
        'url_contains' => '/pricing',
        'referrer_contains' => 'search.example',
        'delay_seconds' => 45,
        'minimum_visit_count' => 2,
    ]);
    $first = ProactiveMessageRule::factory()->for($site)->create([
        'name' => 'Internal name must not leak',
        'message' => 'A safe first invitation.',
        'position' => 10,
        'is_enabled' => true,
    ]);
    ProactiveMessageRule::factory()->for($site)->create([
        'name' => 'Draft',
        'position' => 1,
        'is_enabled' => false,
    ]);

    $response = $this->getJson(route('widget.appearance', ['site_public_key' => $site->public_key]))
        ->assertOk()
        ->assertJsonCount(2, 'data.proactive_messages')
        ->assertJsonPath('data.proactive_messages.0.id', $first->public_id)
        ->assertJsonPath('data.proactive_messages.0.message', 'A safe first invitation.')
        ->assertJsonPath('data.proactive_messages.1.id', $later->public_id)
        ->assertJsonPath('data.proactive_messages.1.url_contains', '/pricing')
        ->assertJsonPath('data.proactive_messages.1.referrer_contains', 'search.example');

    expect($response->json('data.proactive_messages.0'))->not->toHaveKey('name')
        ->and(json_encode($response->json('data.proactive_messages')))->not->toContain('Draft');

    $site->forceFill(['settings' => ['presence' => ['enabled' => false]]])->save();

    $this->getJson(route('widget.appearance', ['site_public_key' => $site->public_key]))
        ->assertOk()
        ->assertExactJsonStructure(['data' => ['appearance', 'presence', 'proactive_messages', 'locale']])
        ->assertJsonCount(0, 'data.proactive_messages');
});

test('display claims are idempotent and a shown invitation caps every rule for the site', function (): void {
    [$site, $visitor, $rule] = proactiveDeliveryWorld();
    $otherRule = ProactiveMessageRule::factory()->for($site)->create([
        'name' => 'Checkout invitation',
        'message' => 'Need a hand checking out?',
        'is_enabled' => true,
        'requires_available_agent' => false,
        'frequency_cap_minutes' => 60,
    ]);

    $first = claimProactiveDelivery($site, $rule, 'claim-one')
        ->assertCreated()
        ->assertJsonPath('data.authorized', true)
        ->assertJsonPath('data.message', $rule->message);
    $deliveryId = $first->json('data.delivery_id');

    expect(Str::isUuid($deliveryId))->toBeTrue();

    claimProactiveDelivery($site, $rule, 'claim-one')
        ->assertOk()
        ->assertJsonPath('data.delivery_id', $deliveryId);

    expect(ProactiveMessageDelivery::query()->count())->toBe(1);

    // An unshown claim keeps another tab from winning, but does not yet spend
    // the long-lived display cap.
    claimProactiveDelivery($site, $otherRule, 'claim-two')
        ->assertOk()
        ->assertJsonPath('data.authorized', false);

    recordProactiveOutcome($site, $deliveryId, 'shown')->assertAccepted();

    claimProactiveDelivery($site, $otherRule, 'claim-three')
        ->assertOk()
        ->assertJsonPath('data.authorized', false);

    $delivery = ProactiveMessageDelivery::query()->sole();

    expect($delivery->visitor_id)->toBe($visitor->id)
        ->and($delivery->message)->toBe($rule->message)
        ->and($delivery->shown_at)->not->toBeNull()
        ->and($delivery->getAttributes())->not->toHaveKey('url_contains')
        ->and($delivery->getAttributes())->not->toHaveKey('referrer_contains');
});

test('authorization respects presence support hours agent eligibility and site rosters', function (): void {
    $this->travelTo(Carbon::parse('2026-09-06 12:00:00', 'UTC')); // Sunday.

    [$site, $visitor, $rule] = proactiveDeliveryWorld([
        'requires_available_agent' => true,
    ], [
        'availability' => [
            'enabled' => true,
            'timezone' => 'UTC',
            'weekdays' => [
                'mon' => ['09:00', '17:00'],
                'tue' => null,
                'wed' => null,
                'thu' => null,
                'fri' => null,
                'sat' => null,
                'sun' => null,
            ],
        ],
    ]);

    claimProactiveDelivery($site, $rule, 'closed')->assertJsonPath('data.authorized', false);

    $this->travelTo(Carbon::parse('2026-09-07 10:00:00', 'UTC'));
    $visitor->forceFill(['last_web_seen_at' => now()])->save();

    claimProactiveDelivery($site, $rule, 'no-agent')->assertJsonPath('data.authorized', false);

    $online = User::factory()->for($site->account)->create([
        'account_role' => AccountRole::Agent,
        'routing_status' => User::ROUTING_STATUS_ONLINE,
    ]);
    $away = User::factory()->for($site->account)->create([
        'account_role' => AccountRole::Agent,
        'routing_status' => User::ROUTING_STATUS_AWAY,
    ]);

    // An explicit roster replaces account-wide fallback. The online agent is
    // not eligible until this site actually includes them.
    $site->supportAgents()->attach($away);
    claimProactiveDelivery($site, $rule, 'not-on-roster')->assertJsonPath('data.authorized', false);

    $site->supportAgents()->attach($online);
    claimProactiveDelivery($site, $rule, 'eligible')->assertCreated()->assertJsonPath('data.authorized', true);

    $site->forceFill(['settings' => ['presence' => ['enabled' => false]]])->save();
    claimProactiveDelivery($site, $rule, 'presence-off')->assertJsonPath('data.authorized', false);

    $this->travelBack();
});

test('outcomes are monotonic visitor scoped and dismissal is honored across rules', function (): void {
    [$site, $visitor, $rule] = proactiveDeliveryWorld([
        'frequency_cap_minutes' => 1,
        'dismissal_snooze_minutes' => 60,
    ]);
    $otherRule = ProactiveMessageRule::factory()->for($site)->create([
        'is_enabled' => true,
        'requires_available_agent' => false,
        'frequency_cap_minutes' => 1,
        'dismissal_snooze_minutes' => 60,
    ]);
    $deliveryId = claimProactiveDelivery($site, $rule, 'dismiss-me')->assertCreated()->json('data.delivery_id');

    recordProactiveOutcome($site, $deliveryId, 'shown')->assertAccepted();
    recordProactiveOutcome($site, $deliveryId, 'dismissed')->assertAccepted();
    recordProactiveOutcome($site, $deliveryId, 'dismissed')->assertAccepted();
    recordProactiveOutcome($site, $deliveryId, 'engaged')->assertNotFound();

    Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-other']);
    recordProactiveOutcome($site, $deliveryId, 'shown', 'anon-other')->assertNotFound();

    $this->travel(61)->minutes();
    $visitor->forceFill(['last_web_seen_at' => now()])->save();

    claimProactiveDelivery($site, $otherRule, 'after-snooze')
        ->assertCreated()
        ->assertJsonPath('data.authorized', true);
});

test('a new dismissal cannot be forged after the short display claim expires', function (): void {
    [$site, $visitor, $rule] = proactiveDeliveryWorld();
    $deliveryId = claimProactiveDelivery($site, $rule, 'late-dismissal')->assertCreated()->json('data.delivery_id');

    recordProactiveOutcome($site, $deliveryId, 'shown')->assertAccepted();

    $this->travel(6)->minutes();
    $visitor->forceFill(['last_web_seen_at' => now()])->save();

    recordProactiveOutcome($site, $deliveryId, 'dismissed')->assertNotFound();
    expect(ProactiveMessageDelivery::query()->where('public_id', $deliveryId)->sole()->dismissed_at)->toBeNull();
});

test('a recorded engagement remains idempotent after its display claim expires', function (): void {
    [$site, $visitor, $rule] = proactiveDeliveryWorld();
    $deliveryId = claimProactiveDelivery($site, $rule, 'engagement-retry')->assertCreated()->json('data.delivery_id');

    recordProactiveOutcome($site, $deliveryId, 'shown')->assertAccepted();
    recordProactiveOutcome($site, $deliveryId, 'engaged')->assertAccepted();

    $this->travel(6)->minutes();
    $visitor->forceFill(['last_web_seen_at' => now()])->save();

    recordProactiveOutcome($site, $deliveryId, 'engaged')->assertAccepted();
    expect(ProactiveMessageDelivery::query()->where('public_id', $deliveryId)->sole()->engaged_at)->not->toBeNull();
});

test('engagement becomes one exact support-side opening in the ordinary conversation', function (): void {
    [$site, $visitor, $rule] = proactiveDeliveryWorld();
    $deliveryId = claimProactiveDelivery($site, $rule, 'engage-me')->assertCreated()->json('data.delivery_id');
    recordProactiveOutcome($site, $deliveryId, 'shown')->assertAccepted();
    recordProactiveOutcome($site, $deliveryId, 'engaged')->assertAccepted();

    // Preserve what was shown even if the operator edits the rule before the
    // visitor sends their first reply.
    $shownMessage = $rule->message;
    $rule->forceFill(['message' => 'Edited after the invitation was shown.'])->save();

    $token = $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => $visitor->anonymous_id,
    ])->assertSuccessful()->json('data.visitor.token');

    $response = $this->postJson(route('conversations.store'), [
        'site_public_key' => $site->public_key,
        'anonymous_id' => $visitor->anonymous_id,
        'visitor_token' => $token,
        'proactive_message_delivery_id' => $deliveryId,
    ])->assertCreated();

    $conversation = Conversation::query()->where('support_code', $response->json('data.support_code'))->firstOrFail();
    $opening = ConversationMessage::query()->where('conversation_id', $conversation->id)->sole();

    expect($opening->sender_type)->toBe(ProactiveMessageRule::class)
        ->and($opening->sender_id)->toBe($rule->id)
        ->and($opening->body)->toBe($shownMessage)
        ->and($opening->metadata['proactive_delivery_id'])->toBe($deliveryId)
        ->and(ProactiveMessageDelivery::query()->where('public_id', $deliveryId)->sole()->conversation_id)
        ->toBe($conversation->id);

    $this->getJson('/api/conversations/'.$conversation->support_code.'/messages?'.http_build_query([
        'site_public_key' => $site->public_key,
        'anonymous_id' => $visitor->anonymous_id,
        'visitor_token' => $token,
    ]))
        ->assertOk()
        ->assertJsonPath('data.messages.0.sender.kind', 'agent')
        ->assertJsonPath('data.messages.0.sender.name', $site->name)
        ->assertJsonPath('data.messages.0.body', $shownMessage);

    // A receipt is single-use; it cannot be attached to a second conversation.
    $this->postJson(route('conversations.store'), [
        'site_public_key' => $site->public_key,
        'anonymous_id' => $visitor->anonymous_id,
        'visitor_token' => $token,
        'proactive_message_delivery_id' => $deliveryId,
    ])->assertNotFound();

    expect(Conversation::query()->count())->toBe(1);
});

test('an unshown or foreign delivery cannot open a conversation', function (): void {
    [$site, $visitor, $rule] = proactiveDeliveryWorld();
    $deliveryId = claimProactiveDelivery($site, $rule, 'not-shown')->assertCreated()->json('data.delivery_id');
    $token = $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => $visitor->anonymous_id,
    ])->assertSuccessful()->json('data.visitor.token');

    $this->postJson(route('conversations.store'), [
        'site_public_key' => $site->public_key,
        'anonymous_id' => $visitor->anonymous_id,
        'visitor_token' => $token,
        'proactive_message_delivery_id' => $deliveryId,
    ])->assertNotFound();

    expect(Conversation::query()->count())->toBe(0);
});

test('dashboard metrics use the bounded evidence window', function (): void {
    [$site, $visitor, $rule] = proactiveDeliveryWorld();
    $owner = User::factory()->for($site->account)->create(['account_role' => AccountRole::Owner]);

    ProactiveMessageDelivery::factory()->for($site)->for($visitor)->create([
        'proactive_message_rule_id' => $rule->id,
        'rule_public_id' => $rule->public_id,
        'message' => $rule->message,
        'claimed_at' => now()->subDays(10),
        'expires_at' => now()->subDays(10)->addMinutes(5),
        'shown_at' => now()->subDays(10),
        'engaged_at' => now()->subDays(10),
    ]);
    ProactiveMessageDelivery::factory()->for($site)->for($visitor)->create([
        'proactive_message_rule_id' => $rule->id,
        'rule_public_id' => $rule->public_id,
        'message' => $rule->message,
        'claimed_at' => now()->subDays(100),
        'expires_at' => now()->subDays(100)->addMinutes(5),
        'shown_at' => now()->subDays(100),
        'dismissed_at' => now()->subDays(100),
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard.sites.proactive-messages.index', $site))
        ->assertOk()
        ->assertSee('1 shown · 1 engaged · 0 dismissed')
        ->assertSee('Last 90 days');
});

test('delivery evidence is pruned daily after no more than ninety days', function (): void {
    [$site, $visitor, $rule] = proactiveDeliveryWorld();
    $old = ProactiveMessageDelivery::factory()->for($site)->for($visitor)->create([
        'proactive_message_rule_id' => $rule->id,
        'rule_public_id' => $rule->public_id,
        'claimed_at' => now()->subDays(91),
        'expires_at' => now()->subDays(91)->addMinutes(5),
    ]);
    $recent = ProactiveMessageDelivery::factory()->for($site)->for($visitor)->create([
        'proactive_message_rule_id' => $rule->id,
        'rule_public_id' => $rule->public_id,
        'claimed_at' => now()->subDays(89),
        'expires_at' => now()->subDays(89)->addMinutes(5),
    ]);
    $recentOutcome = ProactiveMessageDelivery::factory()->for($site)->for($visitor)->create([
        'proactive_message_rule_id' => $rule->id,
        'rule_public_id' => $rule->public_id,
        'claimed_at' => now()->subDays(91),
        'expires_at' => now()->subDays(91)->addMinutes(5),
        'shown_at' => now()->subDays(91),
        'dismissed_at' => now()->subDays(89),
    ]);

    $this->artisan('wayfindr:prune-proactive-message-deliveries')
        ->expectsOutputToContain('Pruned 1 proactive-message delivery older than 90 days.')
        ->assertSuccessful();

    expect($old->fresh())->toBeNull()
        ->and($recent->fresh())->not->toBeNull()
        ->and($recentOutcome->fresh())->not->toBeNull();

    $pruneEvent = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'wayfindr:prune-proactive-message-deliveries'));

    expect($pruneEvent)->not->toBeNull()
        ->and($pruneEvent?->getExpression())->toBe('0 0 * * *');
});
