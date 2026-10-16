<?php

use App\Enums\AccountRole;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

function fragmentResetOwner(): User
{
    return User::factory()->create([
        'email' => 'owner+support@example.test', 'password' => Hash::make('original-password'),
        'account_role' => AccountRole::Owner, 'platform_role' => null,
    ]);
}

function fragmentResetPayload(User $owner, string $token): array
{
    return ['token' => $token, 'email' => $owner->email, 'password' => 'customer-chosen-password',
        'password_confirmation' => 'customer-chosen-password'];
}

test('the fixed landing renders no URL credential or old input and starts disabled', function (): void {
    $token = str_repeat('a', 64);
    $response = $this->withSession(['_old_input' => ['token' => $token, 'email' => 'old@example.test']])
        ->get(route('password.reset.fragment').'?token='.$token.'&email=query@example.test')
        ->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Pragma', 'no-cache')
        ->assertDontSee($token)->assertDontSee('query@example.test')->assertDontSee('old@example.test');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    expect($xpath->query('//form[@data-password-reset-fragment]')->length)->toBe(1)
        ->and($xpath->query('//input[@name="token"]')->length)->toBe(0)
        ->and($xpath->query('//input[@name="_token"]')->length)->toBe(1)
        ->and($xpath->query('//input[@type="password"][@disabled]')->length)->toBe(2)
        ->and($xpath->query('//button[@type="submit"][@disabled]')->length)->toBe(1)
        ->and($xpath->query('//script[@src="/js/password-reset-fragment.js"]')->length)->toBe(1);
});

test('legacy reset links still render the normal form without a fragment script', function (): void {
    $token = str_repeat('b', 64);
    $this->get(route('password.reset', ['token' => $token, 'email' => 'owner@example.test']))
        ->assertOk()->assertSee('value="'.$token.'"', false)
        ->assertDontSee('password-reset-fragment.js')->assertHeader('Referrer-Policy', 'no-referrer');
});

test('an authenticated browser renders the clearing landing without redirect or reset authority', function (): void {
    $owner = fragmentResetOwner();
    $token = Password::createToken($owner);
    $this->actingAs($owner)->get(route('password.reset.fragment'))->assertOk()
        ->assertHeaderMissing('Location')->assertSee('data-reset-authenticated', false)
        ->assertSee('/js/password-reset-fragment.js', false)->assertDontSee($token);
    $this->postJson(route('password.update'), fragmentResetPayload($owner, $token))->assertRedirect();
    expect(Password::tokenExists($owner, $token))->toBeTrue()
        ->and(Hash::check('original-password', $owner->fresh()->password))->toBeTrue();
});

test('the JSON completion uses the normal broker and revokes only the owners sessions', function (): void {
    Notification::fake();
    config(['session.driver' => 'database']);
    $owner = fragmentResetOwner();
    $other = User::factory()->create();
    foreach ([$owner, $other] as $user) {
        DB::table('sessions')->insert(['id' => 'existing-'.$user->id, 'user_id' => $user->id,
            'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => base64_encode(serialize([])),
            'last_activity' => now()->getTimestamp()]);
    }
    $token = Password::createToken($owner);
    $this->postJson(route('password.update'), fragmentResetPayload($owner, $token))
        ->assertOk()->assertExactJson(['status' => 'password_reset'])
        ->assertHeaderMissing('Location')->assertHeader('Referrer-Policy', 'no-referrer');
    expect(Hash::check('customer-chosen-password', $owner->fresh()->password))->toBeTrue()
        ->and(Password::tokenExists($owner, $token))->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $owner->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $other->id)->count())->toBe(1)
        ->and($owner->fresh()->platform_role)->toBeNull();
    $this->assertGuest();
    Notification::assertNothingSent();
});

