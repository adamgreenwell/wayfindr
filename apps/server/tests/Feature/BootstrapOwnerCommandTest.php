<?php

use App\Enums\AccountRole;
use App\Enums\PlatformRole;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    config(['app.url' => 'https://tenant.example.test']);
    Notification::fake();
    $this->ownerClaimDirectory = realpath(sys_get_temp_dir()).'/wayfindr-owner-'.bin2hex(random_bytes(8));
    mkdir($this->ownerClaimDirectory, 0700);
    $this->ownerClaim = $this->ownerClaimDirectory.'/claim.json';
    $this->ownerRequest = '64b764b2-552c-49a0-bf5f-25277b51d590';
    $this->ownerOptions = [
        '--request-id' => $this->ownerRequest, '--email' => 'owner@example.test',
        '--claim-file' => $this->ownerClaim,
    ];
});

afterEach(function (): void {
    foreach (glob($this->ownerClaimDirectory.'/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($this->ownerClaimDirectory);
});

function ownerBootstrapOutput(array $options): array
{
    $exit = Artisan::call('wayfindr:bootstrap-owner', $options);
    $output = Artisan::output();

    return [$exit, json_decode(trim($output), true, 32, JSON_THROW_ON_ERROR), $output];
}

/** @return list<array{int, array<string, mixed>, string}> */
function concurrentOwnerBootstraps(array $requests): array
{
    $database = config('database.connections.'.config('database.default'));
    $processes = [];
    foreach ($requests as $options) {
        $arguments = [PHP_BINARY, base_path('artisan'), 'wayfindr:bootstrap-owner', '--no-interaction'];
        foreach ($options as $option => $value) {
            $arguments[] = $option.'='.$value;
        }
        $processes[] = new Process($arguments, base_path(), [
            'APP_ENV' => 'testing', 'APP_URL' => 'https://tenant.example.test', 'APP_KEY' => config('app.key'),
            'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => $database['host'],
            'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => $database['database'],
            'DB_USERNAME' => $database['username'], 'DB_PASSWORD' => $database['password'],
            'MAIL_MAILER' => 'array', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ], timeout: 20);
    }
    try {
        foreach ($processes as $process) {
            $process->start();
        }
        $results = [];
        foreach ($processes as $process) {
            $exit = $process->wait();
            $results[] = [$exit, json_decode(trim($process->getOutput()), true, 32, JSON_THROW_ON_ERROR), $process->getErrorOutput()];
        }

        return $results;
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }
}

test('fresh headless bootstrap issues a protected claim for an ordinary owner only', function (): void {
    [$exit, $receipt, $output] = ownerBootstrapOutput($this->ownerOptions);
    $claim = json_decode(file_get_contents($this->ownerClaim), true, 32, JSON_THROW_ON_ERROR);
    $owner = User::query()->sole();
    $site = Site::query()->sole();

    expect($exit)->toBe(0)->and($receipt['status'])->toBe('completed')
        ->and($owner->account_role)->toBe(AccountRole::Owner)->and($owner->platform_role)->toBeNull()
        ->and($site->supportsAgent($owner))->toBeTrue()
        ->and($claim['receipt'])->toBe($receipt)
        ->and($claim['password_reset_url'])->toStartWith('https://tenant.example.test/reset-password/')
        ->and($output)->not->toContain('password_reset_url', '/reset-password/', 'owner@example.test')
        ->and(fileperms($this->ownerClaim) & 0777)->toBe(0600)
        ->and(DB::table('owner_bootstrap_requests')->count())->toBe(1)
        ->and(DB::table('password_reset_tokens')->count())->toBe(1);
    Notification::assertNothingSent();
    $this->get('/setup')->assertRedirect(route('login'));
    $this->actingAs($owner)->get('/operator')->assertForbidden();
});

test('the claim uses the normal one-use password reset and sign-in flow', function (): void {
    ownerBootstrapOutput($this->ownerOptions);
    $claim = json_decode(file_get_contents($this->ownerClaim), true, 32, JSON_THROW_ON_ERROR);
    $token = basename(parse_url($claim['password_reset_url'], PHP_URL_PATH));
    $owner = User::query()->sole();
    $payload = ['token' => $token, 'email' => $owner->email, 'password' => 'customer-chosen-password', 'password_confirmation' => 'customer-chosen-password'];

    $this->post(route('password.update'), $payload)->assertRedirect(route('login'));
    expect(Hash::check('customer-chosen-password', $owner->fresh()->password))->toBeTrue();
    $this->post(route('password.update'), $payload)->assertSessionHasErrors('email');
    $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'customer-chosen-password'])->assertRedirect();
    $this->assertAuthenticatedAs($owner);
    $this->get('/operator')->assertForbidden();
    Notification::assertNothingSent();
});

