<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give browser alerts their own durable publication cursor and version.
 *
 * Notification updated_at also advances for read-state and email bookkeeping,
 * neither of which is a new browser alert. These dedicated fields advance only
 * when AgentAlertBroadcaster publishes a newly stored or meaningfully refreshed
 * alert. Existing rows are backfilled so a just-deployed dashboard can remember
 * recent durable alerts at its initial overlap boundary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->timestamp('agent_alerted_at', precision: 6)->nullable();
            $table->uuid('agent_alert_version')->nullable();
        });

        DB::table('notifications')->update([
            'agent_alerted_at' => DB::raw('created_at'),
            'agent_alert_version' => DB::raw('id'),
        ]);

        Schema::table('notifications', function (Blueprint $table): void {
            $table->index(
                ['notifiable_type', 'notifiable_id', 'agent_alerted_at', 'id'],
                'notifications_recipient_alerted_at_id_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex('notifications_recipient_alerted_at_id_index');
            $table->dropColumn(['agent_alerted_at', 'agent_alert_version']);
        });
    }
};
