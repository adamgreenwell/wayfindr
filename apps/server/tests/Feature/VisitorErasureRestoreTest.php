<?php

// Erasures survive a restore (ADR 0026 §8). Restoring an archive taken before
// an erasure brings the person back, so each erasure is also written to a
// ledger on the storage volume, which backups do not carry and a restore
// cannot roll back, and the restore erases them again from it.
//
// The "archive" in these tests is a restorer that puts rows back the way a
// dump taken before the erasure would: the same IDs, the old ticket content,
// no ledger row, and the visitor ID sequence where the archive left it.

use App\Enums\AccountRole;
use App\Jobs\RunRestoreJob;
use App\Listeners\ReapplyErasuresAfterMigrating;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorErasure;
use App\Support\Backup\BackupService;
use App\Support\Backup\DatabaseRestorer;
use App\Support\Backup\RestoreService;
use App\Support\Visitors\ErasureLedger;
use App\Support\Visitors\ErasureReapplier;
use App\Support\Visitors\VisitorEraser;
use App\Support\Visitors\VisitorIdentityMerger;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

/** A restorer that stands in for loading a dump: it runs what the archive holds. */
final class ArchiveStandInRestorer implements DatabaseRestorer
{
    public function __construct(private readonly Closure $archive) {}

    public function restore(string $sqlFile): void
    {
        ($this->archive)();
    }
}

/**
 * A contact with a conversation, an attachment on disk and a ticket, on a
 * site that already has a visitor, so the contact's ID is never the first.
 *
 * @return array<string, mixed>
 */
function ledgerFixture(): array
{
    Storage::fake('attachments');

    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create();
    $bystander = Visitor::factory()->for($site)->create();
    $visitor = Visitor::factory()->for($site)->create(['name' => 'Robin Ledger', 'email' => 'robin.ledger@example.test']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create(['subject' => 'Robin needs help']);
    $message = ConversationMessage::factory()->for($conversation)->create(['body' => 'Robin wrote this']);
    $attachment = ConversationMessageAttachment::factory()->pendingFor($conversation, $visitor)->create([
        'conversation_message_id' => $message->id,
    ]);
    Storage::disk('attachments')->put($attachment->storage_key, 'binary');
    $ticket = Ticket::factory()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'requester_id' => $visitor->id,
        'conversation_id' => $conversation->id,
        'subject' => 'Robin cannot log in',
    ]);

    return compact('account', 'admin', 'site', 'bystander', 'visitor', 'conversation', 'message', 'attachment', 'ticket');
}

/**
 * The rows an archive taken now would hold for these tables and IDs.
 *
 * @param  array<string, list<int>>  $rows  table => IDs
 * @return array<string, list<array<string, mixed>>>
 */
function archivedRows(array $rows): array
{
    return collect($rows)
        ->map(fn (array $ids, string $table): array => DB::table($table)->whereIn('id', $ids)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all())
        ->all();
}

/**
 * Put archived rows back as a dump would: rows that are gone are inserted
 * under their own IDs, and rows that survived get their old content back.
 *
 * @param  array<string, list<array<string, mixed>>>  $archived
 */
function putArchivedRowsBack(array $archived): void
{
    foreach ($archived as $table => $rows) {
        foreach ($rows as $row) {
            DB::table($table)->updateOrInsert(['id' => $row['id']], $row);
        }
    }
}

/** Set the visitor ID sequence back, the way importing an older dump does. */
function rewindVisitorSequenceTo(int $value): void
{
    if (DB::getDriverName() === 'pgsql') {
        DB::select("select setval(pg_get_serial_sequence('visitors', 'id'), ?)", [$value]);

        return;
    }

    DB::table('sqlite_sequence')->where('name', 'visitors')->update(['seq' => $value]);
}

function restoreArchive(Closure $archive, array $files = [], bool $asLongLivedWorker = false): PendingCommand
{
    app()->instance(DatabaseRestorer::class, new ArchiveStandInRestorer($archive));

    // A queue worker runs the in-app restore and keeps its services after.
    if ($asLongLivedWorker) {
        app()->instance(RestoreService::class, app(RestoreService::class));
    }

    $src = sys_get_temp_dir().'/wf-erasure-restore-src-'.bin2hex(random_bytes(6));
    mkdir($src, 0700, true);
    file_put_contents($src.'/database.sql', "-- dump\n");
    file_put_contents($src.'/manifest.json', json_encode([
        'wayfindr_version' => 'v1',
        'local_attachment_disks' => $files === [] ? [] : ['attachments'],
    ]));

    foreach ($files as $key => $bytes) {
        @mkdir(dirname($src.'/attachments/attachments/'.$key), 0700, true);
        file_put_contents($src.'/attachments/attachments/'.$key, $bytes);
    }

    $dir = sys_get_temp_dir().'/wf-erasure-restore-arc-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    $archivePath = $dir.'/wayfindr-backup-test.tar.gz';
    exec('tar -czf '.escapeshellarg($archivePath).' -C '.escapeshellarg($src).' .');
    exec('rm -rf '.escapeshellarg($src));

    return test()->artisan('wayfindr:restore', ['archive' => $archivePath, '--force' => true]);
}

