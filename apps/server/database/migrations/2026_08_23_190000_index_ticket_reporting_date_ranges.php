<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ticket half of `2026_08_22_100000_index_support_records_for_reporting`.
 *
 * That migration indexed the conversation queries because those were the only
 * reporting queries that existed. Its own note explains why the existing
 * indexes do not serve them: every composite on `conversations` and `tickets`
 * is `(scope, status)`, which is right for the queue and useless for
 * "everything on these sites over the last quarter".
 *
 * Tickets are reported on now, and `TicketReport::volume()` scans exactly that
 * shape on every load of the page -- whichever tab is selected, since the
 * controller builds both halves before the view chooses.
 *
 * Invisible in a test suite and on a new install: correctness is identical
 * either way, and the symptom is a fixed 7/30/90-day view getting slower every
 * quarter. Nobody attributes that to an index, because the query never changed
 * -- only the amount of history behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->index(['site_id', 'created_at'], 'tickets_site_id_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex('tickets_site_id_created_at_index');
        });
    }
};
