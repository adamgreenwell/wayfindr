<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Visitors\VisitorPresence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * A profile page existed with no way to reach it except from a conversation or
 * a support-code lookup. This lists the people a desk has heard from -- and
 * only those: Wayfindr records a visitor when the widget is opened, never on
 * page load, so it cannot list people who were merely watched (ADR 0016).
 */
function visitorIndexWorld(): array
{
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['name' => 'Northwind Docs']);

    return compact('account', 'agent', 'site');
}

test('the index lists visitors this agent may see, most recent first', function (): void {
    $w = visitorIndexWorld();

    $older = Visitor::factory()->for($w['site'])->create([
        'name' => 'Older Visitor',
        'last_seen_at' => now()->subHours(2),
    ]);
    $newer = Visitor::factory()->for($w['site'])->create([
        'name' => 'Newer Visitor',
        'last_seen_at' => now()->subMinute(),
    ]);

    $this->actingAs($w['agent'])
        ->get(route('dashboard.visitors.index'))
        ->assertOk()
        ->assertSeeInOrder(['Newer Visitor', 'Older Visitor'])
        ->assertSee(route('dashboard.visitors.show', $newer), false)
        ->assertSee(route('dashboard.visitors.show', $older), false);
});

test('another account\'s visitors never appear', function (): void {
    $w = visitorIndexWorld();
    $otherSite = Site::factory()->for(Account::factory())->create();
    Visitor::factory()->for($otherSite)->create(['name' => 'Somebody Else']);
    Visitor::factory()->for($w['site'])->create(['name' => 'Our Visitor']);

    $this->actingAs($w['agent'])
        ->get(route('dashboard.visitors.index'))
        ->assertOk()
        ->assertSee('Our Visitor')
        ->assertDontSee('Somebody Else');
});

test('a site the agent is not assigned to is excluded', function (): void {
    $w = visitorIndexWorld();
    $agent = User::factory()->for($w['account'])->create(['account_role' => AccountRole::Agent]);

    $restricted = Site::factory()->for($w['account'])->create(['name' => 'Restricted']);
    // Explicit support agents make a site visible only to them.
    $restricted->supportAgents()->attach($w['agent']->id);

    Visitor::factory()->for($restricted)->create(['name' => 'Restricted Visitor']);
    Visitor::factory()->for($w['site'])->create(['name' => 'Open Visitor']);

    $this->actingAs($agent)
        ->get(route('dashboard.visitors.index'))
        ->assertOk()
        ->assertSee('Open Visitor')
        ->assertDontSee('Restricted Visitor');
});

test('a site filter cannot be used to widen the scope', function (): void {
    // A site id in the query string is checked against what the agent may
    // already see, so it can only ever narrow.
    $w = visitorIndexWorld();
    $otherSite = Site::factory()->for(Account::factory())->create();
    Visitor::factory()->for($otherSite)->create(['name' => 'Somebody Else']);
    Visitor::factory()->for($w['site'])->create(['name' => 'Our Visitor']);

    $this->actingAs($w['agent'])
        ->get(route('dashboard.visitors.index', ['site' => $otherSite->id]))
        ->assertOk()
        ->assertDontSee('Somebody Else')
        // The bad filter is ignored rather than honoured.
        ->assertSee('Our Visitor');
});

test('tester visitors are not listed', function (): void {
    // The hosted tester page creates real visitor rows; without this an agent
    // watches themselves browse.
    $w = visitorIndexWorld();
    Visitor::factory()->for($w['site'])->create([
        'anonymous_id' => 'tester-site-'.$w['site']->id.'-agent-'.$w['agent']->id,
        'name' => 'Tester Row',
    ]);
    Visitor::factory()->for($w['site'])->create(['name' => 'Real Visitor']);

    $this->actingAs($w['agent'])
        ->get(route('dashboard.visitors.index'))
        ->assertOk()
        ->assertSee('Real Visitor')
        ->assertDontSee('Tester Row');
});

test('presence filters use the same buckets as the conversation queue', function (): void {
    $this->travelTo(Carbon::parse('2026-08-22 12:00:00', 'UTC'));
    $w = visitorIndexWorld();

    Visitor::factory()->for($w['site'])->create(['name' => 'Active One', 'last_seen_at' => now()->subMinute()]);
    Visitor::factory()->for($w['site'])->create(['name' => 'Recent One', 'last_seen_at' => now()->subMinutes(8)]);
    Visitor::factory()->for($w['site'])->create(['name' => 'Quiet One', 'last_seen_at' => now()->subMinutes(30)]);
    Visitor::factory()->for($w['site'])->create(['name' => 'Never One', 'last_seen_at' => null]);

    $expectations = [
        VisitorPresence::ACTIVE => ['Active One', ['Recent One', 'Quiet One', 'Never One']],
        VisitorPresence::RECENT => ['Recent One', ['Active One', 'Quiet One', 'Never One']],
        VisitorPresence::QUIET => ['Quiet One', ['Active One', 'Recent One', 'Never One']],
        VisitorPresence::NOT_REPORTED => ['Never One', ['Active One', 'Recent One', 'Quiet One']],
    ];

    foreach ($expectations as $state => [$shown, $hidden]) {
        $response = $this->actingAs($w['agent'])
            ->get(route('dashboard.visitors.index', ['presence' => $state]))
            ->assertOk()
            ->assertSee($shown);

        foreach ($hidden as $name) {
            $response->assertDontSee($name);
        }
    }
});

test('search finds a visitor by what they told us and by what we generated', function (): void {
    $w = visitorIndexWorld();
    Visitor::factory()->for($w['site'])->create(['name' => 'Avery Lane', 'email' => 'avery@example.test']);
    Visitor::factory()->for($w['site'])->create(['name' => 'Someone Unrelated']);

    foreach (['Avery', 'avery@example.test'] as $term) {
        $this->actingAs($w['agent'])
            ->get(route('dashboard.visitors.index', ['search' => $term]))
            ->assertOk()
            ->assertSee('Avery Lane')
            ->assertDontSee('Someone Unrelated');
    }
});

test('an unknown presence value is ignored rather than emptying the list', function (): void {
    $w = visitorIndexWorld();
    Visitor::factory()->for($w['site'])->create(['name' => 'Avery Lane', 'last_seen_at' => now()]);

    $this->actingAs($w['agent'])
        ->get(route('dashboard.visitors.index', ['presence' => 'nonsense']))
        ->assertOk()
        ->assertSee('Avery Lane');
});

test('the list counts the conversations a visitor has had', function (): void {
    $w = visitorIndexWorld();
    $visitor = Visitor::factory()->for($w['site'])->create(['name' => 'Avery Lane']);
    Conversation::factory()->count(3)->for($w['site'])->for($visitor)->create();

    $this->actingAs($w['agent'])
        ->get(route('dashboard.visitors.index'))
        ->assertOk()
        ->assertSee('Avery Lane');

    expect($visitor->conversations()->count())->toBe(3);
});

test('the rail offers visitors', function (): void {
    $w = visitorIndexWorld();

    $this->actingAs($w['agent'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('dashboard.visitors.index'), false);
});
