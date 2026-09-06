<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proactive_message_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proactive_message_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('visitor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('public_id')->unique();
            $table->uuid('rule_public_id');
            $table->string('claim_key', 128);
            $table->string('message', 500);
            $table->timestamp('claimed_at');
            $table->timestamp('expires_at');
            $table->timestamp('shown_at')->nullable();
            $table->timestamp('engaged_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'claim_key']);
            $table->index(['visitor_id', 'claimed_at']);
            $table->index(['visitor_id', 'shown_at']);
            $table->index(['visitor_id', 'dismissed_at']);
            $table->index(['proactive_message_rule_id', 'shown_at'], 'proactive_deliveries_rule_shown_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proactive_message_deliveries');
    }
};
