<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The record that an erasure happened (ADR 0026 §8, §9). It holds no
        // name, email, browser ID or content: the internal ID of a row that
        // no longer exists, who erased it, and how much went. That is what a
        // restore needs to re-apply the erasure, and what an operator quotes
        // back to the person who asked, via the receipt reference.
        Schema::create('visitor_erasures', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            // Null after the site is purged: the ledger outlives the site so a
            // restore of an archive that still holds it can re-apply erasures.
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            // No foreign key: the row it names is gone by design.
            $table->unsignedBigInteger('erased_visitor_id');
            // Every visitor merged into that one. A restore from before a
            // merge brings the source row back under its own ID, so the
            // ledger has to name it too.
            $table->json('merged_visitor_ids');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('counts');
            // Binaries still to be removed from storage, as disk and key. Written
            // in the erasure's own transaction, so a crash after the commit
            // cannot lose them, and emptied as each is removed; the scheduled
            // wayfindr:finish-erasures retries whatever is left.
            $table->json('pending_files')->nullable();
            $table->timestamp('erased_at');
            $table->timestamps();

            $table->index(['account_id', 'erased_at']);
            $table->index('erased_visitor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_erasures');
    }
};
