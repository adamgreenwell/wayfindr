<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stamp the instant TICKET lifecycle recording became trustworthy.
 *
 * The ticket report shipped claiming it needed no such boundary, on the
 * reasoning that ticket events predate every install that has tickets. That is
 * true of installs created after ticket auditing existed and false of every
 * install upgraded from before it: a ticket closed and reopened while nothing
 * was writing `ticket.reopened`, then closed again inside a reporting window,
 * is measured from its original creation and inflates the median with work that
 * was already finished.
 *
 * Same shape as the conversation stamp, and the same reasoning applies:
 *
 * - on an install already recording, the earliest event, which is the most that
 *   can honestly be claimed;
 * - otherwise now, which is when this release starts recording.
 *
 * **Nothing is written on an install with no tickets**, for the two reasons the
 * conversation migration records: such an install has no history predating the
 * boundary, and writing a row unconditionally leaves a fresh database non-empty
 * — which `wayfindr:restore` reads as populated and refuses to overwrite
 * without --force, breaking restore-into-a-fresh-install.
 */
return new class extends Migration
{
    public const KEY = 'reporting.ticket_lifecycle_recording_began_at';

    public function up(): void
    {
        if (DB::table('operator_settings')->where('key', self::KEY)->exists()) {
            return;
        }

        if (! DB::table('tickets')->exists()) {
            return;
        }

        $earliest = DB::table('audit_events')
            ->whereIn('action', ['ticket.closed', 'ticket.reopened'])
            ->min('occurred_at');

        DB::table('operator_settings')->insert([
            'key' => self::KEY,
            'value' => $earliest ?? now()->toDateTimeString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('operator_settings')->where('key', self::KEY)->delete();
    }
};
