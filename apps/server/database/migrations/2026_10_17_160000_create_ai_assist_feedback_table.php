<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_assist_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 64);
            $table->uuid('generation');
            $table->string('source_revision', 64);
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('usefulness', 20)->nullable();
            $table->string('correction', 20)->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
            $table->unique(['conversation_id', 'purpose', 'generation', 'user_id'], 'ai_assist_feedback_identity');
            $table->index(['account_id', 'site_id', 'created_at'], 'ai_assist_feedback_report');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_assist_feedback');
    }
};
