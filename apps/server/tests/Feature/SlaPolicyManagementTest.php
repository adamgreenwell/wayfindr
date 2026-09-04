<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\SlaClock;
use App\Models\SlaPolicy;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeTime();
});

function slaPolicyPayload(array $normal = []): array
{
    return ['policies' => [
        'urgent' => ['first_response_minutes' => null, 'resolution_minutes' => null],
        'high' => ['first_response_minutes' => null, 'resolution_minutes' => null],
        'normal' => array_replace(['first_response_minutes' => null, 'resolution_minutes' => null], $normal),
        'low' => ['first_response_minutes' => null, 'resolution_minutes' => null],
    ]];
}

test('a site manager can configure account SLA targets', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $this->actingAs($admin)
        ->get(route('dashboard.account.sla-policies.index'))
        ->assertOk()
        ->assertSee('SLA policies')
        ->assertSee('name="policies[urgent][first_response_minutes]"', false);

    $this->actingAs($admin)
        ->put(route('dashboard.account.sla-policies.update'), slaPolicyPayload([
            'first_response_minutes' => 45,
            'resolution_minutes' => 360,
        ]))
        ->assertRedirect(route('dashboard.account.sla-policies.index'));

    $policy = SlaPolicy::query()->where('account_id', $account->id)->where('priority', 'normal')->firstOrFail();
    expect($policy->first_response_minutes)->toBe(45)
        ->and($policy->resolution_minutes)->toBe(360)
        ->and(AuditEvent::query()->where('action', 'account.sla_policies_updated')->count())->toBe(1);

    $this->actingAs($admin)
        ->get(route('dashboard.account.audit.index'))
        ->assertOk()
        ->assertSee('SLA policies updated')
        ->assertDontSee('Account Sla Policies Updated');
});

test('an ordinary agent cannot read or change account SLA targets', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);

    $this->actingAs($agent)->get(route('dashboard.account.sla-policies.index'))->assertForbidden();
    $this->actingAs($agent)->put(route('dashboard.account.sla-policies.update'), slaPolicyPayload([
        'first_response_minutes' => 10,
    ]))->assertForbidden();

    expect(SlaPolicy::query()->count())->toBe(0);
});

test('enabling a target starts existing open work now rather than fabricating old elapsed time', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['settings' => []]);
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create(['created_at' => now()->subWeek()]);

    $this->actingAs($admin)->put(route('dashboard.account.sla-policies.update'), slaPolicyPayload([
        'first_response_minutes' => 60,
        'resolution_minutes' => 480,
    ]));

    $clocks = $conversation->slaClocks()->orderBy('metric')->get();
    expect($clocks)->toHaveCount(2)
        ->and($clocks->every(fn (SlaClock $clock): bool => $clock->elapsed_seconds === 0))->toBeTrue()
        ->and($clocks->every(fn (SlaClock $clock): bool => $clock->started_at->diffInSeconds(now()) < 1))->toBeTrue();
});

test('clearing a target cancels its active clocks without deleting their history', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    SlaPolicy::factory()->for($account)->create([
        'priority' => 'normal',
        'first_response_minutes' => 60,
        'resolution_minutes' => null,
    ]);
    $site = Site::factory()->for($account)->create(['settings' => []]);
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();

    $this->actingAs($admin)->put(route('dashboard.account.sla-policies.update'), slaPolicyPayload());

    expect(SlaPolicy::query()->where('account_id', $account->id)->count())->toBe(0)
        ->and($conversation->slaClocks()->count())->toBe(1)
        ->and($conversation->slaClocks()->firstOrFail()->cancelled_at)->not->toBeNull();
});

test('changing a policy preserves a breach crossed under the old target', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    SlaPolicy::factory()->for($account)->create([
        'priority' => 'normal',
        'first_response_minutes' => 10,
        'resolution_minutes' => null,
    ]);
    $site = Site::factory()->for($account)->create(['settings' => []]);
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();

    $this->travel(11)->minutes();
    $this->actingAs($admin)
        ->put(route('dashboard.account.sla-policies.update'), slaPolicyPayload([
            'first_response_minutes' => 60,
        ]))
        ->assertRedirect(route('dashboard.account.sla-policies.index'));

    $clock = $conversation->slaClocks()->where('metric', SlaClock::METRIC_FIRST_RESPONSE)->sole();
    expect($clock->elapsed_seconds)->toBe(11 * 60)
        ->and($clock->breached_at)->not->toBeNull()
        ->and($clock->target_seconds)->toBe(10 * 60);
});

test('extending a policy does not consume the future warning at the old threshold', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    SlaPolicy::factory()->for($account)->create([
        'priority' => 'normal',
        'first_response_minutes' => 10,
        'resolution_minutes' => null,
    ]);
    $site = Site::factory()->for($account)->create(['settings' => []]);
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();

    $this->travel(8)->minutes();
    $this->actingAs($admin)->put(route('dashboard.account.sla-policies.update'), slaPolicyPayload([
        'first_response_minutes' => 60,
    ]));

    $clock = $conversation->slaClocks()->where('metric', SlaClock::METRIC_FIRST_RESPONSE)->sole();
    expect($clock->elapsed_seconds)->toBe(8 * 60)
        ->and($clock->warned_at)->toBeNull()
        ->and($clock->breached_at)->toBeNull()
        ->and($clock->target_seconds)->toBe(60 * 60);
});

test('invalid targets return localized validation instead of changing policy', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $this->actingAs($admin)
        ->put(route('dashboard.account.sla-policies.update'), slaPolicyPayload([
            'first_response_minutes' => 1,
        ]))
        ->assertSessionHasErrors('policies.normal.first_response_minutes');

    expect(SlaPolicy::query()->count())->toBe(0);
});

test('an incomplete policy payload cannot silently clear omitted priorities', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    SlaPolicy::factory()->for($account)->create([
        'priority' => 'urgent',
        'first_response_minutes' => 15,
        'resolution_minutes' => 60,
    ]);

    $this->actingAs($admin)
        ->put(route('dashboard.account.sla-policies.update'), [
            'policies' => [
                'normal' => ['first_response_minutes' => 45, 'resolution_minutes' => 360],
            ],
        ])
        ->assertSessionHasErrors('policies.urgent');

    expect(SlaPolicy::query()->where('account_id', $account->id)->where('priority', 'urgent')->firstOrFail()->first_response_minutes)
        ->toBe(15);
});
