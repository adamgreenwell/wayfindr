<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_draft_refinements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('generation')->unique();
            $table->uuid('draft_revision');
            $table->string('connection_revision', 64);
            $table->string('policy_revision', 64);
            $table->string('work_fingerprint', 64);
            $table->string('status', 24);
            $table->json('operation');
            $table->longText('input')->nullable();
            $table->longText('scope')->nullable();
            $table->longText('preview')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['conversation_id', 'requested_by_id'], 'draft_refinement_owner');
        });
        // A cancelled/deleted private request must not hide an already-started call.
        Schema::create('conversation_draft_refinement_sends', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->uuid('generation')->unique();
            $table->timestamp('started_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_draft_refinement_sends');
        Schema::dropIfExists('conversation_draft_refinements');
    }
};
