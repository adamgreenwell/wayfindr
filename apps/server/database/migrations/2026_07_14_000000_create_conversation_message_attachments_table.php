<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_message_id')->constrained()->cascadeOnDelete();

            // Denormalized owning account/site. Every access re-check scopes the
            // attachment lookup by these columns as well as by the conversation,
            // so a leaked or guessed id still fails an account/site mismatch.
            // This is one leg of the ADR 0007 defense-in-depth boundary.
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // Storage location. `storage_disk` lets a row remember its home so a
            // future local->remote migration never breaks an existing reference;
            // `storage_key` is opaque and non-guessable — never a filename, never
            // a web-served path.
            $table->string('storage_disk', 64)->default('attachments');
            $table->string('storage_key', 512);

            // `mime_type` is the SERVER-detected type; `original_filename` is a
            // sanitized display hint only and is never trusted for safety.
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum', 64)->nullable();

            // Lifecycle. `status` gates downloadability (only `ready` is served);
            // `scan_*` are reserved for the pluggable scanner adapter (ADR 0007)
            // and stay unused until that surface lands.
            $table->string('status')->default('pending');
            $table->string('scan_status')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();

            // One row per stored object; the account/site index backs the scoped
            // access lookups; the status index backs the future orphan sweep.
            $table->unique(['storage_disk', 'storage_key']);
            $table->index(['account_id', 'site_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_message_attachments');
    }
};
