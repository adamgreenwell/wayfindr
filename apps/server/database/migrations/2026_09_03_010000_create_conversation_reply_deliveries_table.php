<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_reply_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_message_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('recipient');
            $table->string('message_id')->unique();
            $table->string('in_reply_to')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['delivered_at', 'failed_at'], 'conversation_reply_deliveries_pending_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_reply_deliveries');
    }
};
