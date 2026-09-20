<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Record which visitor session opened each conversation.
 *
 * A visitor's `anonymous_id` is shown to agents in the dashboard, and until now
 * it was also enough to reach that visitor's conversations: a request was
 * authorised by matching the conversation's `visitor_id`, and a token naming that
 * visitor could be obtained from bootstrap with the id alone. A value the product
 * displays cannot also be the thing that authorises access to it.
 *
 * Binding a conversation to the SESSION that opened it fixes that without
 * touching bootstrap, which matters: on a presence-enabled site the visitor row
 * already exists before bootstrap runs, so any gate there would refuse the
 * ordinary funnel.
 *
 * A real column rather than a `metadata` key. JSON-path SQL on a TEXT column
 * behaves differently between sqlite and Postgres here, which is a documented
 * landmine in this codebase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->string('owner_session_id', 64)->nullable()->after('visitor_id');
        });

        // Every conversation that predates the control gets a sentinel rather
        // than NULL, so the three states each mean exactly one thing: the
        // sentinel is "older than this control", a digest is "this session", and
        // NULL is "written by a path that had no session", which is a bug.
        //
        // A self-terminating loop rather than chunkById: that needs a stable
        // order and behaves differently on sqlite and Postgres. This is
        // idempotent and safe to re-run.
        do {
            $updated = DB::table('conversations')
                ->whereNull('owner_session_id')
                ->limit(1000)
                ->update(['owner_session_id' => '~legacy']);
        } while ($updated > 0);
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn('owner_session_id');
        });
    }
};
