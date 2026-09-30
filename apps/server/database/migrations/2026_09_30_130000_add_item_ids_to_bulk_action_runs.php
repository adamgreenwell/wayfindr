<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record every item a bulk run selected, not only the ones it changed.
 *
 * A run keeps the queue search that found its items, which can be a person's
 * name or email. Erasing that person clears the search from every run that
 * selected them (ADR 0026 §4), but `changes` names only the items a run
 * changed: one already holding the requested value is skipped and left out.
 * Null on runs made before this column, which record only what they changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['conversation_bulk_action_runs', 'ticket_bulk_action_runs'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->json('item_ids')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['conversation_bulk_action_runs', 'ticket_bulk_action_runs'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn('item_ids');
            });
        }
    }
};
