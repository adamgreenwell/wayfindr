<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

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

    Notification::assertSentTo($agent, ResetPassword::class);
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
    Notification::assertSentTimes(ResetPassword::class, 1);
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
