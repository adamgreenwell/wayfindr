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
    private const INDEX_NAME = 'notifications_recipient_alerted_at_id_index';

    private const SQLITE_TRIGGER_NAME = 'notifications_agent_alerted_at_default';

    // The sweep locks and releases one row at a time while the old release is
    // still serving. Do not let PostgreSQL hold those locks for the full table.
    public $withinTransaction = false;

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        $this->ensurePublicationColumns($driver);

        if ($driver === 'sqlite') {
            $this->ensureSqliteInsertDefault();
        }

        AgentAlertPublicationSweep::run();
        $this->ensureReconciliationIndex($driver);
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS '.self::SQLITE_TRIGGER_NAME);
        }

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX_NAME);
        } elseif ($this->reconciliationIndexExists()) {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX_NAME);
            });
        }

        $columns = array_values(array_filter([
            'agent_alerted_at',
            'agent_alert_version',
            'agent_alert_broadcast_claim_version',
            'agent_alert_broadcast_pending_version',
            'agent_alert_fingerprint',
        ], fn (string $column): bool => Schema::hasColumn('notifications', $column)));

        if ($columns !== []) {
            Schema::table('notifications', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    private function ensurePublicationColumns(string $driver): void
    {
        $columns = [
            'agent_alerted_at',
            'agent_alert_version',
            'agent_alert_broadcast_claim_version',
            'agent_alert_broadcast_pending_version',
            'agent_alert_fingerprint',
        ];

        foreach ($columns as $column) {
            if (Schema::hasColumn('notifications', $column)) {
                continue;
            }

            Schema::table('notifications', function (Blueprint $table) use ($column, $driver): void {
                match ($column) {
                    // SQLite refuses ALTER TABLE ADD COLUMN with a dynamic
                    // CURRENT_TIMESTAMP default on a populated table. Its
                    // trigger below supplies the same old-writer behavior.
                    'agent_alerted_at' => $driver === 'sqlite'
                        ? $table->timestamp($column, precision: 6)->nullable()
                        : $table->timestamp($column, precision: 6)->useCurrent(),
                    'agent_alert_version',
                    'agent_alert_broadcast_claim_version',
                    'agent_alert_broadcast_pending_version' => $table->uuid($column)->nullable(),
                    'agent_alert_fingerprint' => $table->string($column, 64)->nullable(),
                };
            });
        }
    }

    private function ensureSqliteInsertDefault(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS '.self::SQLITE_TRIGGER_NAME);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER notifications_agent_alerted_at_default
            AFTER INSERT ON notifications
            FOR EACH ROW
            WHEN NEW.agent_alerted_at IS NULL
            BEGIN
                UPDATE notifications
                SET agent_alerted_at = STRFTIME('%Y-%m-%d %H:%M:%f', 'NOW')
                WHERE id = NEW.id;
            END
            SQL);
    }

    private function ensureReconciliationIndex(string $driver): void
    {
        if ($driver === 'pgsql') {
            $index = DB::selectOne(
                'SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)',
                [self::INDEX_NAME],
            );

            if ($index !== null && $this->databaseBoolean($index->indisvalid ?? false)) {
                return;
            }

            // A cancelled concurrent build leaves an invalid index behind. Drop
            // that partial object so rerunning the unrecorded migration heals
            // the deployment instead of failing on both columns and index name.
            if ($index !== null) {
                DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX_NAME);
            }

            // Migrations run before zero-downtime activation while the old
            // release is still writing notifications. An ordinary PostgreSQL
            // index build blocks those writes for its entire scan.
            DB::statement(<<<'SQL'
                CREATE INDEX CONCURRENTLY notifications_recipient_alerted_at_id_index
                ON notifications (notifiable_type, notifiable_id, agent_alerted_at, id)
                SQL);

            return;
        }

        if ($this->reconciliationIndexExists()) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table): void {
            $table->index(
                ['notifiable_type', 'notifiable_id', 'agent_alerted_at', 'id'],
                self::INDEX_NAME,
            );
        });
    }

    private function reconciliationIndexExists(): bool
    {
        return collect(Schema::getIndexes('notifications'))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === self::INDEX_NAME);
    }

    private function databaseBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
};
