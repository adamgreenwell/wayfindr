<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['conversation_copilot_summaries', 'conversation_copilot_reply_drafts',
        'conversation_copilot_knowledge_suggestions', 'conversation_copilot_ticket_suggestions'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('preparation_fingerprint', 64)->nullable();
                $table->json('preparation_source_snapshot')->nullable();
                $table->json('previous_output')->nullable();
                $table->timestamp('retry_not_before')->nullable();
            });
        }
        // Content-free receipts remember terminal keys even after the bounded
        // output slot moves on. Returning A -> B -> A is never a free retry.
        Schema::create('ai_assist_preparation_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 64);
            $table->string('fingerprint', 64);
            $table->uuid('generation');
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24);
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('retry_not_before')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'conversation_id', 'purpose', 'fingerprint'], 'ai_preparation_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_assist_preparation_receipts');
        foreach (self::TABLES as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn([
                'preparation_fingerprint', 'preparation_source_snapshot', 'previous_output', 'retry_not_before',
            ]));
        }
    }
};
