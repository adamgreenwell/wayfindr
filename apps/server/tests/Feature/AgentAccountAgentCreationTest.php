<?php

use App\Enums\AccountRole;
use App\Mail\AgentWelcomeMessage;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('owners and admins can create a new default agent from the account page', function (AccountRole $actorRole): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $actor = User::factory()->for($account)->create(['account_role' => $actorRole]);
    Mail::fake();

    $this->actingAs($actor)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSee('Add agent')
        ->assertSee('New agents start with the Agent role')
        ->assertSee('/dashboard/account/agents', false);

    $response = $this->actingAs($actor)
        ->from('/dashboard/account')
        ->post('/dashboard/account/agents', [
            'name' => 'Bea Builder',
            'email' => 'bea@example.test',
        ]);

    $response
        ->assertRedirect('/dashboard/account')
        ->assertSessionHas('status', 'Agent created. Share the temporary password securely.')
        ->assertSessionHas('created_agent_email', 'bea@example.test')
        ->assertSessionHas('created_agent_password');

    $createdAgent = User::query()->where('email', 'bea@example.test')->firstOrFail();
    $auditEvent = AuditEvent::query()
        ->where('action', 'agent.created')
        ->firstOrFail();

    expect($createdAgent->account_id)->toBe($account->id)
        ->and($createdAgent->name)->toBe('Bea Builder')
        ->and($createdAgent->account_role)->toBe(AccountRole::Agent)
        ->and(Hash::check($response->getSession()->get('created_agent_password'), $createdAgent->password))->toBeTrue()
        ->and($auditEvent->account_id)->toBe($account->id)
        ->and($auditEvent->actor->is($actor))->toBeTrue()
        ->and($auditEvent->subject->is($createdAgent))->toBeTrue()
        ->and($auditEvent->metadata['welcome_email_requested'])->toBeFalse()
        ->and($auditEvent->metadata['welcome_email_sent'])->toBeFalse();

    Mail::assertNothingSent();
})->with([
    'owner' => [AccountRole::Owner],
    'admin' => [AccountRole::Admin],
]);

test('owners and admins can email the generated welcome credentials when creating an agent', function (): void {
    config([
        'app.name' => 'Wayfindr',
        'app.url' => 'https://wayfindr.example.test',
    ]);

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    Mail::fake();

    $response = $this->actingAs($owner)
        ->from('/dashboard/account')
        ->post('/dashboard/account/agents', [
            'name' => 'Bea Builder',
            'email' => 'Bea@Example.test',
            'send_welcome_email' => '1',
        ]);

    $response
        ->assertRedirect('/dashboard/account')
        ->assertSessionHas('status', 'Agent created and welcome email sent.')
        ->assertSessionHas('created_agent_email', 'bea@example.test')
        ->assertSessionHas('created_agent_password');

    $createdAgent = User::query()->where('email', 'bea@example.test')->firstOrFail();
    $temporaryPassword = $response->getSession()->get('created_agent_password');
    $auditEvent = AuditEvent::query()
        ->where('action', 'agent.created')
        ->firstOrFail();

    Mail::assertSent(AgentWelcomeMessage::class, function (AgentWelcomeMessage $mail) use ($createdAgent, $temporaryPassword): bool {
        return $mail->hasTo($createdAgent->email)
            && $mail->envelope()->subject === 'Welcome to Wayfindr'
            && $mail->accountName === 'Acme Support'
            && $mail->agentName === 'Bea Builder'
            && $mail->agentEmail === 'bea@example.test'
            && $mail->temporaryPassword === $temporaryPassword
            && str_ends_with($mail->loginUrl, '/login');
    });

    expect($createdAgent->account_id)->toBe($account->id)
        ->and($createdAgent->account_role)->toBe(AccountRole::Agent)
        ->and(Hash::check($temporaryPassword, $createdAgent->password))->toBeTrue()
        ->and($auditEvent->subject->is($createdAgent))->toBeTrue()
        ->and($auditEvent->metadata['welcome_email_requested'])->toBeTrue()
        ->and($auditEvent->metadata['welcome_email_sent'])->toBeTrue();
});

