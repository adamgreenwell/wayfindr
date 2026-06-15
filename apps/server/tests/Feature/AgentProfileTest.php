<?php

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('guest is redirected from the agent profile to login', function (): void {
    $this->get('/dashboard/profile')
        ->assertRedirect('/login');
});

test('agent profile routes require an account', function (): void {
    $agent = User::factory()->create([
        'account_id' => null,
        'name' => 'Detached Agent',
        'password' => Hash::make('old-password'),
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertForbidden();

    $this->actingAs($agent)
        ->put('/dashboard/profile', [
            'name' => 'Updated Agent',
        ])
        ->assertForbidden();

    $this->actingAs($agent)
        ->put('/dashboard/profile/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertForbidden();

    expect($agent->fresh()->name)->toBe('Detached Agent')
        ->and(Hash::check('old-password', $agent->fresh()->password))->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'agent.password_updated')->exists())->toBeFalse();
});

test('agent can view their profile from the application shell', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'name' => 'Ada Agent',
        'email' => 'ada@example.test',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('/dashboard/profile', false);

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertOk()
        ->assertSee('Agent profile')
        ->assertSee('Ada Agent')
        ->assertSee('ada@example.test')
        ->assertSee('Change password')
        ->assertSee('/dashboard/profile/password', false);
});

test('agent can update their alert preference mode', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => ['mode' => 'all'],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertOk()
        ->assertSee('Alert preferences')
        ->assertSee('/dashboard/profile/alerts', false)
        ->assertSee('All site alerts I can support')
        ->assertSee('Only conversations and tickets assigned to me')
        ->assertSee('Quiet mode')
        ->assertSee('Email alerts');

    $this->actingAs($agent)
        ->from('/dashboard/profile')
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => 'assigned',
            'email_alerts' => '1',
        ])
        ->assertRedirect('/dashboard/profile')
        ->assertSessionHas('status', 'Alert preferences updated.');

    expect($agent->fresh()->alert_preferences)->toMatchArray([
        'mode' => 'assigned',
        'email' => true,
    ]);
});

test('agent alert cadence defaults to immediate delivery', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => null,
    ]);

    expect($agent->alertCadence())->toBe('immediate');
});

test('agent can choose their email alert delivery cadence', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => [
            'mode' => 'all',
            'email' => true,
            'cadence' => 'immediate',
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertOk()
        ->assertSee('Email cadence')
        ->assertSee('Send email alerts as they happen')
        ->assertSee('Prefer digest delivery when available')
        ->assertSee('Digest delivery bundles eligible email alerts when the scheduler runs.')
        ->assertDontSee('Digest delivery is planned.');

    $this->actingAs($agent)
        ->from('/dashboard/profile')
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => 'all',
            'email_alerts' => '1',
            'alert_cadence' => 'digest',
        ])
        ->assertRedirect('/dashboard/profile')
        ->assertSessionHas('status', 'Alert preferences updated.');

    expect($agent->fresh()->alert_preferences)->toMatchArray([
        'mode' => 'all',
        'email' => true,
        'cadence' => 'digest',
    ]);
});

test('agent profile shows the latest alert digest delivery status', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => [
            'mode' => 'all',
            'email' => true,
            'cadence' => 'digest',
            'digest_delivery' => [
                'status' => 'queued',
                'candidate_count' => 2,
                'message' => 'Queued digest email with 2 alerts.',
                'error' => 'SMTP cratered',
                'last_attempted_at' => now()->subMinutes(7)->toISOString(),
            ],
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertOk()
        ->assertSee('Last digest')
        ->assertSee('Queued digest email')
        ->assertSee('Queued digest email with 2 alerts.')
        ->assertSee('7 minutes ago')
        ->assertDontSee('Last error')
        ->assertDontSee('SMTP cratered');
});

