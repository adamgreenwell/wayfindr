<?php

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Site;
use App\Models\Ticket;
use App\Support\Reporting\TicketReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const STAMP = 'reporting.ticket_lifecycle_recording_began_at';

function runStampMigration(): void
{
    // Required rather than resolved: the migration has already run against the
    // empty schema RefreshDatabase built, and what is under test is what it
    // does to a database that already has history.
    $migration = require database_path('migrations/2026_08_23_170000_record_when_ticket_lifecycle_recording_began.php');

    $migration->up();
}

function stampedValue(): ?string
{
    return DB::table('operator_settings')->where('key', STAMP)->value('value');
}

function aTicket(): Ticket
{
    $account = Account::factory()->create();

    return Ticket::factory()->for($account)->for(Site::factory()->for($account))->create();
}

it('writes nothing on an install with no tickets', function (): void {
    runStampMigration();

    // Not merely tidiness. `wayfindr:restore` reads a non-empty database as
    // populated and refuses to overwrite it without --force, so a migration
    // that unconditionally inserts a row breaks restore-into-a-fresh-install.
    expect(stampedValue())->toBeNull();
});

it('stamps the earliest recorded ticket event on an install already recording', function (): void {
    $ticket = aTicket();

    AuditEvent::factory()->create([
        'account_id' => $ticket->account_id,
        'site_id' => $ticket->site_id,
        'subject_type' => $ticket->getMorphClass(),
        'subject_id' => $ticket->id,
        'action' => TicketReport::CLOSED,
        'occurred_at' => now()->subDays(90),
    ]);

    AuditEvent::factory()->create([
        'account_id' => $ticket->account_id,
        'site_id' => $ticket->site_id,
        'subject_type' => $ticket->getMorphClass(),
        'subject_id' => $ticket->id,
        'action' => TicketReport::REOPENED,
        'occurred_at' => now()->subDays(30),
    ]);

    runStampMigration();

    // The earliest of the two, not the latest: everything from that moment on
    // is on record, and claiming a later date would discard measurable work.
    expect(stampedValue())->toStartWith(now()->subDays(90)->format('Y-m-d'));
});

it('stamps now on an install with tickets but nothing recorded', function (): void {
    aTicket();

    runStampMigration();

    // This install starts recording at this release, so nothing before it can
    // be trusted -- which is exactly what the report needs to be told.
    expect(stampedValue())->toStartWith(now()->format('Y-m-d'));
});

it('leaves an existing stamp alone', function (): void {
    aTicket();

    DB::table('operator_settings')->insert([
        'key' => STAMP,
        'value' => '2020-01-01 00:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    runStampMigration();

    // Re-running must not move the boundary forward and silently make
    // previously measurable history unmeasurable.
    expect(stampedValue())->toBe('2020-01-01 00:00:00')
        ->and(DB::table('operator_settings')->where('key', STAMP)->count())->toBe(1);
});