test('the claim expires according to the admitted normal broker configuration', function (): void {
    config(['auth.passwords.users.expire' => 5]);
    ownerBootstrapOutput($this->ownerOptions);
    $claim = json_decode(file_get_contents($this->ownerClaim), true, 32, JSON_THROW_ON_ERROR);
    $token = basename(parse_url($claim['password_reset_url'], PHP_URL_PATH));
    $this->travel(6)->minutes();
    expect(Password::tokenExists(User::query()->sole(), $token))->toBeFalse();
});

test('an exact committed retry returns the stable receipt and never rotates the claim', function (): void {
    [$exit, $first, $firstOutput] = ownerBootstrapOutput($this->ownerOptions);
    $bytes = file_get_contents($this->ownerClaim);
    $token = DB::table('password_reset_tokens')->sole()->token;
    $this->travel(2)->hours();
    [$again, $second, $secondOutput] = ownerBootstrapOutput($this->ownerOptions);

    expect([$exit, $again])->toBe([0, 0])->and($second)->toBe($first)
        ->and($secondOutput)->toBe($firstOutput)->and(file_get_contents($this->ownerClaim))->toBe($bytes)
        ->and(DB::table('password_reset_tokens')->sole()->token)->toBe($token)
        ->and(Account::query()->count())->toBe(1)->and(User::query()->count())->toBe(1)->and(Site::query()->count())->toBe(1);
    Notification::assertNothingSent();
});

test('different requests or changed request data cannot reuse a committed bootstrap', function (): void {
    ownerBootstrapOutput($this->ownerOptions);
    $bytes = file_get_contents($this->ownerClaim);
    foreach ([['--request-id' => '64b764b2-552c-49a0-bf5f-25277b51d591'], ['--email' => 'other@example.test'], ['--name' => 'Other owner']] as $change) {
        [$exit, $receipt] = ownerBootstrapOutput(array_replace($this->ownerOptions, $change));
        expect($exit)->toBe(1)->and($receipt['code'])->toBe('owner_bootstrap_request_conflict');
    }
    expect(file_get_contents($this->ownerClaim))->toBe($bytes)->and(User::query()->sole()->email)->toBe('owner@example.test');
});

test('missing or changed committed claims hold without token reissuance', function (): void {
    ownerBootstrapOutput($this->ownerOptions);
    $token = DB::table('password_reset_tokens')->sole()->token;
    file_put_contents($this->ownerClaim, '{}');
    [$exit, $receipt] = ownerBootstrapOutput($this->ownerOptions);
    expect($exit)->toBe(1)->and($receipt['code'])->toBe('owner_bootstrap_replay_refused')->and(file_get_contents($this->ownerClaim))->toBe('{}');
    unlink($this->ownerClaim);
    [$exit, $receipt] = ownerBootstrapOutput($this->ownerOptions);
    expect($exit)->toBe(1)->and($receipt['code'])->toBe('owner_bootstrap_replay_refused')
        ->and(file_exists($this->ownerClaim))->toBeFalse()->and(DB::table('password_reset_tokens')->sole()->token)->toBe($token);
});

