<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_external_comment_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_external_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_connection_id')
                ->constrained('external_issue_provider_connections')
                ->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('note_audit_event_id');
            $table->text('body');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('remote_comment_id')->nullable();
            $table->text('remote_url')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();

            $table->unique(
                ['note_audit_event_id', 'ticket_external_link_id'],
                'ticket_external_comment_note_link_unique',
            );
            $table->index(
                ['started_at', 'delivered_at', 'failed_at'],
                'ticket_external_comment_pending_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_external_comment_deliveries');
    }
};
