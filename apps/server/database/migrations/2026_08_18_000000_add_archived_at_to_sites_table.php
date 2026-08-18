<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the archived state a site needs before it can be retired.
 *
 * Every relation beneath a site cascades on delete - visitors, conversations,
 * tickets, cobrowse sessions and the site's own audit events - so a hard delete
 * destroys the entire support history for that site. Archiving is the operation
 * an operator usually wants instead: the widget stops serving, the site leaves
 * the working lists, and nothing is lost. Purging remains available as a
 * separate, deliberate action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('settings');

            // Site lists filter on "not archived" within an account on every
            // dashboard request, which is the hot path for this column.
            $table->index(['account_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropIndex(['account_id', 'archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};
