<?php

use App\Console\Commands\ManagedApplyCommand;
use App\Listeners\BlockMigrationsWithUnmetRequirements;
use App\Support\Release\ReleaseManifest;
use App\Support\Release\ReleaseState;
use App\Support\Updates\ManagedMigrationContext;
use App\Support\Updates\ManagedRealtimeProbe;
use App\Support\Updates\ManagedUpdateGate;
use App\Support\Visitors\ErasureLedger;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Predis\Response\Status;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

class ManagedApplyFixtureCommand extends ManagedApplyCommand
{
    public array $paths;

    public int $runs = 0;

    public bool $databaseWorks = true;

    public bool $redisWorks = true;

    public ?Closure $beforeMigration = null;

    public ?Closure $afterMigration = null;

    public ?int $migrationResult = null;

    protected function artifactPaths(): array
    {
        return $this->paths;
    }

    public function binding(): string
    {
        return $this->bindingSha256();
    }

    protected function verifyDatabase(): void
    {
        // Schema and migration events below use the real disposable SQLite
        // database. Only the PostgreSQL/Redis connectivity boundary is faked.
        if (! $this->databaseWorks) {
            throw new RuntimeException('managed_apply_database_unverified');
        }
    }

    protected function verifyRedis(): void
    {
        if (! $this->redisWorks) {
            throw new RuntimeException('managed_apply_redis_unverified');
        }
    }

    protected function migrate(): int
    {
        $this->runs++;
        ($this->beforeMigration ?? static fn () => null)();
        $result = $this->migrationResult ?? parent::migrate();
        ($this->afterMigration ?? static fn () => null)();

        return $result;
    }
}

class ManagedApplyStopped extends RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct('Stopped before the migration command could run.');
    }
}

class ManagedApplyGateListener extends BlockMigrationsWithUnmetRequirements
{
    protected function terminate(int $code): never
    {
        throw new ManagedApplyStopped($code);
    }
}

class ManagedApplyDependencyProbe extends ManagedApplyCommand
{
    public function database(): void
    {
        $this->verifyDatabase();
    }

    public function redis(): void
    {
        $this->verifyRedis();
    }
}

beforeEach(function (): void {
    $this->applyOriginalStorage = app()->storagePath();
    $this->applyRoot = sys_get_temp_dir().'/wayfindr-managed-apply-'.bin2hex(random_bytes(8));
    mkdir($this->applyRoot.'/framework', 0700, true);
    mkdir($this->applyRoot.'/app', 0700, true);
    app()->useStoragePath($this->applyRoot);
    $this->applyOperation = '11111111-2222-4333-8444-555555555555';
    $this->applyTargetCommit = str_repeat('a', 40);
    $this->applyPlan = str_repeat('c', 64);
    $this->applyFile = storage_path('app/managed-updates/'.$this->applyOperation.'/migration.json');
    config()->set('wayfindr.release.version', 'v1.2.0');
    config()->set('wayfindr.release.commit', $this->applyTargetCommit);
    config()->set('wayfindr.release.installation_profile', 'image');
    config()->set('wayfindr.release.manifest_path', $this->applyRoot.'/release.json');
    config()->set('wayfindr.release.history_path', $this->applyRoot.'/history.json');
    config()->set('wayfindr.release.state_path', storage_path('app/release-state.json'));
    config()->set('wayfindr.erasure.ledger_path', storage_path('app/erasure-ledger'));
    config()->set('database.connections.pgsql.password', 'private-database-password-never-in-receipts');
    config()->set('app.previous_keys', ['private-previous-key-never-in-receipts']);
    $this->applyManifest = ReleaseManifest::build([], '1.2.0', $this->applyTargetCommit);
    file_put_contents($this->applyRoot.'/release.json', json_encode($this->applyManifest)."\n");
    file_put_contents($this->applyRoot.'/history.json', json_encode(['schema' => 1, 'releases' => [$this->applyManifest]])."\n");
    app(ReleaseState::class)->record('1.1.1', str_repeat('b', 40), '1.1.1', false, 'image');
    $this->applyCommand = new ManagedApplyFixtureCommand;
    $this->applyCommand->paths = ['manifest' => $this->applyRoot.'/release.json', 'history' => $this->applyRoot.'/history.json'];
    $kernel = app(Kernel::class);
    // Tests normally disable this bridge. Exercise the same COMMAND/TERMINATE
    // events the production Kernel::call path uses, including release recording.
    $kernel->rerouteSymfonyCommandEvents();
    $kernel->registerCommand($this->applyCommand);
    app(ManagedUpdateGate::class)->enter($this->applyOperation);
});

