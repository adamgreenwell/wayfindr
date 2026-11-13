<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ARTIFACTS = [
        'conversation_copilot_summaries',
        'conversation_copilot_reply_drafts',
        'conversation_copilot_ticket_suggestions',
        'conversation_copilot_knowledge_suggestions',
    ];

    public function up(): void
    {
        Schema::create('account_ai_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name', 100)->default('');
            // Preserve the existing manual installation path while drafting.
            // Automatic preparation is not enabled by this foundation.
            $table->boolean('enabled')->default(true);
            $table->boolean('uses_installation_default')->default(true);
            $table->uuid('proposed_revision')->nullable();
            $table->text('proposed_configuration')->nullable();
            $table->uuid('tested_revision')->nullable();
            $table->string('tested_fingerprint', 64)->nullable();
            $table->string('tested_policy_revision', 128)->nullable();
            $table->timestamp('tested_at')->nullable();
            $table->uuid('test_generation')->nullable();
            $table->string('test_status', 20)->default('untested');
            $table->timestamp('test_started_at')->nullable();
            $table->uuid('active_revision')->nullable();
            $table->text('active_configuration')->nullable();
            $table->timestamps();
        });

        foreach (self::ARTIFACTS as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('connection_revision', 64)->nullable());
        }
        // Existing unversioned output cannot prove its processor identity.
        // Readers withhold it; queued work is cancelled. A fresh manual request
        // remains available and receives an authoritative revision stamp.
    }

    public function down(): void
    {
        foreach (self::ARTIFACTS as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('connection_revision'));
        }

        Schema::dropIfExists('account_ai_connections');
    }
};
