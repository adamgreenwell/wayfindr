<?php

use App\Enums\AccountRole;
use App\Enums\VisitorAttributeType;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorAttributeDefinition;
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

test('the index includes contacts that originated outside a browser', function (): void {
    $w = visitorIndexWorld();
    $emailContact = Visitor::factory()->for($w['site'])->create([
        'anonymous_id' => null,
        'name' => 'Email Contact',
        'email' => 'email-contact@example.test',
    ]);

    $this->actingAs($w['agent'])
        ->get(route('dashboard.visitors.index'))
        ->assertOk()
        ->assertSee('Email Contact')
        ->assertSee(route('dashboard.visitors.show', $emailContact), false);
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

test('defined visitor attributes filter by an exact typed value within the account', function (): void {
    $w = visitorIndexWorld();
    VisitorAttributeDefinition::factory()->for($w['account'])->create([
        'key' => 'plan',
        'label' => 'Customer plan',
        'type' => VisitorAttributeType::Text,
    ]);
    VisitorAttributeDefinition::factory()->for($w['account'])->create([
        'key' => 'seats',
        'label' => 'Seat count',
        'type' => VisitorAttributeType::Number,
    ]);
    VisitorAttributeDefinition::factory()->for($w['account'])->create([
        'key' => 'vip',
        'label' => 'VIP customer',
        'type' => VisitorAttributeType::Boolean,
    ]);
    Visitor::factory()->for($w['site'])->create([
        'name' => 'Enterprise Contact',
        'metadata' => ['context' => ['plan' => 'Enterprise', 'seats' => '25', 'vip' => 'YeS']],
    ]);
    Visitor::factory()->for($w['site'])->create([
        'name' => 'Starter Contact',
        'metadata' => ['context' => ['plan' => 'Starter', 'seats' => '3', 'vip' => 'off']],
    ]);

    $this->actingAs($w['agent'])
        ->get(route('dashboard.visitors.index', [
            'attribute' => 'plan',
            'attribute_value' => 'Enterprise',
        ]))
        ->assertOk()
        ->assertSee('Enterprise Contact')
        ->assertDontSee('Starter Contact');

    $this->actingAs($w['agent'])
        ->get(route('dashboard.visitors.index', [
            'attribute' => 'seats',
            'attribute_value' => '25',
        ]))
        ->assertOk()
        ->assertSee('Enterprise Contact')
        ->assertDontSee('Starter Contact');

    $this->actingAs($w['agent'])
        ->get(route('dashboard.visitors.index', [
            'attribute' => 'vip',
            'attribute_value' => 'true',
        ]))
        ->assertOk()
        ->assertSee('Enterprise Contact')
        ->assertDontSee('Starter Contact');

    $this->actingAs($w['agent'])
        ->get(route('dashboard.visitors.index', [
            'attribute' => 'vip',
            'attribute_value' => 'false',
        ]))
        ->assertOk()
        ->assertSee('Starter Contact')
        ->assertDontSee('Enterprise Contact');
});

test('unknown malformed and invalid visitor attribute filters never widen account scope or fail the page', function (): void {
    $w = visitorIndexWorld();
    VisitorAttributeDefinition::factory()->for($w['account'])->create([
        'key' => 'seats',
        'label' => 'Seat count',
        'type' => VisitorAttributeType::Number,
    ]);
    Visitor::factory()->for($w['site'])->create([
        'name' => 'Our Contact',
        'metadata' => ['context' => ['seats' => '25']],
    ]);
    $otherSite = Site::factory()->for(Account::factory())->create();
    Visitor::factory()->for($otherSite)->create([
        'name' => 'Other Account Contact',
        'metadata' => ['context' => ['seats' => '25']],
    ]);

    $this->actingAs($w['agent'])
        ->get(route('dashboard.visitors.index', [
            'attribute' => ['seats'],
            'attribute_value' => ['25'],
        ]))
        ->assertOk()
        ->assertSee('Our Contact')
        ->assertDontSee('Other Account Contact');

    $this->actingAs($w['agent'])
        ->get(route('dashboard.visitors.index', [
            'attribute' => 'seats',
            'attribute_value' => 'many',
        ]))
        ->assertOk()
        ->assertSee('Enter a value that matches the selected attribute type.')
        ->assertSee('Our Contact')
        ->assertDontSee('Other Account Contact');
});

test('an unknown presence value is ignored rather than emptying the list', function (): void {
    $w = visitorIndexWorld();
    Visitor::factory()->for($w['site'])->create(['name' => 'Avery Lane', 'last_seen_at' => now()]);

    $this->actingAs($w['agent'])
        ->get(route('dashboard.visitors.index', ['presence' => 'nonsense']))
        ->assertOk()
        ->assertSee('Avery Lane');
});

test('a presence value that is not even a string is ignored too', function (): void {
    // `?presence[]=active` casts an array to string, which is a PHP warning that
    // Laravel raises as an ErrorException -- a 500 from a query string anybody
    // can type. The two filters beside it in the same method already guard with
    // is_string(); this one did not.
    $w = visitorIndexWorld();
    Visitor::factory()->for($w['site'])->create(['name' => 'Avery Lane', 'last_seen_at' => now()]);

    $this->actingAs($w['agent'])
        ->get(route('dashboard.visitors.index', ['presence' => ['active']]))
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

test('a translated visitor directory translates its paginator too', function (): void {
    $w = visitorIndexWorld();
    $w['agent']->forceFill(['locale' => 'de'])->save();

    foreach (range(1, 26) as $index) {
        Visitor::factory()->for($w['site'])->create([
            'anonymous_id' => 'anon-page-'.$index,
            'name' => 'Visitor '.$index,
            'last_seen_at' => now()->subMinutes($index),
        ]);
    }

    $this->actingAs($w['agent'])
        ->get(route('dashboard.visitors.index', ['page' => 2]))
        ->assertOk()
        ->assertSee('<html lang="de"', false)
        ->assertSee('aria-label="Seitennavigation"', false)
        ->assertSee('Ergebnisse <span class="font-medium">26</span> bis <span class="font-medium">26</span> von <span class="font-medium">26</span>', false)
        ->assertSee('Zurück')
        ->assertSee('Weiter')
        ->assertDontSee('Pagination navigation')
        ->assertDontSee('Showing')
        ->assertDontSee('Previous')
        ->assertDontSee('Next');
});

test('the rail offers visitors', function (): void {
    $w = visitorIndexWorld();

    $this->actingAs($w['agent'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('dashboard.visitors.index'), false);
});