afterEach(function (): void {
    app()->useStoragePath($this->applyOriginalStorage);
    File::deleteDirectory($this->applyRoot);
});

function managedApplyReceipt(string $action = 'assess', array $overrides = []): array
{
    $test = test();
    $output = new BufferedOutput;
    $exit = Artisan::call('wayfindr:managed-apply', array_replace([
        'operation' => $test->applyOperation, '--action' => $action,
        '--plan-id' => $test->applyPlan, '--target-version' => '1.2.0',
        '--commit' => $test->applyTargetCommit, '--binding' => $test->applyCommand->binding(), '--json' => true,
    ], $overrides), $output);

    $raw = $output->fetch();

    try {
        $value = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('Fixture command produced invalid JSON: '.$raw);
    }

    return [$exit, $value, $raw];
}

test('assess emits only bounded hashes and fixed facts without applying or writing an intent', function (): void {
    [$exit, $receipt, $raw] = managedApplyReceipt();

    expect($exit)->toBe(0)
        ->and($receipt)->toHaveKeys([
            'schema', 'operation_id', 'plan_id', 'phase', 'target', 'binding_sha256',
            'manifest_sha256', 'history_sha256', 'migrations_sha256', 'release_state_sha256',
            'pending_migrations', 'guards_clear', 'database_verified', 'redis_verified', 'hold_owned',
        ])
        ->and(count($receipt))->toBe(15)
        ->and($receipt['phase'])->toBe('assessed')
        ->and($receipt['target'])->toBe(['version' => '1.2.0', 'commit' => $this->applyTargetCommit, 'profile' => 'image'])
        ->and($receipt['manifest_sha256'])->toBe(hash_file('sha256', $this->applyRoot.'/release.json'))
        ->and($receipt['history_sha256'])->toBe(hash_file('sha256', $this->applyRoot.'/history.json'))
        ->and($receipt['release_state_sha256'])->toBe(hash_file('sha256', storage_path('app/release-state.json')))
        ->and($receipt['pending_migrations'])->toBe(0)
        ->and($this->applyCommand->runs)->toBe(0)
        ->and(file_exists($this->applyFile))->toBeFalse()
        ->and($raw)->not->toContain('private-', 'password', $this->applyRoot, 'create_users_table');
});

test('baseline verifies the current serving release without migrating or requiring a target receipt', function (): void {
    app(ReleaseState::class)->record('1.2.0', $this->applyTargetCommit, '1.2.0', false, 'image');
    $state = file_get_contents(storage_path('app/release-state.json'));
    [$exit, $receipt, $raw] = managedApplyReceipt('baseline');

    expect($exit)->toBe(0)->and($receipt['phase'])->toBe('assessed')
        ->and($receipt['pending_migrations'])->toBe(0)->and($receipt['guards_clear'])->toBeTrue()
        ->and($receipt['release_state_sha256'])->toBe(hash('sha256', $state))
        ->and($this->applyCommand->runs)->toBe(0)->and(file_exists($this->applyFile))->toBeFalse()
        ->and(file_get_contents(storage_path('app/release-state.json')))->toBe($state)
        ->and(app(ManagedUpdateGate::class)->active())->toBeTrue()
        ->and($raw)->not->toContain('private-', $this->applyRoot);
});

