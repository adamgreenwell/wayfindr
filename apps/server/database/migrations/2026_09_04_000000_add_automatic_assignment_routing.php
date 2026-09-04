<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('routing_status', 16)->default('away');
            $table->timestamp('routing_status_changed_at')->nullable();
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->index(['assigned_agent_id', 'status'], 'conversations_assigned_agent_status_index');
        });

        Schema::create('site_routing_states', function (Blueprint $table): void {
            $table->foreignId('site_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('last_conversation_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('last_ticket_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_routing_states');

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conversations_assigned_agent_status_index');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['routing_status', 'routing_status_changed_at']);
        });
    }
};
