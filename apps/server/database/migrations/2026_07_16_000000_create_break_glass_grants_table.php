<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('break_glass_grants', function (Blueprint $table): void {
            $table->id();

            // Every grant targets exactly ONE account; conversation/site scopes
            // carry their reference alongside so coverage checks re-derive the
            // account per resource (ADR 0008, the ADR 0007 defense-in-depth
            // posture). Grant rows are the ACCOUNTABILITY RECORD: deleting the
            // scoped conversation/site (or the requesting user) nulls the
            // reference but retains the grant, per the ADR — only deleting the
            // whole account removes its grants.
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type'); // conversation | site | account
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');

            // requested -> active (approved / self-approved) | denied;
            // active -> closed | expired.
            $table->string('status')->default('requested');
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('self_approved')->default(false);

            // Requested TTL is recorded up front; expires_at is stamped at
            // approval time (approved_at + ttl). Never extended — a longer
            // investigation means a fresh grant (ADR 0008).
            $table->unsignedInteger('requested_minutes');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('break_glass_grants');
    }
};