test('held realtime proof binds source or target identity without writing a migration receipt', function (string $version): void {
    config()->set('wayfindr.release.version', 'v'.$version);
    $manifest = ReleaseManifest::build([], $version, $this->applyTargetCommit);
    file_put_contents($this->applyRoot.'/release.json', json_encode($manifest)."\n");
    file_put_contents($this->applyRoot.'/history.json', json_encode(['schema' => 1, 'releases' => [$manifest]])."\n");
    $state = file_get_contents(storage_path('app/release-state.json'));
    $probe = Mockery::mock(ManagedRealtimeProbe::class);
    $probe->shouldReceive('verify')->once();
    app()->instance(ManagedRealtimeProbe::class, $probe);
    [$exit, $receipt, $raw] = managedApplyReceipt('realtime', ['--target-version' => $version]);

    expect($exit)->toBe(0)->and($receipt)->toBe([
        'schema' => 1, 'operation_id' => $this->applyOperation, 'plan_id' => $this->applyPlan,
        'phase' => 'realtime_verified', 'target' => ['version' => $version, 'commit' => $this->applyTargetCommit, 'profile' => 'image'],
        'binding_sha256' => $this->applyCommand->binding(), 'hold_owned' => true, 'realtime_verified' => true,
    ])->and($this->applyCommand->runs)->toBe(0)->and(file_exists($this->applyFile))->toBeFalse()
        ->and(file_get_contents(storage_path('app/release-state.json')))->toBe($state)
        ->and(app(ManagedUpdateGate::class)->active())->toBeTrue()
        ->and($raw)->not->toContain('private-', 'secret', 'host', 'auth', $this->applyRoot);
})->with(['1.1.1', '1.2.0']);

test('failed realtime delivery preserves the hold and emits only the classified refusal', function (): void {
    $probe = Mockery::mock(ManagedRealtimeProbe::class);
    $probe->shouldReceive('verify')->once()->andThrow(new RuntimeException('managed_apply_realtime_unverified'));
    app()->instance(ManagedRealtimeProbe::class, $probe);
    [$exit, $receipt, $raw] = managedApplyReceipt('realtime');

    expect($exit)->toBe(1)->and($receipt['reason'])->toBe('managed_apply_realtime_unverified')
        ->and(app(ManagedUpdateGate::class)->active())->toBeTrue()
        ->and($this->applyCommand->runs)->toBe(0)->and(file_exists($this->applyFile))->toBeFalse()
        ->and($raw)->not->toContain('private-', $this->applyRoot);
});

test('realtime rechecks the effective binding and operation hold after transport succeeds', function (string $changed): void {
    $probe = Mockery::mock(ManagedRealtimeProbe::class);
    $probe->shouldReceive('verify')->once()->andReturnUsing(function () use ($changed): void {
        if ($changed === 'binding') {
            config()->set('database.connections.pgsql.password', 'changed-private-database-password');
        } else {
            unlink(app(ManagedUpdateGate::class)->markerPath());
        }
    });
    app()->instance(ManagedRealtimeProbe::class, $probe);
    [$exit, $receipt] = managedApplyReceipt('realtime');

    expect($exit)->toBe(1)->and($receipt['reason'])->toBe($changed === 'binding' ? 'managed_apply_binding_mismatch' : 'managed_update_busy')
        ->and($this->applyCommand->runs)->toBe(0)->and(file_exists($this->applyFile))->toBeFalse();
})->with(['binding', 'hold']);

