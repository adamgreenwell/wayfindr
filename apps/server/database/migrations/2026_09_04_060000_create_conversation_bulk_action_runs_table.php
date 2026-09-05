<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_bulk_action_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->json('value')->nullable();
            $table->unsignedInteger('item_count');
            $table->unsignedInteger('changed_count');
            $table->json('changes');
            $table->json('return_query')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->foreignId('undone_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('undo_result')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_bulk_action_runs');
    }
};
