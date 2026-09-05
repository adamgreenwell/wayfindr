<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_alert_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->uuid('alert_version');
            $table->string('state_key', 64)->default('event');
            $table->string('channel');
            // Digest and unattended collectors already treat this as an
            // opaque ownership token; production uses UUIDs, while keeping
            // the ledger compatible with every valid existing token shape.
            $table->string('claim_token', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['notification_id', 'alert_version', 'state_key'],
                'agent_alert_delivery_state_unique',
            );
        });

        Schema::table('sla_alert_deliveries', function (Blueprint $table): void {
            $table->timestamp('deduplicated_at')->nullable()->after('accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('sla_alert_deliveries', function (Blueprint $table): void {
            $table->dropColumn('deduplicated_at');
        });

        Schema::dropIfExists('agent_alert_deliveries');
    }
};