test('replay verifies the exact claim resource envelope beyond a matching file hash', function (): void {
    ownerBootstrapOutput($this->ownerOptions);
    $original = json_decode(file_get_contents($this->ownerClaim), true, 32, JSON_THROW_ON_ERROR);
    $token = DB::table('password_reset_tokens')->sole()->token;
    foreach (['receipt', 'origin', 'email'] as $mismatch) {
        $claim = $original;
        if ($mismatch === 'receipt') {
            $claim['receipt']['owner_id']++;
        } elseif ($mismatch === 'origin') {
            $claim['password_reset_url'] = str_replace('tenant.example.test', 'foreign.example.test', $claim['password_reset_url']);
        } else {
            $claim['password_reset_url'] = str_replace('owner%40example.test', 'other%40example.test', $claim['password_reset_url']);
        }
        $bytes = json_encode($claim, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        file_put_contents($this->ownerClaim, $bytes);
        DB::table('owner_bootstrap_requests')->where('id', 1)->update(['claim_file_sha256' => hash('sha256', $bytes)]);
        [$exit, $receipt] = ownerBootstrapOutput($this->ownerOptions);
        expect($exit)->toBe(1)->and($receipt['code'])->toBe('owner_bootstrap_replay_refused')
            ->and(DB::table('password_reset_tokens')->sole()->token)->toBe($token);
    }
});

test('a preexisting destination is never overwritten or treated as our claim', function (): void {
    file_put_contents($this->ownerClaim, 'foreign evidence');
    chmod($this->ownerClaim, 0600);
    [$exit, $receipt] = ownerBootstrapOutput($this->ownerOptions);
    expect($exit)->toBe(1)->and($receipt['code'])->toBe('owner_bootstrap_claim_file_refused')
        ->and(file_get_contents($this->ownerClaim))->toBe('foreign evidence')
        ->and(Account::query()->count())->toBe(0)->and(DB::table('owner_bootstrap_requests')->count())->toBe(0);
});

test('unsafe parents and symlink destinations are refused before creating records', function (): void {
    chmod($this->ownerClaimDirectory, 0755);
    [$exit, $receipt] = ownerBootstrapOutput($this->ownerOptions);
    expect($exit)->toBe(1)->and($receipt['code'])->toBe('owner_bootstrap_claim_file_refused');
    chmod($this->ownerClaimDirectory, 0700);
    file_put_contents($this->ownerClaimDirectory.'/foreign', 'foreign');
    symlink($this->ownerClaimDirectory.'/foreign', $this->ownerClaim);
    [$exit, $receipt] = ownerBootstrapOutput($this->ownerOptions);
    expect($exit)->toBe(1)->and($receipt['code'])->toBe('owner_bootstrap_claim_file_refused')
        ->and(file_get_contents($this->ownerClaimDirectory.'/foreign'))->toBe('foreign')->and(User::query()->count())->toBe(0);
});

test('an existing installation stays intact', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['platform_role' => PlatformRole::Operator]);
    [$exit, $receipt] = ownerBootstrapOutput($this->ownerOptions);
    expect($exit)->toBe(1)->and($receipt['code'])->toBe('owner_bootstrap_existing_installation')
        ->and($owner->fresh()->platform_role)->toBe(PlatformRole::Operator)->and(file_exists($this->ownerClaim))->toBeFalse();
});

test('unsupported broker connections, expiry or origins are refused before issuing credentials', function (): void {
    foreach ([['auth.passwords.users.driver' => 'cache'], ['auth.passwords.users.expire' => 61], ['auth.passwords.users.expire' => '60'], ['app.url' => 'https://tenant.example.test/subpath'], ['app.url' => 'http://tenant.example.test']] as $change) {
        $before = [];
        foreach ($change as $key => $value) {
            $before[$key] = config($key);
        }
        config($change);
        [$exit, $receipt] = ownerBootstrapOutput($this->ownerOptions);
        expect($exit)->toBe(1)->and($receipt['code'])->toBe('owner_bootstrap_configuration_refused');
        config($before);
    }
    expect(User::query()->count())->toBe(0)->and(file_exists($this->ownerClaim))->toBeFalse();
});

