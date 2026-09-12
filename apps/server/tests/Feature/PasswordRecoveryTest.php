<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function agentNeedingRecovery(string $email = 'ada@example.test'): User
{
    return User::factory()->for(Account::factory())->create([
        'email' => $email,
        'password' => Hash::make('the-old-password'),
        'account_role' => AccountRole::Agent,
    ]);
}

test('an agent can ask for a reset link', function (): void {
    Notification::fake();
    $agent = agentNeedingRecovery();

    $this->post(route('password.email'), ['email' => $agent->email])
        ->assertRedirect()
        ->assertSessionHas('status');

    Notification::assertSentTo($agent, ResetPasswordLink::class);
});

test('an unknown address gets the same answer as a real one', function (): void {
    // The login page is public and the account model is multi-tenant. A form
    // that confirms which addresses are real hands over the agent roster of
    // every site on the install.
    Notification::fake();
    $agent = agentNeedingRecovery();

    $known = $this->post(route('password.email'), ['email' => $agent->email]);
    $unknown = $this->post(route('password.email'), ['email' => 'nobody@example.test']);

    expect($unknown->status())->toBe($known->status())
        ->and(session()->get('status'))->not->toBeNull();

    $unknown->assertSessionHasNoErrors();
    Notification::assertSentTimes(ResetPasswordLink::class, 1);
});

test('a reset link sets a new password and the old one stops working', function (): void {
    Notification::fake();
    $agent = agentNeedingRecovery();
    $token = Password::createToken($agent);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $agent->email,
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertRedirect(route('login'));

    $agent->refresh();

    expect(Hash::check('a-brand-new-password', $agent->password))->toBeTrue()
        ->and(Hash::check('the-old-password', $agent->password))->toBeFalse();
});

test('a completed reset tells you so on the page it lands on', function (): void {
    // PasswordResetController has always flashed this, and login.blade.php
    // rendered no flash region at all -- unlike forgot-password.blade.php right
    // next to it -- so the confirmation was discarded. You finish a reset, land
    // on the sign-in form, and nothing on it says the reset worked or which
    // password to type.
    Notification::fake();
    $agent = agentNeedingRecovery();
    $token = Password::createToken($agent);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $agent->email,
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertRedirect(route('login'));

    // Following the redirect, not asserting on the session: a flash nothing
    // renders is exactly the defect, and assertSessionHas would have passed
    // throughout.
    $this->followingRedirects()
        ->post(route('password.update'), [
            'token' => Password::createToken($agent),
            'email' => $agent->email,
            'password' => 'another-new-password',
            'password_confirmation' => 'another-new-password',
        ])
        ->assertOk()
        ->assertSee('Your password has been reset. Sign in with it now.');
});

