<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'conversation_copilot_summaries', 'conversation_copilot_reply_drafts',
        'conversation_copilot_knowledge_suggestions', 'conversation_copilot_ticket_suggestions',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('policy_revision', 64)->nullable();
                $table->string('source_context_fingerprint', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['policy_revision', 'source_context_fingerprint']));
        }
    }
};