test('realtime refuses wrong operation, target or binding before transport starts', function (string $field): void {
    $probe = Mockery::mock(ManagedRealtimeProbe::class);
    $probe->shouldNotReceive('verify');
    app()->instance(ManagedRealtimeProbe::class, $probe);
    [$exit, $receipt] = managedApplyReceipt('realtime', match ($field) {
        'operation' => ['operation' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'],
        'target' => ['--target-version' => '1.3.0'],
        'binding' => ['--binding' => str_repeat('f', 64)],
    });

    expect($exit)->toBe(1)->and($receipt['reason'])->toBe(match ($field) {
        'operation' => 'managed_update_busy', 'target' => 'managed_apply_target_mismatch', 'binding' => 'managed_apply_binding_mismatch',
    })->and(app(ManagedUpdateGate::class)->active())->toBeTrue()->and($this->applyCommand->runs)->toBe(0);
})->with(['operation', 'target', 'binding']);

test('baseline refuses schema debt, serving debt, erasure debt, or an inexact source release record', function (string $failure): void {
    app(ReleaseState::class)->record('1.2.0', $this->applyTargetCommit, '1.2.0', false, 'image');

    if ($failure === 'serving') {
        $this->applyManifest = ReleaseManifest::build(['actions' => [[
            'id' => 'source-manual-work', 'summary' => 'private-source-action-not-a-receipt', 'detail' => 'operator action',
            'phase' => 'after-start', 'depends_on_release' => 'none',
            'applicability' => ['type' => 'always'], 'verification' => ['type' => 'attest'],
        ]]], '1.2.0', $this->applyTargetCommit);
        file_put_contents($this->applyRoot.'/release.json', json_encode($this->applyManifest)."\n");
        file_put_contents($this->applyRoot.'/history.json', json_encode(['schema' => 1, 'releases' => [$this->applyManifest]])."\n");
    } else {
        match ($failure) {
            'pending' => DB::table('migrations')->where('migration', DB::table('migrations')->value('migration'))->delete(),
            'erasure' => app(ErasureLedger::class)->markReapplyOutstanding(),
            'version' => app(ReleaseState::class)->record('1.1.1', str_repeat('b', 40), '1.1.1', false, 'image'),
            'commit' => app(ReleaseState::class)->record('1.2.0', str_repeat('b', 40), '1.2.0', false, 'image'),
            'profile' => app(ReleaseState::class)->record('1.2.0', $this->applyTargetCommit, '1.2.0', false, 'host'),
        };
    }

    [$exit, $receipt, $raw] = managedApplyReceipt('baseline');
    expect($exit)->toBe(1)->and($receipt['reason'])->toBe(in_array($failure, ['pending', 'serving', 'erasure'], true)
        ? 'managed_apply_verification_failed' : 'managed_apply_state_mismatch')
        ->and($this->applyCommand->runs)->toBe(0)->and(file_exists($this->applyFile))->toBeFalse()
        ->and(app(ManagedUpdateGate::class)->active())->toBeTrue()
        ->and($raw)->not->toContain('private-', $this->applyRoot);
})->with(['pending', 'serving', 'erasure', 'version', 'commit', 'profile']);

test('invalid target and operation arguments fail before migration', function (array $overrides): void {
    [$exit, $receipt] = managedApplyReceipt('migrate', $overrides);

    expect($exit)->toBe(1)->and($receipt['reason'])->toBe('managed_apply_request_invalid')
        ->and($this->applyCommand->runs)->toBe(0)->and(file_exists($this->applyFile))->toBeFalse();
})->with([
    [['operation' => '../../elsewhere']], [['--action' => 'rollback']], [['--plan-id' => 'short']],
    [['--target-version' => 'v1.2.0']], [['--target-version' => '1.2.0-dev']], [['--target-version' => '01.2.0']],
    [['--commit' => 'abc123']], [['--commit' => str_repeat('A', 40)]], [['--binding' => 'bad']],
]);

test('fixed target identity, binding, and dependencies refuse before an intent or schema change', function (string $failure): void {
    $binding = $this->applyCommand->binding();

    match ($failure) {
        'target' => config()->set('wayfindr.release.version', '1.1.1'),
        'binding' => config()->set('filesystems.disks.attachments.root', '/changed/private/storage'),
        'database' => $this->applyCommand->databaseWorks = false,
        'redis' => $this->applyCommand->redisWorks = false,
    };
    [$exit, $receipt] = managedApplyReceipt('migrate', ['--binding' => $binding]);

    expect($exit)->toBe(1)->and($receipt['reason'])->toBe(match ($failure) {
        'target' => 'managed_apply_target_mismatch', 'binding' => 'managed_apply_binding_mismatch',
        'database' => 'managed_apply_database_unverified', 'redis' => 'managed_apply_redis_unverified',
    })->and($this->applyCommand->runs)->toBe(0)->and(file_exists($this->applyFile))->toBeFalse();
})->with(['target', 'binding', 'database', 'redis']);

test('the exact owned hold and its lifetime file lease are required', function (): void {
    [$exit, $receipt] = managedApplyReceipt('migrate', ['operation' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee']);
    expect($exit)->toBe(1)->and($receipt['reason'])->toBe('managed_update_busy');

    $gate = app(ManagedUpdateGate::class);
    $lease = $gate->acquireProtective($this->applyOperation);

    try {
        [$exit, $receipt] = managedApplyReceipt('migrate');
        expect($exit)->toBe(1)->and($receipt['reason'])->toBe('managed_update_busy');
    } finally {
        $lease->release();
    }

    $gate->release($this->applyOperation);
    [$exit, $receipt] = managedApplyReceipt('migrate');
    expect($exit)->toBe(1)->and($receipt['reason'])->toBe('managed_update_busy')
        ->and($this->applyCommand->runs)->toBe(0)->and(file_exists($this->applyFile))->toBeFalse();
});

test('the fixed migration keeps ownership through real migration events and persists complete evidence', function (): void {
    $initialStateHash = hash_file('sha256', storage_path('app/release-state.json'));
    $events = 0;
    app('events')->listen(CommandFinished::class, function (CommandFinished $event) use (&$events): void {
        if ($event->command === 'migrate') {
            $events++;
        }
    });
    $this->applyCommand->beforeMigration = function () use ($initialStateHash): void {
        $intent = json_decode(file_get_contents($this->applyFile), true, flags: JSON_THROW_ON_ERROR);
        expect($intent['phase'])->toBe('intent')->and($intent['release_state_sha256'])->toBe($initialStateHash)
            ->and(fileperms($this->applyFile) & 0777)->toBe(0600)
            ->and(app(ManagedMigrationContext::class)->allows('migrate'))->toBeTrue()
            ->and(app(ManagedMigrationContext::class)->allows('migrate:fresh'))->toBeFalse();
        expect(fn () => app(ManagedUpdateGate::class)->release($this->applyOperation))->toThrow(RuntimeException::class, 'managed_update_busy');
    };
    [$exit, $complete, $raw] = managedApplyReceipt('migrate');

    expect($exit)->toBe(0, $raw)->and($events)->toBe(1)->and($this->applyCommand->runs)->toBe(1)
        ->and($complete['phase'])->toBe('complete')->and($complete['pending_migrations'])->toBe(0)
        ->and(app(ReleaseState::class)->recordedVersion())->toBe('1.2.0')
        ->and(app(ReleaseState::class)->recordedCommit())->toBe($this->applyTargetCommit)
        ->and(app(ManagedUpdateGate::class)->status()['operation_id'])->toBe($this->applyOperation)
        ->and(app(ManagedMigrationContext::class)->allows('migrate'))->toBeFalse()
        ->and(json_decode(file_get_contents($this->applyFile), true))->toBe($complete)
        ->and($raw)->not->toContain('private-', 'Nothing to migrate', 'migration succeeded');

    [$verifiedExit, $verified] = managedApplyReceipt('verify');
    expect($verifiedExit)->toBe(0)->and($verified)->toBe(array_replace($complete, ['phase' => 'verified']));
    [$receiptExit, $stored] = managedApplyReceipt('receipt');
    expect($receiptExit)->toBe(0)->and($stored)->toBe($complete);
    [$replayExit, $replay] = managedApplyReceipt('migrate');
    expect($replayExit)->toBe(1)->and($replay['reason'])->toBe('managed_apply_already_started')->and($this->applyCommand->runs)->toBe(1);
});

test('the fixed owner applies an actual pending migration before verifying target state', function (): void {
    $directory = $this->applyRoot.'/migrations';
    mkdir($directory, 0700);
    file_put_contents($directory.'/2099_01_01_000000_create_managed_apply_fixture.php', <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \Illuminate\Support\Facades\Schema::create('managed_apply_fixture', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
        });
    }
    public function down(): void {
        \Illuminate\Support\Facades\Schema::dropIfExists('managed_apply_fixture');
    }
};
PHP);
    app('migrator')->path($directory);
    [$assessExit, $before] = managedApplyReceipt();
    expect($assessExit)->toBe(0)->and($before['pending_migrations'])->toBe(1)
        ->and(Schema::hasTable('managed_apply_fixture'))->toBeFalse();

    [$exit, $complete, $raw] = managedApplyReceipt('migrate');
    expect($exit)->toBe(0, $raw)->and($complete['pending_migrations'])->toBe(0)
        ->and(Schema::hasTable('managed_apply_fixture'))->toBeTrue()
        ->and($complete['migrations_sha256'])->not->toBe($before['migrations_sha256'])
        ->and($raw)->not->toContain('create_managed_apply_fixture')
        ->and(managedApplyReceipt('verify')[0])->toBe(0);
});