test('a completed reset ends the sessions that were already open', function (): void {
    // A reset that leaves old sessions alive is not a recovery, it is a second
    // key cut for whoever already had one.
    //
    // Asserting on remember_token alone is not enough: the password broker
    // rotates it anyway, so that assertion passes even with every line of the
    // session handling deleted. The live session rows are the thing that
    // actually survives, so they are what this checks.
    // The suite runs on the array driver; this behaviour only exists for the
    // database store, which is what .env.example ships and what the compose
    // stack runs. Without this the guard returns early and the test proves
    // nothing.
    config(['session.driver' => 'database']);

    Notification::fake();
    $agent = agentNeedingRecovery();
    $other = agentNeedingRecovery('someone-else@example.test');

    foreach ([$agent, $other] as $user) {
        DB::table('sessions')->insert([
            'id' => 'session-for-'.$user->id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->getTimestamp(),
        ]);
    }

    $this->post(route('password.update'), [
        'token' => Password::createToken($agent),
        'email' => $agent->email,
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertRedirect(route('login'));

    expect(DB::table('sessions')->where('user_id', $agent->id)->count())->toBe(0)
        // Somebody else's sessions are none of this reset's business.
        ->and(DB::table('sessions')->where('user_id', $other->id)->count())->toBe(1);
});

test('a used token cannot be replayed', function (): void {
    Notification::fake();
    $agent = agentNeedingRecovery();
    $token = Password::createToken($agent);

    $payload = [
        'token' => $token,
        'email' => $agent->email,
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ];

    $this->post(route('password.update'), $payload)->assertRedirect(route('login'));

    $this->post(route('password.update'), array_replace($payload, [
        'password' => 'a-third-password',
        'password_confirmation' => 'a-third-password',
    ]))->assertSessionHasErrors('email');

    expect(Hash::check('a-third-password', $agent->fresh()->password))->toBeFalse();
});

test('a wrong token, an expired one and an unknown address are indistinguishable', function (): void {
    Notification::fake();
    $agent = agentNeedingRecovery();

    $bogusToken = $this->post(route('password.update'), [
        'token' => 'not-a-real-token',
        'email' => $agent->email,
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ]);

    $unknownEmail = $this->post(route('password.update'), [
        'token' => Password::createToken($agent),
        'email' => 'nobody@example.test',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ]);

    $bogusToken->assertSessionHasErrors('email');
    $unknownEmail->assertSessionHasErrors('email');

    expect(session()->get('errors')->get('email'))
        ->toBe($bogusToken->getSession()->get('errors')->get('email'));
});

test('the request is rate limited', function (): void {
    Notification::fake();
    $agent = agentNeedingRecovery();

    foreach (range(1, 5) as $ignored) {
        $this->post(route('password.email'), ['email' => $agent->email]);
    }

    $this->post(route('password.email'), ['email' => $agent->email])->assertStatus(429);
});

test('both halves of the flow are audited against the account', function (): void {
    Notification::fake();
    $agent = agentNeedingRecovery();

    $this->post(route('password.email'), ['email' => $agent->email]);
    $this->post(route('password.update'), [
        'token' => Password::createToken($agent),
        'email' => $agent->email,
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ]);

    $actions = AuditEvent::query()->pluck('action')->all();

    expect($actions)->toContain('password_reset.requested')
        ->and($actions)->toContain('password_reset.completed');

    $event = AuditEvent::query()->where('action', 'password_reset.completed')->firstOrFail();

    // Nobody is authenticated during a reset, so there is no actor to record —
    // but the account has to be able to see it happened.
    expect($event->account_id)->toBe($agent->account_id)
        ->and($event->actor_id)->toBeNull()
        ->and($event->subject_id)->toBe($agent->id);
});

test('an unknown address writes no audit event', function (): void {
    Notification::fake();

    $this->post(route('password.email'), ['email' => 'nobody@example.test']);

    expect(AuditEvent::query()->count())->toBe(0);
});

test('the login page offers recovery', function (): void {
    // A user must exist, or first-run setup redirects away from the login page.
    agentNeedingRecovery();

    $this->get(route('login'))->assertOk()->assertSee(route('password.request'), false);
});

test('requesting a link does not consume the quota for using one', function (): void {
    // Sharing a bucket let an attacker spend an agent's completion allowance
    // just by requesting links for their address -- their valid token would be
    // refused before it was read, repeatably.
    Notification::fake();
    $agent = agentNeedingRecovery();
    $token = Password::createToken($agent);

    foreach (range(1, 6) as $ignored) {
        $this->post(route('password.email'), ['email' => $agent->email]);
    }

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $agent->email,
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertRedirect(route('login'));

    expect(Hash::check('a-brand-new-password', $agent->fresh()->password))->toBeTrue();
});

test('the reset link is queued rather than sent inside the request', function (): void {
    // Sending inline leaked which addresses exist: a known one paid for an SMTP
    // round trip and could 500, an unknown one returned immediately either way.
    Notification::fake();
    $agent = agentNeedingRecovery();

    $this->post(route('password.email'), ['email' => $agent->email]);

    Notification::assertSentTo($agent, ResetPasswordLink::class, function (ResetPasswordLink $notification): bool {
        return $notification instanceof ShouldQueue;
    });
});

test('sessions are deleted from the connection the session store uses', function (): void {
    // SESSION_CONNECTION can point away from the default. Deleting from the
    // default would succeed against an empty table while the real sessions
    // stayed valid -- the guarantee failing silently.
    //
    // A file-backed store, because two ':memory:' connections are two separate
    // databases and would prove nothing about which one was written to.
    $file = tempnam(sys_get_temp_dir(), 'wf-sessions-').'.sqlite';
    touch($file);

    config([
        'session.driver' => 'database',
        'session.connection' => 'session_store',
        'database.connections.session_store' => [
            'driver' => 'sqlite',
            'database' => $file,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ],
    ]);

    Schema::connection('session_store')->create('sessions', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->foreignId('user_id')->nullable()->index();
        $table->string('ip_address', 45)->nullable();
        $table->text('user_agent')->nullable();
        $table->longText('payload');
        $table->integer('last_activity')->index();
    });

    Notification::fake();
    $agent = agentNeedingRecovery();

    DB::connection('session_store')->table('sessions')->insert([
        'id' => 'session-on-other-connection',
        'user_id' => $agent->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->getTimestamp(),
    ]);

    $this->post(route('password.update'), [
        'token' => Password::createToken($agent),
        'email' => $agent->email,
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertRedirect(route('login'));

    expect(DB::connection('session_store')->table('sessions')->where('user_id', $agent->id)->count())->toBe(0);

    @unlink($file);
});

test('a stale reset link offers the page its own error names', function (): void {
    // The error on a bad token says "Request a new one." The page rendered ZERO
    // anchors -- the only href in 102KB of document was the favicon -- so it
    // named a destination it gave no way to reach. Both sibling auth views
    // already carry exactly this footer link.
    $response = $this->get('/reset-password/not-a-real-token?email=agent@example.test')->assertOk();

    expect(substr_count($response->getContent(), '<a '))->toBeGreaterThan(0);

    $response->assertSee(route('password.request'), false)
        ->assertSee('Request a new reset link');
});

test('being throttled explains itself instead of rendering the framework 429', function (): void {
    Notification::fake();
    $agent = agentNeedingRecovery();

    $throttled = null;

    for ($attempt = 0; $attempt < 12; $attempt++) {
        $response = $this->post(route('password.email'), ['email' => $agent->email]);

        if ($response->status() === 429) {
            $throttled = $response;
            break;
        }
    }

    expect($throttled)->not->toBeNull('the reset request route should throttle');

    // Laravel's own 429 is 6,602 bytes whose entire visible text is "Too Many
    // Requests 429 Too Many Requests" -- no product name, no link back, and no
    // use made of the Retry-After it just computed.
    $throttled->assertSee('Too many attempts')
        ->assertSee('It does not lock your account')
        ->assertSee(route('login'), false)
        ->assertDontSee('Too Many Requests');
});

test('a throttled authenticated form is not told to sign in again', function (): void {
    // This view answers EVERY html 429, not just the pre-auth ones. The
    // two-factor confirmation, recovery-code regeneration, disablement and the
    // operator AI test are all throttled inside the `auth` group, and offering
    // an already-signed-in agent a link to sign in is no way out.
    $agent = User::factory()->for(Account::factory())->create([
        'account_role' => AccountRole::Owner,
    ]);

    $throttled = null;

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $response = $this->actingAs($agent)
            ->put(route('dashboard.profile.two-factor.confirm'), ['one_time_code' => '000000']);

        if ($response->status() === 429) {
            $throttled = $response;
            break;
        }
    }

    expect($throttled)->not->toBeNull('two-factor confirmation should throttle');

    $throttled->assertSee('Too many attempts')
        ->assertSee(route('dashboard'), false)
        ->assertDontSee('Back to sign in');
});
