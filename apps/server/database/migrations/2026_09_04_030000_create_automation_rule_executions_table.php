<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rule_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('subject');
            $table->string('rule_name');
            $table->string('event');
            $table->string('status');
            $table->json('conditions');
            $table->json('actions');
            $table->json('action_results');
            $table->json('metadata');
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->index(['account_id', 'status', 'started_at']);
            $table->index(['automation_rule_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rule_executions');
    }
};