test('agent profile flags email alerts when mail delivery needs attention', function (): void {
    config([
        'mail.default' => 'log',
        'mail.from.address' => 'support@example.test',
    ]);

    $agent = User::factory()->for(Account::factory())->create();

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertOk()
        ->assertSee('Email delivery needs attention')
        ->assertSee('MAIL_MAILER is log.')
        ->assertSee('Configure smtp, ses, postmark, resend, or another real outbound mail transport before relying on email alerts.');
});

test('agent profile confirms email alerts when mail delivery is ready', function (): void {
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.from.address' => 'support@example.test',
    ]);

    $agent = User::factory()->for(Account::factory())->create();

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertOk()
        ->assertSee('Email delivery ready')
        ->assertSee('MAIL_MAILER is smtp.')
        ->assertSee('php artisan wayfindr:mail-test --to=you@example.com');
});

test('agent alert preference mode must be supported', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => ['mode' => 'all', 'cadence' => 'immediate'],
    ]);

    $this->actingAs($agent)
        ->from('/dashboard/profile')
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => 'party-horn',
        ])
        ->assertRedirect('/dashboard/profile')
        ->assertSessionHasErrors('alert_mode');

    expect($agent->fresh()->alert_preferences)->toMatchArray([
        'mode' => 'all',
        'cadence' => 'immediate',
    ]);
});

test('agent alert cadence must be supported', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => ['mode' => 'all', 'cadence' => 'immediate'],
    ]);

    $this->actingAs($agent)
        ->from('/dashboard/profile')
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => 'all',
            'alert_cadence' => 'every-seven-seconds',
        ])
        ->assertRedirect('/dashboard/profile')
        ->assertSessionHasErrors('alert_cadence');

    expect($agent->fresh()->alert_preferences)->toMatchArray([
        'mode' => 'all',
        'cadence' => 'immediate',
    ]);
});

test('agent can disable email alert delivery while keeping dashboard alerts', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => [
            'mode' => 'all',
            'email' => true,
            'cadence' => 'digest',
        ],
    ]);

    $this->actingAs($agent)
        ->from('/dashboard/profile')
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => 'all',
        ])
        ->assertRedirect('/dashboard/profile')
        ->assertSessionHas('status', 'Alert preferences updated.');

    expect($agent->fresh()->alert_preferences)->toMatchArray([
        'mode' => 'all',
        'email' => false,
        'cadence' => 'digest',
    ]);
});

test('agent can update their display name', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'name' => 'Ada Agent',
    ]);

    $this->actingAs($agent)
        ->from('/dashboard/profile')
        ->put('/dashboard/profile', [
            'name' => 'Ada Lovelace',
        ])
        ->assertRedirect('/dashboard/profile')
        ->assertSessionHas('status', 'Profile updated.');

    expect($agent->fresh()->name)->toBe('Ada Lovelace');
});

test('agent can change their password with the current password', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'password' => Hash::make('old-password'),
    ]);

    $this->actingAs($agent)
        ->from('/dashboard/profile')
        ->put('/dashboard/profile/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertRedirect('/dashboard/profile')
        ->assertSessionHas('status', 'Password updated.');

    $auditEvent = AuditEvent::query()
        ->where('action', 'agent.password_updated')
        ->firstOrFail();

    expect(Hash::check('new-password', $agent->fresh()->password))->toBeTrue()
        ->and($auditEvent->account_id)->toBe($agent->account_id)
        ->and($auditEvent->actor->is($agent))->toBeTrue()
        ->and($auditEvent->subject->is($agent))->toBeTrue();
});

test('agent cannot change their password with the wrong current password', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'password' => Hash::make('old-password'),
    ]);

    $this->actingAs($agent)
        ->from('/dashboard/profile')
        ->put('/dashboard/profile/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertRedirect('/dashboard/profile')
        ->assertSessionHasErrors('current_password');

    expect(Hash::check('old-password', $agent->fresh()->password))->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'agent.password_updated')->exists())->toBeFalse();
});
