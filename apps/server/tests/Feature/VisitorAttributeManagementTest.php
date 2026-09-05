<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Enums\VisitorAttributeType;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorAttributeDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('an account manager can define update and remove visitor attributes without changing stored contact data', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create([
        'metadata' => ['context' => ['plan' => 'Enterprise']],
    ]);

    $this->actingAs($admin)
        ->post(route('dashboard.account.visitor-attributes.store'), [
            'key' => 'plan',
            'label' => 'Customer plan',
            'type' => VisitorAttributeType::Text->value,
        ])
        ->assertSessionHasNoErrors();

    $definition = VisitorAttributeDefinition::query()->sole();

    expect($definition->account_id)->toBe($account->id)
        ->and($definition->key)->toBe('plan')
        ->and($definition->type)->toBe(VisitorAttributeType::Text);

    $this->actingAs($admin)
        ->put(route('dashboard.account.visitor-attributes.update', $definition), [
            'editing_definition' => (string) $definition->id,
            'key' => 'replacement_key',
            'label' => 'Membership tier',
            'type' => VisitorAttributeType::Number->value,
        ])
        ->assertSessionHasNoErrors();

    expect($definition->fresh()->key)->toBe('plan')
        ->and($definition->fresh()->label)->toBe('Membership tier')
        ->and($definition->fresh()->type)->toBe(VisitorAttributeType::Number);

    $this->actingAs($admin)
        ->delete(route('dashboard.account.visitor-attributes.destroy', $definition))
        ->assertRedirect(route('dashboard.account.visitor-attributes.index'));

    expect(VisitorAttributeDefinition::query()->count())->toBe(0)
        ->and(data_get($visitor->fresh()->metadata, 'context.plan'))->toBe('Enterprise');

    $events = AuditEvent::query()->orderBy('id')->get();

    expect($events->pluck('action')->all())->toBe([
        'visitor_attribute.created',
        'visitor_attribute.updated',
        'visitor_attribute.deleted',
    ])->and(json_encode($events->pluck('metadata')->all(), JSON_THROW_ON_ERROR))
        ->toContain('attribute_key')
        ->not->toContain('Enterprise');

    $this->actingAs($admin)
        ->get(route('dashboard.account.audit.index', ['audit_search' => 'Membership tier']))
        ->assertOk()
        ->assertSee('Visitor attribute deleted')
        ->assertSee('Membership tier');
});

test('visitor attribute definitions enforce safe unique account-scoped keys and a bounded schema', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    foreach (['email', 'api_token', 'billing_address'] as $unsafeKey) {
        $this->actingAs($admin)
            ->post(route('dashboard.account.visitor-attributes.store'), [
                'key' => $unsafeKey,
                'label' => 'Unsafe',
                'type' => VisitorAttributeType::Text->value,
            ])
            ->assertSessionHasErrors('key');
    }

    $existing = VisitorAttributeDefinition::factory()->for($account)->create(['key' => 'plan']);

    $this->actingAs($admin)
        ->post(route('dashboard.account.visitor-attributes.store'), [
            'key' => 'plan',
            'label' => 'Duplicate plan',
            'type' => VisitorAttributeType::Text->value,
        ])
        ->assertSessionHasErrors('key');

    VisitorAttributeDefinition::factory()->for($account)->count(19)->create();

    $this->actingAs($admin)
        ->post(route('dashboard.account.visitor-attributes.store'), [
            'key' => 'one_too_many',
            'label' => 'One too many',
            'type' => VisitorAttributeType::Text->value,
        ])
        ->assertSessionHasErrors('key');

    $otherAccount = Account::factory()->create();
    $otherAdmin = User::factory()->for($otherAccount)->create(['account_role' => AccountRole::Admin]);

    $this->actingAs($otherAdmin)
        ->put(route('dashboard.account.visitor-attributes.update', $existing), [
            'editing_definition' => (string) $existing->id,
            'label' => 'Cross-account edit',
            'type' => VisitorAttributeType::Text->value,
        ])
        ->assertNotFound();

    $this->actingAs($otherAdmin)
        ->delete(route('dashboard.account.visitor-attributes.destroy', $existing))
        ->assertNotFound();

    expect($existing->fresh()->label)->not->toBe('Cross-account edit');
});

test('attribute edit validation returns errors to the definition that owns the form', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $definition = VisitorAttributeDefinition::factory()->for($account)->create([
        'label' => 'Customer plan',
    ]);

    $index = route('dashboard.account.visitor-attributes.index');

    $response = $this->actingAs($admin)
        ->from($index)
        ->followingRedirects()
        ->put(route('dashboard.account.visitor-attributes.update', $definition), [
            'editing_definition' => (string) $definition->id,
            'label' => '',
            'type' => 'unsupported',
        ]);

    $response
        ->assertOk()
        ->assertSee('id="attribute-'.$definition->id.'-label-error"', false)
        ->assertDontSee('<p id="attribute-label-error"', false);
});

