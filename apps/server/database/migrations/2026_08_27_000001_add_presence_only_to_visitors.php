<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether this row exists only because somebody loaded a page.
 *
 * Retention needs POSITIVE evidence, not an inference. The first version of the
 * pruner deleted visitors with no conversation and no ticket, which reads as
 * "never made contact" and is not: `BootstrapController` creates a row the
 * moment somebody OPENS the widget, and ADR 0016 classifies that as contact.
 *
 * So the inference would have deleted every legacy visitor who opened the chat
 * and never sent anything, on every install, including ones that never enabled
 * presence at all -- irreversibly, on the first scheduled run after upgrading.
 *
 * Defaulting to false is what makes that impossible: every row that already
 * exists is not presence-only, whatever else is true of it, and only the
 * presence endpoint may ever set it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table): void {
            $table->boolean('presence_only')->default(false)->after('current_visit_started_at');
            // The pruner filters on it alongside last_seen_at.
            $table->index(['presence_only', 'last_seen_at'], 'visitors_presence_only_last_seen_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table): void {
            $table->dropIndex('visitors_presence_only_last_seen_at_index');
            $table->dropColumn('presence_only');
        });
    }
};
