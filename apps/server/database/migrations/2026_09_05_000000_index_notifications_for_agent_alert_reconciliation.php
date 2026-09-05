<?php

use App\Support\AgentAlertPublicationSweep;
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
 * alert. The shared sweep establishes publication metadata for existing rows;
 * Forge repeats it after activation because the previous release can still
 * create or refresh notifications while this migration runs.
 */
return new class extends Migration
{
    // The sweep locks and releases one row at a time while the old release is
    // still serving. Do not let PostgreSQL hold those locks for the full table.
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            // The default keeps inserts from the previous zero-downtime
            // release visible until the post-activation sweep fingerprints
            // them. Existing in-place refreshes are detected by fingerprint.
            $table->timestamp('agent_alerted_at', precision: 6)->useCurrent();
            $table->uuid('agent_alert_version')->nullable();
            $table->uuid('agent_alert_broadcast_claim_version')->nullable();
            $table->string('agent_alert_fingerprint', 64)->nullable();
        });

        AgentAlertPublicationSweep::run();

        if (DB::connection()->getDriverName() === 'pgsql') {
            // Migrations run before zero-downtime activation while the old
            // release is still writing notifications. An ordinary PostgreSQL
            // index build blocks those writes for its entire scan.
            DB::statement(<<<'SQL'
                CREATE INDEX CONCURRENTLY notifications_recipient_alerted_at_id_index
                ON notifications (notifiable_type, notifiable_id, agent_alerted_at, id)
                SQL);
        } else {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->index(
                    ['notifiable_type', 'notifiable_id', 'agent_alerted_at', 'id'],
                    'notifications_recipient_alerted_at_id_index',
                );
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS notifications_recipient_alerted_at_id_index');
        } else {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->dropIndex('notifications_recipient_alerted_at_id_index');
            });
        }

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn([
                'agent_alerted_at',
                'agent_alert_version',
                'agent_alert_broadcast_claim_version',
                'agent_alert_fingerprint',
            ]);
        });
    }
};