test('a migration failing after a schema write leaves a durable intent and the hold', function (): void {
    $directory = $this->applyRoot.'/migrations';
    mkdir($directory, 0700);
    file_put_contents($directory.'/2099_01_01_000001_create_partial_apply_fixture.php', <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public $withinTransaction = false;
    public function up(): void {
        \Illuminate\Support\Facades\Schema::create('partial_apply_fixture', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
        });
        throw new \RuntimeException('private-migration-details-not-a-receipt');
    }
};
PHP);
    app('migrator')->path($directory);
    [$exit, $failure, $raw] = managedApplyReceipt('migrate');
    expect($exit)->toBe(1)->and($failure['reason'])->toBe('managed_apply_migration_failed')
        ->and(Schema::hasTable('partial_apply_fixture'))->toBeTrue()
        ->and(json_decode(file_get_contents($this->applyFile), true)['phase'])->toBe('intent')
        ->and(app(ManagedUpdateGate::class)->active())->toBeTrue()
        ->and($raw)->not->toContain('private-migration', 'create_partial_apply_fixture');
    expect(managedApplyReceipt('migrate')[1]['reason'])->toBe('managed_apply_already_started');
});

test('failed migration preserves intent and refuses replay rather than inferring unchanged schema', function (): void {
    $this->applyCommand->migrationResult = 1;
    [$exit, $failure] = managedApplyReceipt('migrate');
    expect($exit)->toBe(1)->and($failure['reason'])->toBe('managed_apply_migration_failed');

    [$readExit, $intent] = managedApplyReceipt('receipt');
    expect($readExit)->toBe(0)->and($intent['phase'])->toBe('intent');
    [$verifyExit, $verification] = managedApplyReceipt('verify');
    expect($verifyExit)->toBe(1)->and($verification['reason'])->toBe('managed_apply_verification_failed');
    [$replayExit, $replay] = managedApplyReceipt('migrate');
    expect($replayExit)->toBe(1)->and($replay['reason'])->toBe('managed_apply_already_started')
        ->and($this->applyCommand->runs)->toBe(1)->and(app(ManagedUpdateGate::class)->active())->toBeTrue();
});

