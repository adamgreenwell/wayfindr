<?php

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Minishlink\WebPush\VAPID;

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

test('agent profile password form includes a hidden username for browser tooling', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'email' => 'ada@example.test',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertOk()
        ->assertSee('name="username"', false)
        ->assertSee('value="ada@example.test"', false)
        ->assertSee('autocomplete="username"', false)
        ->assertSee('hidden', false);
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
        ->assertSee('Email alerts')
        ->assertSee('Play a sound for new dashboard alerts');

    $this->actingAs($agent)
        ->from('/dashboard/profile')
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => 'assigned',
            'email_alerts' => '1',
            'sound_alerts' => '1',
        ])
        ->assertRedirect('/dashboard/profile')
        ->assertSessionHas('status', 'profile.flash.alerts_updated');

    expect($agent->fresh()->alert_preferences)->toMatchArray([
        'mode' => 'assigned',
        'email' => true,
        'sound' => true,
    ]);
});

test('agent can turn the optional dashboard alert sound off', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'sound' => true,
        ],
    ]);

    expect($agent->alertSoundEnabled())->toBeTrue();

    $this->actingAs($agent)
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => User::ALERT_MODE_ALL,
            'email_alerts' => '1',
        ])
        ->assertRedirect('/dashboard/profile');

    expect($agent->fresh()->alert_preferences)->toMatchArray([
        'mode' => User::ALERT_MODE_ALL,
        'email' => true,
        'sound' => false,
    ]);
});

test('an agent can save their closed-dashboard alert preference', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'push' => false,
        ],
    ]);

    $this->actingAs($agent)
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => User::ALERT_MODE_ALL,
            'push_alerts' => '1',
        ])
        ->assertRedirect('/dashboard/profile');

    expect($agent->fresh()->alert_preferences)->toMatchArray([
        'mode' => User::ALERT_MODE_ALL,
        'push' => true,
    ]);
});

test('the profile exposes only a ready public VAPID key to the agent browser', function (): void {
    $keys = VAPID::createVapidKeys();
    config()->set('webpush.vapid', [
        'subject' => 'mailto:alerts@example.test',
        'public_key' => $keys['publicKey'],
        'private_key' => $keys['privateKey'],
        'pem_file' => null,
    ]);
    $agent = User::factory()->for(Account::factory())->create();

    $response = $this->actingAs($agent)->get('/dashboard/profile');

    $response
        ->assertOk()
        ->assertSee('Notify this browser after I close the dashboard')
        ->assertSee('data-agent-push-subscription', false)
        ->assertSee($keys['publicKey'])
        ->assertDontSee($keys['privateKey']);

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.(string) $response->getContent());
    $checkbox = (new DOMXPath($document))->query('//input[@id="push_alerts"]')->item(0);

    expect($checkbox)->toBeInstanceOf(DOMElement::class)
        ->and($checkbox->getAttribute('name'))->toBe('push_alerts')
        ->and($checkbox->hasAttribute('disabled'))->toBeTrue();
});

test('the profile cleans up an owned browser subscription after a VAPID key rotation', function (): void {
    $source = file_get_contents(resource_path('views/components/agent-push-subscription.blade.php'));
    $cleanup = Str::before(
        Str::after($source, 'function cleanStaleSubscription(subscription, removeStored) {'),
        'function initializeBrowserState()',
    );

    expect($cleanup)
        ->toContain('pendingRemoval(subscription.endpoint);')
        ->toContain("request(config.destroyEndpoint, 'DELETE'")
        ->toContain('subscription.unsubscribe()');

    expect($source)
        ->toContain("payload.status === 'foreign'")
        ->toContain('cleanStaleSubscription(subscription, false)')
        ->toContain('! usesCurrentApplicationServerKey(subscription)')
        ->toContain("cleanStaleSubscription(subscription, payload.status === 'owned')");
});

test("browser-specific push opt-out keeps the agent's other subscribed browsers active", function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'push' => true,
        ],
    ]);

    foreach (['one', 'two'] as $suffix) {
        $agent->pushSubscriptions()->create([
            'endpoint' => "https://push.example.test/subscriptions/{$suffix}",
            'public_key' => 'public-key',
            'auth_token' => 'auth-token',
            'content_encoding' => 'aes128gcm',
        ]);
    }

    // Saving unrelated alert preferences from a browser with no subscription
    // must not turn off delivery to the two browsers that are subscribed.
    $this->actingAs($agent)
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => User::ALERT_MODE_ALL,
            'email_alerts' => '1',
        ])
        ->assertRedirect('/dashboard/profile');

    expect($agent->fresh()->alertPushEnabled())->toBeTrue()
        ->and($agent->pushSubscriptions()->count())->toBe(2);

    $this->actingAs($agent)
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => User::ALERT_MODE_ALL,
            'push_subscription_endpoint' => 'https://push.example.test/subscriptions/one',
        ])
        ->assertRedirect('/dashboard/profile');

    expect($agent->fresh()->alertPushEnabled())->toBeTrue()
        ->and($agent->pushSubscriptions()->pluck('endpoint')->all())
        ->toBe(['https://push.example.test/subscriptions/two']);

    $this->actingAs($agent)
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => User::ALERT_MODE_ALL,
            'push_subscription_endpoint' => 'https://push.example.test/subscriptions/two',
        ])
        ->assertRedirect('/dashboard/profile');

    expect($agent->fresh()->alertPushEnabled())->toBeFalse()
        ->and($agent->pushSubscriptions()->count())->toBe(0);
});