test('an enclosing transaction cannot receive a falsely completed bootstrap receipt', function (): void {
    DB::beginTransaction();
    try {
        [$exit, $receipt] = ownerBootstrapOutput($this->ownerOptions);
        expect($exit)->toBe(1)->and($receipt['code'])->toBe('owner_bootstrap_configuration_refused')
            ->and(file_exists($this->ownerClaim))->toBeFalse();
    } finally {
        DB::rollBack();
    }
});

test('a failure after private file creation preserves evidence and refuses blind retries', function (): void {
    User::creating(function (): never {
        throw new RuntimeException('sensitive diagnostic that must not escape');
    });
    try {
        [$exit, $receipt, $output] = ownerBootstrapOutput($this->ownerOptions);
    } finally {
        User::flushEventListeners();
    }
    expect($exit)->toBe(1)->and($receipt['code'])->toBe('owner_bootstrap_outcome_unknown')
        ->and($output)->not->toContain('sensitive diagnostic')->and(file_exists($this->ownerClaim))->toBeTrue()
        ->and(file_get_contents($this->ownerClaim))->toBe('')->and(User::query()->count())->toBe(0)
        ->and(DB::table('owner_bootstrap_requests')->count())->toBe(0);
    [$again, $retry] = ownerBootstrapOutput($this->ownerOptions);
    expect($again)->toBe(1)->and($retry['code'])->toBe('owner_bootstrap_claim_file_refused');
});

test('read-only status needs no claim file and never infers token consumption or management authority', function (): void {
    ownerBootstrapOutput($this->ownerOptions);
    unlink($this->ownerClaim);
    DB::table('password_reset_tokens')->delete();
    [$exit, $status] = ownerBootstrapOutput(['--status' => true, '--request-id' => $this->ownerRequest]);
    expect($exit)->toBe(0)->and($status['initial_binding_ready'])->toBeTrue()
        ->and($status['claim_consumption'])->toBe('not_observed')->and($status['management_authority'])->toBeFalse()
        ->and($status['bootstrap_receipt']['claim_issued'])->toBeTrue()->and(file_exists($this->ownerClaim))->toBeFalse()
        ->and(DB::table('password_reset_tokens')->count())->toBe(0);
    User::query()->sole()->update(['account_role' => AccountRole::Admin]);
    [$exit, $changed] = ownerBootstrapOutput(['--status' => true, '--request-id' => $this->ownerRequest]);
    expect($exit)->toBe(1)->and($changed['status'])->toBe('completed')->and($changed['initial_binding_ready'])->toBeFalse();
    Notification::assertNothingSent();
});

test('unknown requests and invalid canonical identifiers return only fixed failures', function (): void {
    [$exit, $receipt] = ownerBootstrapOutput(['--status' => true, '--request-id' => $this->ownerRequest]);
    expect($exit)->toBe(1)->and($receipt['code'])->toBe('owner_bootstrap_not_completed');
    [$exit, $receipt] = ownerBootstrapOutput(array_replace($this->ownerOptions, ['--request-id' => 'secret-input-is-invalid']));
    expect($exit)->toBe(1)->and($receipt)->toBe(['status' => 'held', 'code' => 'owner_bootstrap_invalid_request']);
});

test('completion-only status preserves legitimate later ownership changes without promoting the initial binding', function (): void {
    ownerBootstrapOutput($this->ownerOptions);
    $original = User::query()->sole();
    $successor = User::factory()->create([
        'account_id' => $original->account_id, 'account_role' => AccountRole::Owner, 'platform_role' => null,
    ]);
    $original->update(['account_role' => AccountRole::Admin]);
    Site::query()->sole()->supportAgents()->sync([$successor->id]);
    unlink($this->ownerClaim);
    DB::table('password_reset_tokens')->delete();

    [$strict, $initial] = ownerBootstrapOutput(['--status' => true, '--request-id' => $this->ownerRequest]);
    [$completed, $later] = ownerBootstrapOutput(['--status' => true, '--completion-only' => true, '--request-id' => $this->ownerRequest]);
    expect($strict)->toBe(1)->and($completed)->toBe(0)->and($later)->toBe($initial)
        ->and($later['initial_binding_ready'])->toBeFalse()->and($later['bootstrap_receipt']['status'])->toBe('completed')
        ->and($later['management_authority'])->toBeFalse()->and($later['claim_consumption'])->toBe('not_observed')
        ->and(file_exists($this->ownerClaim))->toBeFalse()->and(DB::table('password_reset_tokens')->count())->toBe(0);
    Notification::assertNothingSent();
});