test('exceptions, pending schema, erasure debt, and mismatched release recording never become complete', function (string $failure): void {
    if ($failure === 'exception') {
        $this->applyCommand->beforeMigration = fn () => throw new RuntimeException('private-provider-password-secret');
    } else {
        $this->applyCommand->afterMigration = function () use ($failure): void {
            match ($failure) {
                'pending' => DB::table('migrations')->where('migration', DB::table('migrations')->value('migration'))->delete(),
                'state' => app(ReleaseState::class)->record('1.1.1', str_repeat('b', 40), '1.1.1', false, 'image'),
                'erasure' => app(ErasureLedger::class)->markReapplyOutstanding(),
            };
        };
    }

    [$exit, $receipt, $raw] = managedApplyReceipt('migrate');
    expect($exit)->toBe(1)->and($receipt['status'])->toBe('failed')
        ->and(json_decode(file_get_contents($this->applyFile), true)['phase'])->toBe('intent')
        ->and(app(ManagedUpdateGate::class)->active())->toBeTrue()->and($raw)->not->toContain('private-', $this->applyRoot);
})->with(['exception', 'pending', 'state', 'erasure']);

test('unknown applied migrations are refused before migration', function (): void {
    DB::table('migrations')->insert(['migration' => '2099_01_01_000000_unknown_future_schema', 'batch' => 1]);
    [$exit, $receipt] = managedApplyReceipt('migrate');
    expect($exit)->toBe(1)->and($receipt['reason'])->toBe('managed_apply_schema_mismatch')
        ->and($this->applyCommand->runs)->toBe(0)->and(file_exists($this->applyFile))->toBeFalse();
});