function ledgerPath(): string
{
    return app(ErasureLedger::class)->path();
}

/** @return list<string> */
function ledgerFiles(string $suffix): array
{
    $files = glob(ledgerPath().'/*'.$suffix) ?: [];
    sort($files);

    return array_map('basename', $files);
}

test('an erasure is on the volume before it changes anything, and committed once it has', function (): void {
    $f = ledgerFixture();
    $seen = null;

    DB::listen(function ($query) use (&$seen): void {
        if ($seen === null && preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
            $seen = ['pending' => ledgerFiles('.pending.json'), 'committed' => ledgerFiles('.json')];
        }
    });

    $receipt = app(VisitorEraser::class)->erase($f['admin'], $f['visitor']);

    expect($seen['pending'])->toBe([$receipt->public_id.'.pending.json'], 'the first change was made before the ledger held the erasure')
        ->and(ledgerFiles('.pending.json'))->toBe([], 'a committed erasure was left pending')
        ->and(ledgerFiles('.json'))->toBe([$receipt->public_id.'.json']);

    $entry = app(ErasureLedger::class)->find($receipt->public_id);
    $raw = (string) file_get_contents(ledgerPath().'/'.$receipt->public_id.'.json');

    expect($entry)->toMatchArray([
        'account_id' => (int) $f['account']->id,
        'site_id' => (int) $f['site']->id,
        'site_public_key' => $f['site']->public_key,
        'erased_visitor_id' => (int) $f['visitor']->id,
        'actor_id' => (int) $f['admin']->id,
    ])
        ->and(str_contains($raw, 'Robin'))->toBeFalse('the ledger on the volume holds the person\'s name')
        ->and(str_contains($raw, 'robin.ledger@example.test'))->toBeFalse('the ledger on the volume holds the person\'s email');
});