test('completion-only never bypasses missing or invalid receipts and cannot bootstrap an owner', function (): void {
    $options = ['--status' => true, '--completion-only' => true, '--request-id' => $this->ownerRequest];
    [$exit, $receipt] = ownerBootstrapOutput($options);
    expect($exit)->toBe(1)->and($receipt['code'])->toBe('owner_bootstrap_not_completed');
    [$exit, $receipt] = ownerBootstrapOutput(array_replace($this->ownerOptions, ['--completion-only' => true]));
    expect($exit)->toBe(1)->and($receipt['code'])->toBe('owner_bootstrap_invalid_request')
        ->and(User::query()->count())->toBe(0)->and(file_exists($this->ownerClaim))->toBeFalse();
    ownerBootstrapOutput($this->ownerOptions);
    DB::table('owner_bootstrap_requests')->where('id', 1)->update(['receipt' => '{}']);
    [$exit, $receipt] = ownerBootstrapOutput($options);
    expect($exit)->toBe(1)->and($receipt['code'])->toBe('owner_bootstrap_replay_refused');
});

test('concurrent identical PostgreSQL requests commit once and return the same private claim receipt', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The real multi-process lock regression requires the disposable PostgreSQL test database.');
    }
    [$first, $second] = concurrentOwnerBootstraps([$this->ownerOptions, $this->ownerOptions]);
    expect($first[0])->toBe(0)->and($second[0])->toBe(0)->and($second[1])->toBe($first[1])
        ->and($first[2])->toBe('')->and($second[2])->toBe('')
        ->and(Account::query()->count())->toBe(1)->and(User::query()->count())->toBe(1)
        ->and(Site::query()->count())->toBe(1)->and(DB::table('owner_bootstrap_requests')->count())->toBe(1)
        ->and(DB::table('password_reset_tokens')->count())->toBe(1);
    $claim = json_decode(file_get_contents($this->ownerClaim), true, 32, JSON_THROW_ON_ERROR);
    expect($claim['receipt'])->toBe($first[1]);
});

test('concurrent competing PostgreSQL requests produce exactly one owner and preserve the loser boundary', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The real multi-process lock regression requires the disposable PostgreSQL test database.');
    }
    $other = array_replace($this->ownerOptions, [
        '--request-id' => '64b764b2-552c-49a0-bf5f-25277b51d591',
        '--email' => 'other@example.test', '--claim-file' => $this->ownerClaimDirectory.'/other.json',
    ]);
    $results = concurrentOwnerBootstraps([$this->ownerOptions, $other]);
    $winner = $results[0][0] === 0 ? 0 : 1;
    $loser = 1 - $winner;
    $requests = [$this->ownerOptions, $other];
    expect($results[$winner][0])->toBe(0)->and($results[$loser][0])->toBe(1)
        ->and($results[$loser][1])->toBe(['status' => 'held', 'code' => 'owner_bootstrap_request_conflict'])
        ->and($results[0][2])->toBe('')->and($results[1][2])->toBe('')
        ->and(User::query()->sole()->email)->toBe($requests[$winner]['--email'])
        ->and(file_exists($requests[$winner]['--claim-file']))->toBeTrue()
        ->and(file_exists($requests[$loser]['--claim-file']))->toBeFalse()
        ->and(Account::query()->count())->toBe(1)->and(Site::query()->count())->toBe(1)
        ->and(DB::table('owner_bootstrap_requests')->count())->toBe(1);
});
