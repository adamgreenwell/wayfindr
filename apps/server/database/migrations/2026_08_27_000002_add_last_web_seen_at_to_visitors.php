<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separate "seen on the website" from "seen at all".
 *
 * `last_seen_at` answers the second question and always has: the visitor
 * directory shows it, and an inbound email stamps it, because somebody who
 * emails support has certainly been seen. That is the right meaning for that
 * column and nothing here changes it.
 *
 * The live board asks the first question, and keying it off `last_seen_at`
 * fabricates answers. `InboundMailRouter` writes that column for a sender whose
 * null `anonymous_id` proves they never loaded the widget, which would start a
 * website visit for an email reply -- putting somebody on the board as present,
 * with a time-on-site counting up, while they are in their mail client. An
 * agent acting on that would try to watch a browser that is not there.
 *
 * So the website fact gets its own column, and `current_visit_started_at` is
 * maintained from THIS one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table): void {
            $table->timestamp('last_web_seen_at')->nullable()->after('last_seen_at');
        });

        // Backfilled only where there is evidence of a browser. An
        // `anonymous_id` is that evidence -- it is the widget's own session
        // identifier and the mail router deliberately never invents one.
        //
        // Imprecise in one direction, knowingly: a visitor who used the widget
        // months ago and emailed yesterday gets yesterday's timestamp, because
        // the conflation this migration ends means the old column cannot say
        // which channel wrote it. That resolves itself on their next page load,
        // and reading one stale timestamp as a website sighting is bounded by
        // the same fifteen-minute cutoff everything else uses.
        Schema::getConnection()
            ->table('visitors')
            ->whereNotNull('anonymous_id')
            ->update(['last_web_seen_at' => Schema::getConnection()->raw('last_seen_at')]);
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table): void {
            $table->dropColumn('last_web_seen_at');
        });
    }
};