test('an erasure the ledger cannot record erases nothing', function (): void {
    $f = ledgerFixture();
    $blocker = sys_get_temp_dir().'/wf-ledger-blocker-'.bin2hex(random_bytes(6));
    file_put_contents($blocker, 'not a directory');
    config()->set('wayfindr.erasure.ledger_path', $blocker.'/erasure-ledger');

    $this->actingAs($f['admin'])
        ->post(route('dashboard.visitors.erasure.store', $f['visitor']), ['confirmation' => 'ERASE', 'current_password' => 'password'])
        ->assertSessionHasErrors(['confirmation' => __('visitor_erasure.errors.ledger_unwritable')]);

    @unlink($blocker);

    expect(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeTrue('the person was erased without a ledger entry')
        ->and($f['ticket']->fresh()->subject)->toBe('Robin cannot log in', 'the ticket was stripped without a ledger entry')
        ->and(VisitorErasure::query()->count())->toBe(0);
});

test('an erasure that refuses after writing its entry takes the entry back', function (): void {
    $f = ledgerFixture();
    // An alert mail on its way to the mail server is only found once the
    // tickets are locked, after the entry is on the volume.
    DB::table('alert_mail_sends')->insert([
        'subject_type' => (new Conversation)->getMorphClass(),
        'subject_id' => $f['conversation']->id,
        'started_at' => now(),
    ]);

    $this->actingAs($f['admin'])
        ->post(route('dashboard.visitors.erasure.store', $f['visitor']), ['confirmation' => 'ERASE', 'current_password' => 'password'])
        ->assertSessionHasErrors(['confirmation' => __('visitor_erasure.errors.alert_mail_sending')]);

    expect(ledgerFiles('.json'))->toBe([], 'an erasure that never happened is on the volume');
});

test('reconciliation keeps a pending entry whose erasure committed, and drops one that did not', function (): void {
    $f = ledgerFixture();
    $ledger = app(ErasureLedger::class);
    $committed = app(VisitorEraser::class)->erase($f['admin'], $f['visitor'])->public_id;
    // As if the rename after the commit never happened.
    rename(ledgerPath()."/{$committed}.json", ledgerPath()."/{$committed}.pending.json");
    $neverHappened = (string) Str::uuid();
    $ledger->writePending(ErasureLedger::entry($neverHappened, (int) $f['account']->id, $f['site'], (int) $f['bystander']->id, [], null, now()->toIso8601ZuluString(), []));

    DB::flushQueryLog();
    DB::enableQueryLog();
    $settled = $ledger->reconcile();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($settled)->toBe(['promoted' => [$committed], 'discarded' => [$neverHappened], 'unconfirmed' => []], 'reconciliation settled an entry the wrong way')
        ->and(ledgerFiles('.pending.json'))->toBe([])
        ->and(ledgerFiles('.json'))->toBe([$committed.'.json']);

    // It waits for the site the erasure locks before it asks for the receipt.
    $lock = $queries->search(fn (string $sql): bool => str_contains($sql, '"sites"'));
    $ask = $queries->search(fn (string $sql): bool => str_contains($sql, '"visitor_erasures"'));

    expect($lock)->toBeInt('reconciliation asked for the receipt without waiting for the erasure\'s site')
        ->toBeLessThan($ask);

    if (DB::getDriverName() === 'pgsql') {
        expect(str_contains($queries[$lock], 'for update'))->toBeTrue('reconciliation read the site without waiting for its lock');
    }
});

test('a database that cannot answer keeps entries pending, and a restore keeps them as done', function (): void {
    $f = ledgerFixture();
    $ledger = app(ErasureLedger::class);
    $receipt = (string) Str::uuid();
    $ledger->writePending(ErasureLedger::entry($receipt, (int) $f['account']->id, $f['site'], (int) $f['visitor']->id, [], null, now()->toIso8601ZuluString(), []));
    Schema::drop('visitor_erasures');

    expect($ledger->reconcile())->toBe(['promoted' => [], 'discarded' => [], 'unconfirmed' => []])
        ->and(ledgerFiles('.pending.json'))->toBe([$receipt.'.pending.json'], 'an entry was settled by a database that could not say');

    expect($ledger->reconcile(assumeCommitted: true)['unconfirmed'])->toBe([$receipt], 'a restore did not keep an erasure it could not confirm')
        ->and(ledgerFiles('.json'))->toBe([$receipt.'.json'], 'a restore dropped an erasure it could not confirm');
});

test('the volume is backfilled from receipts recorded before it held the ledger', function (): void {
    $f = ledgerFixture();
    $receipt = app(VisitorEraser::class)->erase($f['admin'], $f['visitor'])->public_id;
    exec('rm -rf '.escapeshellarg(ledgerPath()));

    $this->artisan('wayfindr:finish-erasures')
        ->expectsOutputToContain('Erasures newly recorded in the ledger on the storage volume: 1.')
        ->assertSuccessful();

    expect(app(ErasureLedger::class)->find($receipt))->toMatchArray([
        'erased_visitor_id' => (int) $f['visitor']->id,
        'site_public_key' => $f['site']->public_key,
    ]);

    $this->artisan('wayfindr:finish-erasures')
        ->doesntExpectOutputToContain('newly recorded')
        ->assertSuccessful();
});

test('the scheduled run removes a file only the volume still lists', function (): void {
    $f = ledgerFixture();
    $receipt = (string) Str::uuid();
    // The row that listed it went back in time with a restore; the volume
    // entry did not.
    app(ErasureLedger::class)->record(ErasureLedger::entry(
        $receipt, (int) $f['account']->id, $f['site'], (int) $f['visitor']->id, [], null, now()->toIso8601ZuluString(),
        [['disk' => 'attachments', 'key' => 'erased/left-behind.png']],
    ));
    Storage::disk('attachments')->put('erased/left-behind.png', 'binary');

    $this->artisan('wayfindr:finish-erasures')->assertSuccessful();

    expect(Storage::disk('attachments')->exists('erased/left-behind.png'))->toBeFalse('a file only the volume listed was left on disk')
        ->and(app(ErasureLedger::class)->find($receipt)['pending_files'])->toBe([], 'a removed file is still listed on the volume');
});

test('a restore from before an erasure erases the person again, and their files', function (): void {
    $f = ledgerFixture();
    $archived = archivedRows([
        'visitors' => [(int) $f['visitor']->id],
        'conversations' => [(int) $f['conversation']->id],
        'conversation_messages' => [(int) $f['message']->id],
        'conversation_message_attachments' => [(int) $f['attachment']->id],
        'tickets' => [(int) $f['ticket']->id],
    ]);
    $receipt = app(VisitorEraser::class)->erase($f['admin'], $f['visitor'])->public_id;

    restoreArchive(function () use ($archived): void {
        putArchivedRowsBack($archived);
        DB::table('visitor_erasures')->delete();
    }, files: [$f['attachment']->storage_key => 'binary'])
        ->expectsOutputToContain('Erasures re-applied: 1 contact(s), from 1 of the 1 erasure(s) in the ledger.')
        ->assertSuccessful();

    expect(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeFalse('the restore brought the person back for good')
        ->and(Conversation::query()->whereKey($f['conversation']->id)->exists())->toBeFalse()
        ->and($f['ticket']->fresh()->subject)->not->toContain('Robin', 'the restored ticket kept the person\'s words')
        ->and(Storage::disk('attachments')->exists($f['attachment']->storage_key))->toBeFalse('the restored file of an erased person is still on disk')
        ->and(VisitorErasure::query()->where('public_id', $receipt)->count())->toBe(1, 'the ledger row did not come back under its receipt')
        ->and(AuditEvent::query()->where('action', 'visitor.erasure_reapplied')->sole()->metadata['receipt'])->toBe($receipt)
        ->and(app(ErasureLedger::class)->reapplyOutstanding())->toBeFalse('a finished re-application is still outstanding');
});

test('a restore from before a merge erases every contact the person was merged from', function (): void {
    $f = ledgerFixture();
    $source = Visitor::factory()->for($f['site'])->create(['anonymous_id' => null, 'external_id' => null, 'email' => null]);
    $archived = archivedRows(['visitors' => [(int) $source->id, (int) $f['visitor']->id]]);
    app(VisitorIdentityMerger::class)->merge($f['admin'], $source, (int) $f['visitor']->id);
    app(VisitorEraser::class)->erase($f['admin'], $f['visitor']->refresh());

    restoreArchive(fn () => putArchivedRowsBack($archived))
        ->expectsOutputToContain('Erasures re-applied: 2 contact(s)')
        ->assertSuccessful();

    expect(Visitor::query()->whereKey([$source->id, $f['visitor']->id])->count())->toBe(0, 'a contact merged into the erased person came back');
});

test('a restore moves the visitor ID sequence past every erased ID', function (): void {
    $f = ledgerFixture();
    $erasedId = (int) $f['visitor']->id;
    app(VisitorEraser::class)->erase($f['admin'], $f['visitor']);

    // An archive from before the person existed: no row, and a sequence that
    // would hand their ID to the next visitor.
    restoreArchive(fn () => rewindVisitorSequenceTo($erasedId - 1))->assertSuccessful();

    expect((int) Visitor::factory()->for($f['site'])->create()->id)->toBeGreaterThan($erasedId, 'a new visitor took an erased person\'s ID');
});

test('an archive from another install is not erased from, even under the same IDs', function (): void {
    $f = ledgerFixture();
    $archived = archivedRows(['visitors' => [(int) $f['visitor']->id]]);
    app(VisitorEraser::class)->erase($f['admin'], $f['visitor']);

    restoreArchive(function () use ($archived, $f): void {
        DB::table('sites')->where('id', $f['site']->id)->update(['public_key' => 'site_from_elsewhere']);
        putArchivedRowsBack($archived);
    })
        ->expectsOutputToContain('Erasures re-applied: 0 contact(s)')
        ->assertSuccessful();

    expect(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeTrue('someone in another install\'s archive was erased');
});

test('a pending entry the restore settles as committed is re-applied', function (): void {
    $f = ledgerFixture();
    $archived = archivedRows(['visitors' => [(int) $f['visitor']->id]]);
    $receipt = app(VisitorEraser::class)->erase($f['admin'], $f['visitor'])->public_id;
    rename(ledgerPath()."/{$receipt}.json", ledgerPath()."/{$receipt}.pending.json");

    restoreArchive(fn () => putArchivedRowsBack($archived))
        ->expectsOutputToContain('Erasures re-applied: 1 contact(s)')
        ->assertSuccessful();

    expect(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeFalse();
});

test('an erasure that lands while a restore is getting ready is re-applied, not lost', function (): void {
    $f = ledgerFixture();
    $archived = archivedRows(['visitors' => [(int) $f['visitor']->id]]);
    $receipt = (string) Str::uuid();

    restoreArchive(function () use ($archived, $f, $receipt): void {
        // An erasure that wrote its entry after the restore's first look and
        // committed to the database the load then replaced: its row is gone
        // with that database, and it never got to promote its entry.
        app(ErasureLedger::class)->writePending(ErasureLedger::entry(
            $receipt, (int) $f['account']->id, $f['site'], (int) $f['visitor']->id, [], (int) $f['admin']->id, now()->toIso8601ZuluString(), [],
        ));
        putArchivedRowsBack($archived);
    })
        ->expectsOutputToContain("The replaced database could not confirm these erasures, so they are treated as done: {$receipt}")
        ->expectsOutputToContain('Erasures re-applied: 1 contact(s)')
        ->assertSuccessful();

    expect(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeFalse('an erasure made during the restore was lost to it')
        ->and(ledgerFiles('.pending.json'))->toBe([])
        ->and(app(ErasureLedger::class)->find($receipt))->not->toBeNull();
});

test('the serving gate is up before a restore takes its first look at the ledger', function (): void {
    ledgerFixture();
    $gateUp = null;

    DB::listen(function ($query) use (&$gateUp): void {
        if ($gateUp === null && str_contains($query->sql, 'visitor_erasures')) {
            $gateUp = app(ErasureLedger::class)->reapplyOutstanding();
        }
    });

    restoreArchive(fn () => null)->assertSuccessful();

    expect($gateUp)->toBeTrue('a restore looked at the ledger while erasures could still start');
});

test('an archive behind the running code is erased from once migrations have run', function (): void {
    $f = ledgerFixture();
    $archived = archivedRows(['visitors' => [(int) $f['visitor']->id]]);
    app(VisitorEraser::class)->erase($f['admin'], $f['visitor']);
    $latest = '2026_09_30_140000_create_alert_mail_sends_table';

    restoreArchive(function () use ($archived, $latest): void {
        putArchivedRowsBack($archived);
        DB::table('migrations')->where('migration', $latest)->delete();
    })
        ->expectsOutputToContain('Erasures are re-applied once the restored schema matches this code')
        ->assertSuccessful();

    expect(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeTrue('erasing ran against a schema the code has not migrated')
        ->and(app(ErasureLedger::class)->reapplyOutstanding())->toBeTrue();

    // What `migrate` leaves behind, then what it announces when it finishes.
    DB::table('migrations')->insert(['migration' => $latest, 'batch' => 99]);
    $output = new BufferedOutput;
    event(new CommandFinished('migrate', new ArrayInput([]), $output, 0));

    expect(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeFalse('migrating did not finish the re-application')
        ->and($output->fetch())->toContain('Erasures re-applied after the restore: 1 contact(s)')
        ->and(app(ErasureLedger::class)->reapplyOutstanding())->toBeFalse();
});

test('only a restore can start a re-application, so an ID reused outside one is never erased', function (): void {
    $f = ledgerFixture();
    $archived = archivedRows(['visitors' => [(int) $f['visitor']->id]]);
    app(VisitorEraser::class)->erase($f['admin'], $f['visitor']);

    // Rows put back some other way, with no restore to move the sequence.
    putArchivedRowsBack($archived);
    $this->artisan('wayfindr:finish-erasures')->assertSuccessful();
    event(new CommandFinished('migrate', new ArrayInput([]), new BufferedOutput, 0));

    expect(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeTrue('a visitor was erased by ID with no restore behind it');
});

test('the scheduled run finishes a re-application a restore left outstanding', function (): void {
    $f = ledgerFixture();
    $archived = archivedRows(['visitors' => [(int) $f['visitor']->id]]);
    app(VisitorEraser::class)->erase($f['admin'], $f['visitor']);
    putArchivedRowsBack($archived);
    app(ErasureLedger::class)->markReapplyOutstanding();

    $this->artisan('wayfindr:finish-erasures')
        ->expectsOutputToContain('Erasures re-applied after a restore: 1 contact(s).')
        ->assertSuccessful();

    expect(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeFalse();
});

test('re-applying the same erasure twice changes nothing the second time', function (): void {
    $f = ledgerFixture();
    $archived = archivedRows(['visitors' => [(int) $f['visitor']->id]]);
    $receipt = app(VisitorEraser::class)->erase($f['admin'], $f['visitor'])->public_id;
    putArchivedRowsBack($archived);
    $entry = app(ErasureLedger::class)->find($receipt);

    expect(app(VisitorEraser::class)->reapply($entry))->toBe(1)
        ->and(app(VisitorEraser::class)->reapply($entry))->toBe(0)
        ->and(VisitorErasure::query()->where('public_id', $receipt)->count())->toBe(1);
});

test('a restore onto a volume that never held the ledger says what it cannot re-apply', function (): void {
    ledgerFixture();
    exec('rm -rf '.escapeshellarg(ledgerPath()));

    restoreArchive(fn () => null)
        ->expectsOutputToContain('This storage volume held no erasure ledger')
        ->assertSuccessful();

    expect(app(ErasureLedger::class)->exists())->toBeTrue();
});

test('a restore that stops before replacing anything leaves nothing to re-apply', function (): void {
    ledgerFixture();

    restoreArchive(fn () => throw new RuntimeException('psql would not start'))
        ->expectsOutputToContain('psql would not start')
        ->assertFailed();

    expect(app(ErasureLedger::class)->reapplyOutstanding())->toBeFalse();
});

test('a restore whose ledger cannot be settled takes its serving gate down again', function (): void {
    $f = ledgerFixture();
    $receipt = app(VisitorEraser::class)->erase($f['admin'], $f['visitor'])->public_id;
    // Backfilling this receipt will fail: its file's name is taken by a directory.
    unlink(ledgerPath()."/{$receipt}.json");
    mkdir(ledgerPath()."/{$receipt}.json");
    $loaded = false;

    restoreArchive(function () use (&$loaded): void {
        $loaded = true;
    })
        ->expectsOutputToContain('The erasure ledger could not be settled, so nothing was restored')
        ->assertFailed();

    expect($loaded)->toBeFalse()
        ->and(app(ErasureLedger::class)->reapplyOutstanding())->toBeFalse('a restore that replaced nothing left the site refusing traffic');
});

test('recovery leaves a restore that is under way alone', function (): void {
    ledgerFixture();
    // A restore between its first look at the ledger and its load: gate up,
    // lock held, nothing replaced yet.
    $restoring = app(ErasureReapplier::class);
    $restoring->beforeRestore();

    $this->artisan('wayfindr:finish-erasures')->assertSuccessful();
    event(new CommandFinished('migrate', new ArrayInput([]), new BufferedOutput, 0));

    expect(app(ErasureLedger::class)->reapplyOutstanding())->toBeTrue('recovery lifted the gate of a restore still under way');

    $restoring->abandon();
    $restoring->finishRestore();

    expect(app(ErasureLedger::class)->reapplyOutstanding())->toBeFalse();
});

test('a finished restore lets recovery run, though the worker that ran it lives on', function (): void {
    $f = ledgerFixture();
    restoreArchive(fn () => null, asLongLivedWorker: true)->assertSuccessful();

    $archived = archivedRows(['visitors' => [(int) $f['visitor']->id]]);
    app(VisitorEraser::class)->erase($f['admin'], $f['visitor']);
    putArchivedRowsBack($archived);
    app(ErasureLedger::class)->markReapplyOutstanding();

    $this->artisan('wayfindr:finish-erasures')->assertSuccessful();

    expect(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeFalse('a finished restore kept recovery locked out');
});

test('an erasure the database cannot answer for stays pending when the load fails', function (): void {
    $f = ledgerFixture();
    $receipt = (string) Str::uuid();
    app(ErasureLedger::class)->writePending(ErasureLedger::entry(
        $receipt, (int) $f['account']->id, $f['site'], (int) $f['visitor']->id, [], null, now()->toIso8601ZuluString(), [],
    ));
    // Unanswerable: as a database older than the ledger, or one that drops
    // the connection, would be.
    Schema::drop('visitor_erasures');

    restoreArchive(fn () => throw new RuntimeException('psql would not start'))->assertFailed();

    expect(ledgerFiles('.pending.json'))->toBe([$receipt.'.pending.json'], 'a failed restore kept an erasure as done that may never have happened')
        ->and(app(ErasureLedger::class)->find($receipt))->toBeNull();
});

test('a restore that stops early leaves an earlier restore\'s re-application outstanding', function (): void {
    ledgerFixture();
    app(ErasureLedger::class)->markReapplyOutstanding();

    restoreArchive(fn () => throw new RuntimeException('psql would not start'))->assertFailed();

    expect(app(ErasureLedger::class)->reapplyOutstanding())->toBeTrue('a failed restore dropped the work an earlier one left');
});

test('a failed re-application is said, and stays outstanding for the next run', function (): void {
    $f = ledgerFixture();
    $archived = archivedRows(['visitors' => [(int) $f['visitor']->id], 'conversations' => [(int) $f['conversation']->id]]);
    app(VisitorEraser::class)->erase($f['admin'], $f['visitor']);

    restoreArchive(function () use ($archived, $f): void {
        putArchivedRowsBack($archived);
        // A copilot request the archive caught mid-flight holds the erasure.
        DB::table('conversation_copilot_summaries')->insert([
            'conversation_id' => $f['conversation']->id, 'requested_by_id' => $f['admin']->id,
            'generation' => (string) Str::uuid(), 'status' => 'running',
            'requested_at' => now()->subSeconds(12), 'started_at' => now()->subSeconds(10),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    })
        ->expectsOutputToContain('Erasures could NOT all be re-applied')
        ->assertFailed();

    expect(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeTrue()
        ->and(app(ErasureLedger::class)->reapplyOutstanding())->toBeTrue('a failed re-application will never be retried');
});

test('the post-migrate hook is quiet when no restore is outstanding', function (): void {
    $output = new BufferedOutput;

    app(ReapplyErasuresAfterMigrating::class)->handle(new CommandFinished('migrate', new ArrayInput([]), $output, 0));

    expect($output->fetch())->toBe('')
        ->and(app(ErasureReapplier::class)->reapplyOutstanding())->toBeNull();
});

test('a ledger inside an attachment disk is refused before anything is erased or restored', function (): void {
    $f = ledgerFixture();
    $root = sys_get_temp_dir().'/wf-attachments-root-'.bin2hex(random_bytes(6));
    config()->set('filesystems.disks.attachments.root', $root);
    config()->set('wayfindr.erasure.ledger_path', $root.'/erasure-ledger');

    $this->actingAs($f['admin'])
        ->post(route('dashboard.visitors.erasure.store', $f['visitor']), ['confirmation' => 'ERASE', 'current_password' => 'password'])
        ->assertSessionHasErrors(['confirmation' => __('visitor_erasure.errors.ledger_unwritable')]);

    expect(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeTrue('an erasure was recorded where a restore would purge it');

    $loaded = false;
    restoreArchive(function () use (&$loaded): void {
        $loaded = true;
    })
        ->expectsOutputToContain('is inside the attachment disk [attachments]')
        ->assertFailed();

    expect($loaded)->toBeFalse('the restore replaced the database with its ledger inside a disk it purges');
});

test('an unreadable ledger entry nothing can repair keeps the restore from reporting success', function (): void {
    $f = ledgerFixture();
    $archived = archivedRows(['visitors' => [(int) $f['visitor']->id]]);
    $receipt = app(VisitorEraser::class)->erase($f['admin'], $f['visitor'])->public_id;
    file_put_contents(ledgerPath()."/{$receipt}.json", '{"receipt": ');
    // With its row, the restore would repair it first; without, nothing can.
    DB::table('visitor_erasures')->delete();

    restoreArchive(function () use ($archived): void {
        putArchivedRowsBack($archived);
        DB::table('visitor_erasures')->delete();
    })
        ->expectsOutputToContain("cannot be read: {$receipt}.json")
        ->assertFailed();

    expect(app(ErasureLedger::class)->reapplyOutstanding())->toBeTrue('an erasure nobody could read was treated as done');
});

test('an unreadable ledger entry is repaired from its row', function (): void {
    $f = ledgerFixture();
    $receipt = app(VisitorEraser::class)->erase($f['admin'], $f['visitor'])->public_id;
    file_put_contents(ledgerPath()."/{$receipt}.json", '{"receipt": ');

    $this->artisan('wayfindr:finish-erasures')->assertSuccessful();

    expect(app(ErasureLedger::class)->find($receipt))->toMatchArray(['erased_visitor_id' => (int) $f['visitor']->id], 'an unreadable entry was left for a restore to trip over');
});

test('the scheduled run says which ledger entries it cannot read or rebuild', function (): void {
    $f = ledgerFixture();
    $receipt = app(VisitorEraser::class)->erase($f['admin'], $f['visitor'])->public_id;
    file_put_contents(ledgerPath()."/{$receipt}.json", '{"receipt": ');
    DB::table('visitor_erasures')->delete();

    $this->artisan('wayfindr:finish-erasures')
        ->expectsOutputToContain("have no ledger row to rebuild them from: {$receipt}.json")
        ->assertFailed();
});

test('an archive from a newer release waits for that release', function (): void {
    $f = ledgerFixture();
    $archived = archivedRows(['visitors' => [(int) $f['visitor']->id]]);
    app(VisitorEraser::class)->erase($f['admin'], $f['visitor']);

    restoreArchive(function () use ($archived): void {
        putArchivedRowsBack($archived);
        DB::table('migrations')->insert(['migration' => '2099_01_01_000000_from_a_newer_release', 'batch' => 99]);
    })
        ->expectsOutputToContain('Erasures are re-applied once the restored schema matches this code')
        ->assertSuccessful();

    expect(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeTrue('this code erased from a schema with tables it does not know')
        ->and(app(ErasureLedger::class)->reapplyOutstanding())->toBeTrue();
});

test('nothing is served while a restore\'s erasures are outstanding, except the health check', function (): void {
    $this->get('/up')->assertOk();
    expect($this->get('/login')->status())->not->toBe(503);

    app(ErasureLedger::class)->markReapplyOutstanding();

    $this->get('/login')
        ->assertStatus(503)
        ->assertSee('contacts erased since it was taken have not been erased again', false);
    $this->get('/up')->assertOk();

    app(ErasureLedger::class)->clearReapplyOutstanding();

    expect($this->get('/login')->status())->not->toBe(503, 'the site stayed down after the erasures were re-applied');
});

test('an erasure whose site was purged is re-applied by the site\'s key', function (): void {
    $f = ledgerFixture();
    $archived = archivedRows(['visitors' => [(int) $f['visitor']->id]]);
    $receipt = app(VisitorEraser::class)->erase($f['admin'], $f['visitor'])->public_id;
    // As a site purge leaves the row, and with the volume's copy lost.
    DB::table('visitor_erasures')->where('public_id', $receipt)->update(['site_id' => null]);
    exec('rm -rf '.escapeshellarg(ledgerPath()));

    restoreArchive(fn () => putArchivedRowsBack($archived))
        ->expectsOutputToContain('Erasures re-applied: 1 contact(s)')
        ->assertSuccessful();

    expect(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeFalse();
});

test('an erasure that cannot prove its site holds the restore open until the operator vouches for it', function (): void {
    $f = ledgerFixture();
    $receipt = (string) Str::uuid();
    $entry = ErasureLedger::entry($receipt, (int) $f['account']->id, $f['site'], (int) $f['visitor']->id, [], null, now()->toIso8601ZuluString(), []);
    $entry['site_public_key'] = null;
    app(ErasureLedger::class)->record($entry);
    app(ErasureLedger::class)->markReapplyOutstanding();

    $this->artisan('wayfindr:finish-erasures')
        ->expectsOutputToContain("name contacts this restore brought back: {$receipt}. If those contacts are this installation's, run php artisan wayfindr:finish-erasures --vouch={$receipt}")
        ->assertFailed();

    expect(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeTrue('a contact was erased on an ID match alone')
        ->and(app(ErasureLedger::class)->reapplyOutstanding())->toBeTrue('a contact nobody could verify was served again')
        ->and(app(VisitorEraser::class)->reapply($entry))->toBe(0, 're-applying an entry with no site key erased by ID alone')
        ->and(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeTrue();

    $this->artisan('wayfindr:finish-erasures', ['--vouch' => [$receipt]])
        ->expectsOutputToContain("Erasure {$receipt} is recorded as this installation's.")
        ->assertSuccessful();

    expect(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeFalse('a vouched-for erasure was not re-applied')
        ->and(app(ErasureLedger::class)->find($receipt)['site_public_key'])->toBe($f['site']->public_key)
        ->and(app(ErasureLedger::class)->reapplyOutstanding())->toBeFalse();
});

test('a restore that stopped after its load leaves no pending entry for a later run to discard', function (): void {
    $f = ledgerFixture();
    $archived = archivedRows(['visitors' => [(int) $f['visitor']->id]]);
    $receipt = app(VisitorEraser::class)->erase($f['admin'], $f['visitor'])->public_id;
    // What a restore that loaded and then failed, before settling, leaves:
    // its gate up, the erasure's row gone with the replaced database, its
    // entry still pending, and the person back.
    rename(ledgerPath()."/{$receipt}.json", ledgerPath()."/{$receipt}.pending.json");
    DB::table('visitor_erasures')->delete();
    putArchivedRowsBack($archived);
    app(ErasureLedger::class)->markReapplyOutstanding();

    $this->artisan('wayfindr:finish-erasures')->assertSuccessful();

    expect(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeFalse('the recovery discarded an erasure the restore had not settled')
        ->and(app(ErasureLedger::class)->find($receipt))->not->toBeNull();
});

test('a restore after one that stopped short keeps the pending entries it left', function (): void {
    $f = ledgerFixture();
    $archived = archivedRows(['visitors' => [(int) $f['visitor']->id]]);
    $receipt = app(VisitorEraser::class)->erase($f['admin'], $f['visitor'])->public_id;
    rename(ledgerPath()."/{$receipt}.json", ledgerPath()."/{$receipt}.pending.json");
    DB::table('visitor_erasures')->delete();
    app(ErasureLedger::class)->markReapplyOutstanding();

    restoreArchive(fn () => putArchivedRowsBack($archived))
        ->expectsOutputToContain('Erasures re-applied: 1 contact(s)')
        ->assertSuccessful();

    expect(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeFalse('a second restore discarded what the first left pending');
});

test('ledger rows written before the site key was kept get it from their site', function (): void {
    $f = ledgerFixture();
    $receipt = app(VisitorEraser::class)->erase($f['admin'], $f['visitor'])->public_id;
    $migration = require database_path('migrations/2026_10_01_090000_add_site_public_key_to_visitor_erasures.php');
    $migration->down();
    $migration->up();

    expect(VisitorErasure::query()->where('public_id', $receipt)->value('site_public_key'))->toBe($f['site']->public_key, 'an existing row was left without its site\'s key');
});

test('a damaged pending entry whose erasure committed is rebuilt from its row', function (): void {
    $f = ledgerFixture();
    $receipt = app(VisitorEraser::class)->erase($f['admin'], $f['visitor'])->public_id;
    rename(ledgerPath()."/{$receipt}.json", ledgerPath()."/{$receipt}.pending.json");
    file_put_contents(ledgerPath()."/{$receipt}.pending.json", '{"receipt": ');

    $this->artisan('wayfindr:finish-erasures')->assertSuccessful();

    expect(ledgerFiles('.pending.json'))->toBe([], 'a damaged pending entry was left to block every restore')
        ->and(app(ErasureLedger::class)->find($receipt))->toMatchArray(['erased_visitor_id' => (int) $f['visitor']->id]);
});

test('the in-app restore keeps the site down until erasures are re-applied', function (array $erasures, bool $down, string $message): void {
    config()->set('app.maintenance.driver', 'cache');
    config()->set('app.maintenance.store', 'array');
    config()->set('wayfindr.backup.restore_drain_seconds', 0);

    $this->mock(BackupService::class)->shouldReceive('resolveLocalArchivePath')->andReturn('/backups/wayfindr-backup-test.tar.gz');
    $this->mock(RestoreService::class)->shouldReceive('restore')->andReturn([
        'archive_version' => 'v1.1.0',
        'running_version' => 'v1.1.0',
        'version_skew' => false,
        'version_indeterminate' => false,
        'app_key_skew' => false,
        'app_key_indeterminate' => false,
        'integrity' => ['skipped' => false, 'verified' => 0, 'dangling' => [], 'external' => []],
        'erasures' => ['fresh_volume' => false, 'unconfirmed' => [], 'entries' => 1, 'reapplied' => 1, 'visitors' => 1, 'deferred' => false, 'failed' => null, ...$erasures],
    ]);

    (new RunRestoreJob('wayfindr-backup-test.tar.gz', pendingToken: RunRestoreJob::claimPending()))
        ->handle(app(RestoreService::class), app(BackupService::class));

    expect(app()->isDownForMaintenance())->toBe($down, $down ? 'the site came back up with erased contacts restored' : 'a clean restore left the site down')
        ->and(Cache::get(RunRestoreJob::STATUS_KEY)['message'])->toContain($message);
})->with([
    're-applied' => [[], false, 'Erasures re-applied: 1 contact(s), from 1 of the 1 erasure(s) in the ledger.'],
    'failed' => [['reapplied' => 0, 'visitors' => 0, 'failed' => 'held by a copilot request'], true, 'Erasures could NOT all be re-applied'],
    'waiting on migrations' => [['reapplied' => 0, 'visitors' => 0, 'deferred' => true], true, 'Erasures are re-applied once the restored schema matches this code'],
]);
