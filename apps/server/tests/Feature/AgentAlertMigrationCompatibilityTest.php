<?php

use App\Models\Account;
use App\Models\User;
use App\Notifications\ConversationNeedsReply;
use App\Support\AgentAlertPublicationFingerprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

function agentAlertPublicationMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_05_000000_index_notifications_for_agent_alert_reconciliation.php');

    return $migration;
}

test('the nontransactional alert migration is safe to retry after its phases committed', function (): void {
    $migration = agentAlertPublicationMigration();

    $migration->up();

    expect(Schema::hasColumns('notifications', [
        'agent_alerted_at',
        'agent_alert_version',
        'agent_alert_broadcast_claim_version',
        'agent_alert_fingerprint',
    ]))->toBeTrue()
        ->and(collect(Schema::getIndexes('notifications'))->pluck('name'))
        ->toContain('notifications_recipient_alerted_at_id_index');
});

test('SQLite upgrades a populated notifications table and preserves old-writer insert visibility', function (): void {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('This regression is specific to SQLite ALTER TABLE default restrictions.');
    }

    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $migration = agentAlertPublicationMigration();
    $migration->down();
    $existingId = (string) Str::uuid();
    $existingData = ['kind' => 'conversation_needs_reply', 'message_count' => 1];

    DB::table('notifications')->insert([
        'id' => $existingId,
        'type' => ConversationNeedsReply::class,
        'notifiable_type' => $agent->getMorphClass(),
        'notifiable_id' => $agent->id,
        'data' => json_encode($existingData),
        'read_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    $existing = DatabaseNotification::query()->findOrFail($existingId);

    expect($existing->getAttribute('agent_alerted_at'))->not->toBeNull()
        ->and($existing->getAttribute('agent_alert_version'))->toBe($existingId)
        ->and($existing->getAttribute('agent_alert_fingerprint'))->toBe(
            AgentAlertPublicationFingerprint::for($existingData),
        );

    $oldWriterId = (string) Str::uuid();

    // The previous release still omits every new column. SQLite's trigger gives
    // that insert the same immediate reconciliation visibility as the dynamic
    // default used by PostgreSQL and MySQL.
    DB::table('notifications')->insert([
        'id' => $oldWriterId,
        'type' => ConversationNeedsReply::class,
        'notifiable_type' => $agent->getMorphClass(),
        'notifiable_id' => $agent->id,
        'data' => json_encode($existingData),
        'read_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('notifications')->where('id', $oldWriterId)->value('agent_alerted_at'))
        ->not->toBeNull();
});