test('agent creation keeps the temporary password fallback when welcome email delivery fails', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);

    Mail::shouldReceive('to')
        ->once()
        ->with('bea@example.test')
        ->andReturn(new class
        {
            public function send(AgentWelcomeMessage $message): never
            {
                throw new RuntimeException('SMTP misfire.');
            }
        });

    $response = $this->actingAs($owner)
        ->from('/dashboard/account')
        ->post('/dashboard/account/agents', [
            'name' => 'Bea Builder',
            'email' => 'bea@example.test',
            'send_welcome_email' => '1',
        ]);

    $response
        ->assertRedirect('/dashboard/account')
        ->assertSessionHas('status', 'Agent created, but the welcome email could not be sent. Share the temporary password securely.')
        ->assertSessionHas('created_agent_email', 'bea@example.test')
        ->assertSessionHas('created_agent_password');

    $createdAgent = User::query()->where('email', 'bea@example.test')->firstOrFail();
    $auditEvent = AuditEvent::query()
        ->where('action', 'agent.created')
        ->firstOrFail();

    expect($createdAgent->account_id)->toBe($account->id)
        ->and($auditEvent->subject->is($createdAgent))->toBeTrue()
        ->and($auditEvent->metadata['welcome_email_requested'])->toBeTrue()
        ->and($auditEvent->metadata['welcome_email_sent'])->toBeFalse();
});

test('agents cannot create account agents', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);

    $this->actingAs($agent)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertDontSee('Add agent')
        ->assertDontSee('/dashboard/account/agents', false);

    $this->actingAs($agent)
        ->from('/dashboard/account')
        ->post('/dashboard/account/agents', [
            'name' => 'Bea Builder',
            'email' => 'bea@example.test',
        ])
        ->assertForbidden();

    expect(User::query()->where('email', 'bea@example.test')->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'agent.created')->exists())->toBeFalse();
});

test('account agent creation rejects duplicate emails without updating an existing user', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $existingAgent = User::factory()->for($account)->create([
        'email' => 'bea@example.test',
        'name' => 'Existing Bea',
        'account_role' => AccountRole::Admin,
    ]);

    $this->actingAs($owner)
        ->from('/dashboard/account')
        ->post('/dashboard/account/agents', [
            'name' => 'Bea Builder',
            'email' => 'bea@example.test',
        ])
        ->assertRedirect('/dashboard/account')
        ->assertSessionHasErrors('email');

    expect($existingAgent->fresh()->name)->toBe('Existing Bea')
        ->and($existingAgent->fresh()->account_role)->toBe(AccountRole::Admin)
        ->and(User::query()->where('email', 'bea@example.test')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'agent.created')->exists())->toBeFalse();
});

test('account agent creation rejects duplicate emails after normalizing casing', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    User::factory()->for($account)->create(['email' => 'bea@example.test']);

    $this->actingAs($owner)
        ->from('/dashboard/account')
        ->post('/dashboard/account/agents', [
            'name' => 'Bea Builder',
            'email' => 'BEA@example.test',
        ])
        ->assertRedirect('/dashboard/account')
        ->assertSessionHasErrors('email');

    expect(User::query()->where('email', 'bea@example.test')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'agent.created')->exists())->toBeFalse();
});

test('account agent creation rejects emails already used by another account', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    User::factory()->for($otherAccount)->create(['email' => 'bea@example.test']);

    $this->actingAs($owner)
        ->from('/dashboard/account')
        ->post('/dashboard/account/agents', [
            'name' => 'Bea Builder',
            'email' => 'bea@example.test',
        ])
        ->assertRedirect('/dashboard/account')
        ->assertSessionHasErrors('email');

    expect(User::query()->where('email', 'bea@example.test')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'agent.created')->exists())->toBeFalse();
});