test('the profile preserves push preference while VAPID configuration is unavailable', function (): void {
    config()->set('webpush.vapid', [
        'subject' => null,
        'public_key' => null,
        'private_key' => null,
        'pem_file' => null,
    ]);
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => ['mode' => User::ALERT_MODE_ALL, 'push' => true],
    ]);

    $response = $this->actingAs($agent)->get('/dashboard/profile');

    $response
        ->assertOk()
        ->assertSee('A platform operator must configure Web Push before browsers can subscribe.')
        ->assertSee('name="push_alerts" value="1"', false)
        ->assertDontSee('data-agent-push-subscription', false);

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.(string) $response->getContent());
    $checkbox = (new DOMXPath($document))->query('//input[@id="push_alerts"]')->item(0);

    expect($checkbox)->toBeInstanceOf(DOMElement::class)
        ->and($checkbox->hasAttribute('name'))->toBeFalse()
        ->and($checkbox->hasAttribute('disabled'))->toBeTrue();
});

test('alert preference changes lock the account before the agent', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL exposes the row-lock clause used by this concurrency contract.');
    }

    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => ['mode' => User::ALERT_MODE_ALL],
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($agent)
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => User::ALERT_MODE_ASSIGNED,
            'email_alerts' => '1',
        ])
        ->assertRedirect('/dashboard/profile');

    $queries = collect(DB::getQueryLog())->pluck('query')->values();
    DB::disableQueryLog();
    $accountLock = $queries->search(fn (string $query): bool => str_contains($query, 'from "accounts"')
        && str_contains($query, 'for update'));
    $agentLock = $queries->search(fn (string $query): bool => str_contains($query, 'from "users"')
        && str_contains($query, 'for update'));

    expect($accountLock)->toBeInt()
        ->and($agentLock)->toBeInt()
        ->and($accountLock)->toBeLessThan($agentLock);
});

test('agent profile explains calm alert preference controls', function (): void {
    $agent = User::factory()->for(Account::factory())->create();

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertOk()
        ->assertSee('How alerts behave')
        ->assertSee('Dashboard alerts are the source of truth for support work that needs attention.')
        ->assertSee('Email alerts are optional delivery, not a separate queue.')
        ->assertSee('Quiet mode pauses new alerts without changing assignments, site access, or support responsibility.');
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
        ->assertSessionHas('status', 'profile.flash.alerts_updated');

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

test('agent profile summarizes personal alert readiness', function (): void {
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.from.address' => 'support@example.test',
    ]);

    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => [
            'mode' => 'assigned',
            'email' => true,
            'cadence' => 'digest',
            'digest_delivery' => [
                'status' => 'queued',
                'candidate_count' => 3,
                'last_attempted_at' => now()->subMinutes(11)->toISOString(),
            ],
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertOk()
        ->assertSee('Alert readiness')
        ->assertSee('Dashboard alerts')
        ->assertSee('Listening')
        ->assertSee('You will receive dashboard alerts for eligible support work.')
        ->assertSee('Alert scope')
        ->assertSee('Assigned to me')
        ->assertSee('Only conversations and tickets assigned to you create new alerts.')
        ->assertSee('Email delivery')
        ->assertSee('Ready')
        ->assertSee('Email alerts are enabled and outbound mail looks configured.')
        ->assertSee('Cadence')
        ->assertSee('Digest')
        ->assertSee('Digest delivery is preferred. Latest digest: Queued digest email 11 minutes ago.');
});

test('agent profile explains quiet dashboard-only alert readiness', function (): void {
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.from.address' => 'support@example.test',
    ]);

    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => [
            'mode' => 'quiet',
            'email' => false,
            'cadence' => 'immediate',
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertOk()
        ->assertSee('Alert readiness')
        ->assertSee('Paused')
        ->assertSee('Quiet mode suppresses new dashboard and email alerts.')
        ->assertSee('Email delivery')
        ->assertSee('Dashboard only')
        ->assertSee('Email alerts are off for your profile.')
        ->assertSee('Cadence')
        ->assertSee('Immediate')
        ->assertSee('New eligible alerts can notify immediately when email alerts are enabled.');
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
        ->assertSessionHas('status', 'profile.flash.alerts_updated');

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
        ->assertSessionHas('status', 'profile.flash.profile_updated');

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
        ->assertSessionHas('status', 'profile.flash.password_updated');

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

test('a confirmation reaches the page as a sentence, not as a catalogue key', function (): void {
    // The flash travels as a key so it can be translated in the request that
    // shows it (see docs/product/dashboard-language.md). That trade is only
    // sound if the view actually translates it -- if it ever stops, every
    // confirmation on this page silently becomes `profile.flash.something`,
    // and the tests above would still pass because the session is right.
    $agent = User::factory()->for(Account::factory())->create([
        'password' => Hash::make('old-password'),
    ]);

    $submissions = [
        ['/dashboard/profile', ['name' => 'Ada Lovelace'], 'Profile updated.'],
        ['/dashboard/profile/alerts', ['alert_mode' => User::ALERT_MODE_ALL], 'Alert preferences updated.'],
        ['/dashboard/profile/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ], 'Password updated.'],
    ];

    foreach ($submissions as [$route, $payload, $expected]) {
        $this->actingAs($agent->fresh())
            ->from('/dashboard/profile')
            ->followingRedirects()
            ->put($route, $payload)
            ->assertOk()
            ->assertSee($expected)
            ->assertDontSee('profile.flash');
    }
});
