<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support recipient-scoped alert snapshots and cursor reconciliation.
 *
 * The original morph index finds every notification belonging to an agent, but
 * cannot narrow or order that lifetime history by update time. The alert stream
 * reads a bounded time window and breaks timestamp ties by UUID, so the index
 * follows that complete access path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->index(
                ['notifiable_type', 'notifiable_id', 'updated_at', 'id'],
                'notifications_recipient_updated_at_id_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex('notifications_recipient_updated_at_id_index');
        });
    }
};
