<?php

use App\Support\Conversations\LegacyOwnerSessionSweep;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        // than NULL, so each stored value means exactly one thing: the sentinel
        // is "older than this control", `~none` is "a path that legitimately has
        // no widget session", a digest is "this session", and NULL is "a path
        // that should have recorded one and did not", which is a bug.
        //
        // NOT the last word. On the supported zero-downtime path this runs while
        // the PREVIOUS release is still serving widget traffic, and that release
        // does not know the column exists -- so a conversation opened after this
        // sweep's batch was passed keeps a null. The deploy scripts run
        // `wayfindr:claim-legacy-conversation-sessions` after activation, and the
        // scheduler runs it daily for the install shapes that never run them.
        //
        // Shared with that command rather than written twice.
        LegacyOwnerSessionSweep::run();
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn('owner_session_id');
        });
    }
};