test('a contacts-only role can use assigned visitor records without gaining support-history access', function (): void {
    $account = Account::factory()->create();
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageContacts->value],
    ]);
    $contactManager = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach($contactManager);
    $visitor = Visitor::factory()->for($site)->create([
        'name' => 'Contact Record',
        'metadata' => [
            'last_page_url' => 'https://example.test/current',
            'context' => [
                'plan' => 'Enterprise',
                'seats' => '25',
                'vip' => 'true',
                'joined_on' => '2026-09-05',
                'renewal_on' => 'not-a-date',
                'support_region' => 'West',
            ],
        ],
    ]);
    VisitorAttributeDefinition::factory()->for($account)->create([
        'key' => 'plan',
        'label' => 'Customer plan',
        'type' => VisitorAttributeType::Text,
    ]);
    VisitorAttributeDefinition::factory()->for($account)->create([
        'key' => 'seats',
        'label' => 'Seat count',
        'type' => VisitorAttributeType::Number,
    ]);
    VisitorAttributeDefinition::factory()->for($account)->create([
        'key' => 'vip',
        'label' => 'VIP customer',
        'type' => VisitorAttributeType::Boolean,
    ]);
    VisitorAttributeDefinition::factory()->for($account)->create([
        'key' => 'joined_on',
        'label' => 'Joined on',
        'type' => VisitorAttributeType::Date,
    ]);
    VisitorAttributeDefinition::factory()->for($account)->create([
        'key' => 'renewal_on',
        'label' => 'Renewal date',
        'type' => VisitorAttributeType::Date,
    ]);
    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-CONTACT-PRIVATE',
        'subject' => 'Private support subject',
        'metadata' => ['started_page_url' => 'https://example.test/private-entry'],
    ]);

    $this->actingAs($contactManager)
        ->get(route('dashboard.account.visitor-attributes.index'))
        ->assertOk()
        ->assertSee('Customer plan');

    $this->actingAs($contactManager)
        ->get(route('dashboard.visitors.index'))
        ->assertOk()
        ->assertSee('Contact Record')
        ->assertSee(route('dashboard.visitors.index'), false)
        ->assertDontSee(route('dashboard.sites.index'), false)
        ->assertDontSee('<th>Conversations</th>', false);

    $this->actingAs($contactManager)
        ->get(route('dashboard.visitors.show', $visitor))
        ->assertOk()
        ->assertSee('Customer plan')
        ->assertSee('Enterprise')
        ->assertSeeInOrder(['Joined on', '2026-09-05', 'Renewal date', 'Not set', 'Seat count', '25', 'VIP customer', 'Yes'])
        ->assertSee('support_region')
        ->assertSee('West')
        ->assertDontSee('<td lang="">plan</td>', false)
        ->assertDontSee('id="visitor-history-heading"', false)
        ->assertDontSee('Private support subject')
        ->assertDontSee('WF-CONTACT-PRIVATE')
        ->assertDontSee('https://example.test/private-entry');

    $this->actingAs($contactManager)
        ->get(route('dashboard.conversations.index'))
        ->assertForbidden();

    $this->actingAs($contactManager)
        ->get(route('dashboard.tickets.index'))
        ->assertForbidden();

    $this->actingAs($contactManager)
        ->get(route('dashboard.account.audit.index'))
        ->assertForbidden();
});

test('an unprivileged custom role cannot manage attributes or open visitor records', function (): void {
    $account = Account::factory()->create();
    $role = CustomRole::factory()->for($account)->create(['permissions' => []]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();

    $this->actingAs($agent)
        ->get(route('dashboard.account.visitor-attributes.index'))
        ->assertForbidden();

    $this->actingAs($agent)
        ->get(route('dashboard.visitors.index'))
        ->assertForbidden();

    $this->actingAs($agent)
        ->get(route('dashboard.visitors.show', $visitor))
        ->assertNotFound();
});

test('visitor attribute types normalize only unambiguous values', function (VisitorAttributeType $type, mixed $input, ?string $expected): void {
    expect($type->normalize($input))->toBe($expected);
})->with([
    'trimmed text' => [VisitorAttributeType::Text, '  Enterprise  ', 'Enterprise'],
    'integer' => [VisitorAttributeType::Number, 12, '12'],
    'decimal' => [VisitorAttributeType::Number, '-12.50', '-12.50'],
    'ambiguous number' => [VisitorAttributeType::Number, '01', null],
    'boolean yes' => [VisitorAttributeType::Boolean, 'yes', 'true'],
    'boolean false' => [VisitorAttributeType::Boolean, false, 'false'],
    'invalid boolean' => [VisitorAttributeType::Boolean, 'sometimes', null],
    'date' => [VisitorAttributeType::Date, '2026-09-05', '2026-09-05'],
    'invalid date' => [VisitorAttributeType::Date, '2026-02-30', null],
]);