test('unmet pre-migration requirements stop before intent and unmet after-start work leaves intent', function (string $phase): void {
    $this->applyManifest = ReleaseManifest::build(['actions' => [[
        'id' => 'manual-work', 'summary' => 'private-action-summary-not-a-receipt', 'detail' => 'operator action',
        'phase' => $phase, 'depends_on_release' => 'none',
        'applicability' => ['type' => 'always'], 'verification' => ['type' => 'attest'],
    ]]], '1.2.0', $this->applyTargetCommit);
    file_put_contents($this->applyRoot.'/release.json', json_encode($this->applyManifest)."\n");
    file_put_contents($this->applyRoot.'/history.json', json_encode(['schema' => 1, 'releases' => [$this->applyManifest]])."\n");
    [$exit, $receipt, $raw] = managedApplyReceipt('migrate');

    expect($exit)->toBe(1)->and($raw)->not->toContain('private-action-summary')
        ->and($receipt['reason'])->toBe($phase === 'after-pull' ? 'managed_apply_guard_blocked' : 'managed_apply_verification_failed')
        ->and($this->applyCommand->runs)->toBe($phase === 'after-pull' ? 0 : 1)
        ->and(file_exists($this->applyFile))->toBe($phase === 'after-start');
})->with(['after-pull', 'after-start']);

test('stored receipt tampering and live state drift cannot verify', function (string $tamper): void {
    expect(managedApplyReceipt('migrate')[0])->toBe(0);

    if ($tamper === 'ledger') {
        DB::table('migrations')->increment('batch');
    } elseif ($tamper === 'release') {
        file_put_contents(storage_path('app/release-state.json'), " \n", FILE_APPEND);
    } elseif ($tamper === 'artifact') {
        file_put_contents($this->applyRoot.'/history.json', " \n", FILE_APPEND);
    } else {
        $receipt = json_decode(file_get_contents($this->applyFile), true);
        $receipt['target']['commit'] = str_repeat('d', 40);
        file_put_contents($this->applyFile, json_encode($receipt)."\n");
    }

    [$exit, $receipt] = managedApplyReceipt('verify');
    expect($exit)->toBe(1)->and($receipt['status'])->toBe('failed')
        ->and($this->applyCommand->runs)->toBe(1)->and(app(ManagedUpdateGate::class)->active())->toBeTrue();
})->with(['ledger', 'release', 'artifact', 'receipt']);

