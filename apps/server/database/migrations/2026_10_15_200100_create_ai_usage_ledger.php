<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_scopes', function (Blueprint $table): void {
            $table->string('scope_key', 80)->primary();
            // Content-free accounting survives account deletion until settled.
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('concurrency_in_use')->default(0);
            $table->timestamps();
        });
        Schema::create('ai_usage_periods', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_key', 80);
            $table->date('period_day');
            $table->unsignedBigInteger('held_requests')->default(0);
            $table->unsignedBigInteger('held_tokens')->default(0);
            $table->unsignedBigInteger('charged_requests')->default(0);
            $table->unsignedBigInteger('charged_tokens')->default(0);
            $table->timestamps();
            $table->unique(['scope_key', 'period_day']);
        });
        Schema::create('ai_usage_attempts', function (Blueprint $table): void {
            // Sequence orders replacements even when timestamps are identical.
            $table->id();
            $table->uuid('attempt_id')->unique();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('requester_id')->nullable();
            $table->string('source', 20);
            $table->string('connection_revision', 64);
            $table->string('purpose', 64);
            $table->string('work_fingerprint', 64);
            $table->unsignedBigInteger('input_token_bound');
            $table->unsignedBigInteger('max_output_tokens');
            $table->unsignedBigInteger('reserved_tokens');
            $table->unsignedBigInteger('debited_tokens')->default(0);
            $table->string('state', 20);
            $table->date('reserved_day');
            $table->date('dispatch_day')->nullable();
            $table->timestamp('expires_at', 6);
            $table->uuid('claim_nonce')->nullable();
            $table->timestamp('claim_expires_at', 6)->nullable();
            $table->uuid('dispatch_nonce')->nullable();
            $table->timestamp('dispatched_at', 6)->nullable();
            $table->timestamp('dispatch_expires_at', 6)->nullable();
            $table->boolean('concurrency_held')->default(false);
            $table->unsignedBigInteger('reported_input_tokens')->nullable();
            $table->unsignedBigInteger('reported_output_tokens')->nullable();
            $table->unsignedInteger('retry_cooldown_seconds')->default(60);
            $table->string('reason', 64)->nullable();
            $table->timestamp('retry_not_before', 6)->nullable();
            $table->timestamp('terminal_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['account_id', 'purpose', 'work_fingerprint', 'id'], 'ai_usage_work_lookup');
            $table->index(['state', 'expires_at']);
            $table->index(['state', 'claim_expires_at']);
            $table->index(['concurrency_held', 'dispatch_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_attempts');
        Schema::dropIfExists('ai_usage_periods');
        Schema::dropIfExists('ai_usage_scopes');
    }
};
