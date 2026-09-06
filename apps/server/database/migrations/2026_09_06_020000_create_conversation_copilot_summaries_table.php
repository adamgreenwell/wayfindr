<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_copilot_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('generation')->unique();
            $table->string('status', 32);
            $table->text('summary')->nullable();
            $table->foreignId('source_last_message_id')->nullable()->constrained('conversation_messages')->nullOnDelete();
            $table->unsignedInteger('source_message_count')->default(0);
            $table->string('provider', 64)->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_copilot_summaries');
    }
};
