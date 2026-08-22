<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Sites\SiteAvailability;
use App\Support\Sites\SiteIntake;
use App\Support\VisitorSessionToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * visitors.name and visitors.email have existed since the first migration and
 * were written by nothing at all. An anonymous visitor -- most traffic on most
 * sites -- ended a conversation with no way to be reached about it.
 */
function intakeSite(array $fields = [], array $availability = []): Site
{
    return Site::factory()->create([
        'settings' => [
            'intake' => ['fields' => $fields, 'intro' => 'Tell us who you are.'],
            'availability' => $availability,
        ],
    ]);
}

function startConversation(Site $site, array $payload = []): TestResponse
{
    $visitor = Visitor::factory()->for($site)->create();

    return test()->postJson('/api/conversations', array_merge([
        'site_public_key' => $site->public_key,
        'anonymous_id' => $visitor->anonymous_id,
        'visitor_token' => app(VisitorSessionToken::class)->issue($site, $visitor),
        'subject' => 'Something is broken',
    ], $payload));
}

test('a site that asks nothing behaves exactly as before', function (): void {
    $site = intakeSite();

    startConversation($site)->assertSuccessful();

    expect(SiteIntake::for($site)->asks())->toBeFalse();
});

test('a required field is enforced by the server, not the widget', function (): void {
    // The widget draws the form; a crafted request skips it entirely.
    $site = intakeSite(['email' => SiteIntake::REQUIRED]);

    startConversation($site)->assertStatus(422)->assertJsonValidationErrors('visitor_email');

    startConversation($site, ['visitor_email' => 'someone@example.test'])->assertSuccessful();
});

test('answers land on the visitor, so the next visit already knows them', function (): void {
    $site = intakeSite(['name' => SiteIntake::OPTIONAL, 'email' => SiteIntake::OPTIONAL]);
    $visitor = Visitor::factory()->for($site)->create(['name' => null, 'email' => null]);

    $this->postJson('/api/conversations', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => $visitor->anonymous_id,
        'visitor_token' => app(VisitorSessionToken::class)->issue($site, $visitor),
        'visitor_name' => '  Avery Lane  ',
        'visitor_email' => 'avery@example.test',
    ])->assertSuccessful();

    $visitor->refresh();

    expect($visitor->name)->toBe('Avery Lane')
        ->and($visitor->email)->toBe('avery@example.test');
});

test('a blank optional answer does not erase what an earlier one captured', function (): void {
    $site = intakeSite(['name' => SiteIntake::OPTIONAL]);
    $visitor = Visitor::factory()->for($site)->create(['name' => 'Avery Lane']);

    $this->postJson('/api/conversations', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => $visitor->anonymous_id,
        'visitor_token' => app(VisitorSessionToken::class)->issue($site, $visitor),
        'visitor_name' => '',
    ])->assertSuccessful();

    expect($visitor->fresh()->name)->toBe('Avery Lane');
});

test('a field the site does not ask for is refused, not quietly accepted', function (): void {
    // Otherwise the configuration is advisory: anyone can post whatever they
    // like and have it stored against the visitor.
    $site = intakeSite(['name' => SiteIntake::OPTIONAL]);

    startConversation($site, ['visitor_email' => 'sneaky@example.test'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('visitor_email');
});

test('the reason belongs to the conversation, not to the person', function (): void {
    // The next conversation may be about something else entirely.
    $site = intakeSite(['reason' => SiteIntake::REQUIRED]);

    $response = startConversation($site, ['visitor_reason' => 'Billing'])->assertSuccessful();

    $conversation = Conversation::query()
        ->where('support_code', $response->json('data.support_code'))
        ->firstOrFail();

    expect($conversation->metadata['reason'])->toBe('Billing');
});

test('out of hours an email is required even where the site left it optional', function (): void {
    // Out of hours it is the only way back to somebody.
    $site = intakeSite(['email' => SiteIntake::OPTIONAL], [
        'enabled' => true,
        'timezone' => 'UTC',
        'weekdays' => array_fill_keys(SiteAvailability::DAYS, null),
    ]);

    startConversation($site)->assertStatus(422)->assertJsonValidationErrors('visitor_email');
    startConversation($site, ['visitor_email' => 'someone@example.test'])->assertSuccessful();
});

test('an invalid address is refused', function (): void {
    $site = intakeSite(['email' => SiteIntake::REQUIRED]);

    startConversation($site, ['visitor_email' => 'not-an-address'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('visitor_email');
});

test('bootstrap tells the widget what to ask, and whether it already knows', function (): void {
    $site = intakeSite(['name' => SiteIntake::REQUIRED]);
    $visitor = Visitor::factory()->for($site)->create(['external_id' => null]);

    $data = $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => $visitor->anonymous_id,
    ])->assertSuccessful()->json('data');

    expect($data['site']['intake']['asks'])->toBeTrue()
        ->and($data['site']['intake']['fields']['name'])->toBe(SiteIntake::REQUIRED)
        ->and($data['site']['intake']['intro'])->toBe('Tell us who you are.')
        // The widget could previously only guess this from its own option,
        // which can be set while the server rejected the value.
        ->and($data['visitor']['identified'])->toBeFalse();
});

test('an admin can configure what is asked', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['settings' => []]);

    $this->actingAs($admin)->put(route('dashboard.sites.intake.update', $site), [
        'intake_fields' => ['name' => SiteIntake::OPTIONAL, 'email' => SiteIntake::REQUIRED],
        'intake_intro' => 'So we can get back to you.',
    ])->assertRedirect(route('dashboard.sites.show', $site));

    $intake = SiteIntake::for($site->fresh());

    expect($intake->fields['name'])->toBe(SiteIntake::OPTIONAL)
        ->and($intake->fields['email'])->toBe(SiteIntake::REQUIRED)
        // Omitted entirely, which must mean off rather than unchanged.
        ->and($intake->fields['reason'])->toBe(SiteIntake::OFF)
        ->and($intake->intro)->toBe('So we can get back to you.');
});

test('a plain agent cannot change what visitors are asked', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $site = Site::factory()->for($account)->create(['settings' => []]);

    $this->actingAs($agent)->put(route('dashboard.sites.intake.update', $site), [
        'intake_fields' => ['email' => SiteIntake::REQUIRED],
    ])->assertForbidden();

    expect($site->fresh()->settings['intake'] ?? null)->toBeNull();
});

test('an unrecognised mode is treated as off', function (): void {
    // A misspelt key must not start interrupting a site's visitors.
    $site = intakeSite(['email' => 'mandatory']);

    expect(SiteIntake::for($site)->fields['email'])->toBe(SiteIntake::OFF)
        ->and(SiteIntake::for($site)->asks())->toBeFalse();
});