test('any existing receipt node prevents migration and unsafe receipt parents are refused', function (string $node): void {
    $directory = dirname($this->applyFile);
    mkdir($directory, 0700, true);

    match ($node) {
        'file' => file_put_contents($this->applyFile, 'not JSON'),
        'directory' => mkdir($this->applyFile, 0700),
        'symlink' => symlink($this->applyRoot.'/missing', $this->applyFile),
        'parent' => (function () use ($directory): void {
            rmdir($directory);
            symlink($this->applyRoot.'/app', $directory);
        })(),
    };
    [$exit, $receipt] = managedApplyReceipt('migrate');
    expect($exit)->toBe(1)->and($receipt['reason'])->toBe($node === 'parent' ? 'managed_apply_receipt_invalid' : 'managed_apply_already_started')
        ->and($this->applyCommand->runs)->toBe(0);
})->with(['file', 'directory', 'symlink', 'parent']);

test('raw schema commands cannot enter a held or corrupt managed window', function (string $command, bool $corrupt): void {
    if ($corrupt) {
        file_put_contents(storage_path('framework/managed-upgrade.json'), 'broken managed marker');
    }

    $output = new BufferedOutput;
    $listener = new ManagedApplyGateListener;

    try {
        $listener->handle(new CommandStarting($command, new ArrayInput(['command' => $command]), $output));
        test()->fail('Raw schema mutation was admitted.');
    } catch (ManagedApplyStopped $stop) {
        expect($stop->status)->toBe(78)->and($output->fetch())->toContain('Managed update maintenance');
    }
})->with(['migrate', 'migrate:fresh', 'migrate:refresh', 'migrate:rollback', 'migrate:reset', 'migrate:install', 'db:wipe'])->with([false, true]);

test('a released or wrong typed lease cannot grant process-local migration permission', function (): void {
    $lease = app(ManagedUpdateGate::class)->acquireProtective($this->applyOperation);
    $lease->release();
    expect(fn () => app(ManagedMigrationContext::class)->during($lease, $this->applyOperation, fn () => 0))
        ->toThrow(RuntimeException::class, 'managed_update_busy')
        ->and(app(ManagedMigrationContext::class)->allows('migrate'))->toBeFalse();
});

test('the production database probe refuses a non-PostgreSQL connection', function (): void {
    $originalDefault = config('database.default');
    $connection = 'managed_apply_probe';
    $originalConnection = config('database.connections.'.$connection);
    config()->set('database.connections.'.$connection, [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.default', $connection);

    try {
        expect(DB::connection()->getDriverName())->toBe('sqlite')
            ->and(DB::connection()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME))->toBe('sqlite');
        expect(fn () => (new ManagedApplyDependencyProbe)->database())
            ->toThrow(RuntimeException::class, 'managed_apply_database_unverified');
    } finally {
        // RefreshDatabase must roll back the original CI PostgreSQL connection.
        config()->set('database.default', $originalDefault);
        DB::purge($connection);
        config()->set('database.connections.'.$connection, $originalConnection);
    }
});

test('the production Redis probe requires an actual PING answer', function (mixed $answer, bool $valid): void {
    $connection = Mockery::mock();
    $connection->shouldReceive('command')->once()->with('ping')->andReturn($answer);
    Redis::shouldReceive('connection')->once()->andReturn($connection);

    if ($valid) {
        expect((new ManagedApplyDependencyProbe)->redis())->toBeNull();
    } else {
        expect(fn () => (new ManagedApplyDependencyProbe)->redis())
            ->toThrow(RuntimeException::class, 'managed_apply_redis_unverified');
    }
})->with([
    [true, true], ['PONG', true], ['+PONG', true],
    [new Status('PONG'), true],
    [new Status('OK'), false],
    [new class implements Stringable
    {
        public function __toString(): string
        {
            return 'PONG';
        }
    }, false],
    [false, false], [null, false], ['OK', false],
]);
