<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stamp the instant conversation lifecycle recording became trustworthy.
 *
 * Reporting needs to know when the absence of a reopen event started meaning
 * "it was not reopened" rather than "nobody was writing it down". Without that
 * instant, a conversation older than the log looks like one long unbroken
 * stretch of work, and its resolution time is measured from a creation date
 * that may sit behind several closes nobody recorded -- silently inflating the
 * median and p90 with work that was already finished.
 *
 * It cannot be derived. The obvious proxy -- the earliest lifecycle event on
 * record -- is circular: that event belongs to a conversation created before
 * it, so the first close on any install could never be measured, and the
 * boundary would drift every time older history was purged.
 *
 * So it is written once, here:
 *
 * - on an install already recording, the earliest event, which is the most that
 *   can honestly be claimed;
 * - otherwise now, which is when this release starts recording.
 *
 * Not an operator setting despite living in that table. There is nothing to
 * configure -- it is a fact about this install, stored in the only key/value
 * table there is, under a key the OperatorSettings registry does not manage.
 *
 * **Nothing is written on a fresh install**, and that is load-bearing twice
 * over. An install with no conversations when recording began has no history
 * that predates it, so every close it will ever see is measurable and the
 * absence of a stamp says exactly that. And writing a row unconditionally would
 * leave a brand-new install non-empty, which `wayfindr:restore` reads as
 * populated -- it refuses to overwrite any non-empty database without --force,
 * deliberately counting every table and not just the content ones. Stamping
 * here would have quietly broken restore-into-a-fresh-install, which is the
 * disaster-recovery path.
 */
return new class extends Migration
{
    public const KEY = 'reporting.lifecycle_recording_began_at';

    public function up(): void
    {
        if (DB::table('operator_settings')->where('key', self::KEY)->exists()) {
            return;
        }

        // No conversations means nothing can predate recording.
        if (! DB::table('conversations')->exists()) {
            return;
        }

        $earliest = DB::table('audit_events')
            ->whereIn('action', ['conversation.closed', 'conversation.reopened'])
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