test('JSON expired wrong unknown and consumed claims have the same private refusal', function (string $case): void {
    $owner = fragmentResetOwner();
    $token = Password::createToken($owner);
    $payload = fragmentResetPayload($owner, $token);
    if ($case === 'expired') {
        $this->travel((int) config('auth.passwords.users.expire') + 1)->minutes();
    } elseif ($case === 'wrong') {
        $payload['token'] = str_repeat('c', 64);
    } elseif ($case === 'unknown') {
        $payload['email'] = 'nobody@example.test';
    } else {
        $this->postJson(route('password.update'), $payload)->assertOk();
        $payload['password'] = $payload['password_confirmation'] = 'a-different-new-password';
    }
    $response = $this->postJson(route('password.update'), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('email')
        ->assertHeaderMissing('Location')->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertDontSee($token)->assertDontSee($payload['password']);
    expect($response->json('errors.email'))->toBe(['That reset link is no longer valid. Request a new one.'])
        ->and(Hash::check('a-different-new-password', $owner->fresh()->password))->toBeFalse()
        ->and(session('_old_input.token'))->toBeNull();
})->with(['expired', 'wrong', 'unknown', 'consumed']);

test('JSON validation allows deliberate correction without token rotation or input flashing', function (): void {
    $owner = fragmentResetOwner();
    $token = Password::createToken($owner);
    $payload = fragmentResetPayload($owner, $token);
    $this->postJson(route('password.update'), array_replace($payload, ['password_confirmation' => 'mismatch']))
        ->assertUnprocessable()->assertJsonValidationErrors('password')->assertDontSee($token);
    expect(Password::tokenExists($owner, $token))->toBeTrue()->and(session('_old_input'))->toBeNull();
    $this->postJson(route('password.update'), $payload)->assertExactJson(['status' => 'password_reset']);
});

test('legacy HTML validation never flashes the reset token or passwords', function (): void {
    $owner = fragmentResetOwner();
    $token = Password::createToken($owner);
    $this->from(route('password.reset.fragment'))->post(route('password.update'), [
        'token' => $token, 'email' => $owner->email, 'password' => 'short', 'password_confirmation' => 'different',
    ])->assertRedirect(route('password.reset.fragment'))->assertSessionHasErrors('password');
    expect(session('_old_input.token'))->toBeNull()->and(session('_old_input.password'))->toBeNull()
        ->and(session('_old_input.password_confirmation'))->toBeNull()
        ->and(Password::tokenExists($owner, $token))->toBeTrue();
});

test('the JSON reset still enforces the actual request forgery middleware', function (): void {
    $this->app->bind(PreventRequestForgery::class, function ($app): PreventRequestForgery {
        return new class($app, $app->make(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };
    });
    $owner = fragmentResetOwner();
    $token = Password::createToken($owner);
    $payload = fragmentResetPayload($owner, $token);
    $this->withSession(['_token' => 'known-csrf'])->postJson(route('password.update'), $payload)
        ->assertStatus(419)->assertDontSee($token)->assertHeader('Referrer-Policy', 'no-referrer');
    expect(Password::tokenExists($owner, $token))->toBeTrue();
    $this->withSession(['_token' => 'known-csrf'])->withHeader('X-CSRF-TOKEN', 'known-csrf')
        ->postJson(route('password.update'), $payload)->assertExactJson(['status' => 'password_reset']);
});

test('ordinary queued recovery uses the fragment contract without query or path credentials', function (): void {
    $owner = fragmentResetOwner();
    $token = Password::createToken($owner);
    $url = (new ResetPasswordLink($token))->toMail($owner)->actionUrl;
    expect(parse_url($url, PHP_URL_PATH))->toBe('/reset-password')
        ->and(parse_url($url, PHP_URL_QUERY))->toBeNull()
        ->and(parse_url($url, PHP_URL_FRAGMENT))->toBe('v=1&token='.$token.'&email=owner%2Bsupport%40example.test');
});

test('the existing notification URL customization hook remains supported', function (): void {
    $original = ResetPassword::$createUrlCallback;
    try {
        ResetPassword::createUrlUsing(fn (): string => 'https://custom.example.test/recovery');
        expect((new ResetPasswordLink(str_repeat('d', 64)))->toMail(fragmentResetOwner())->actionUrl)
            ->toBe('https://custom.example.test/recovery');
    } finally {
        ResetPassword::$createUrlCallback = $original;
    }
});
