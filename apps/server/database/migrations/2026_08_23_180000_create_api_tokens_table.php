<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Programmatic access to an account, per ADR 0018.
 *
 * The most sensitive credential the product issues: every other access path has
 * a human at one end, and this one has nobody and no session to expire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            // Who issued it, kept for the audit trail. Null when that agent's
            // account row is gone -- the token outliving its issuer is exactly
            // the situation `last_used_at` exists to make visible.
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            // The SHA-256 of the token, never the token. Shown once at creation
            // and unrecoverable after: an operator who loses it issues another
            // one, which is the correct outcome rather than an inconvenience.
            $table->string('token_hash', 64)->unique();
            // Enough to identify a token in a list without being enough to use
            // it, so an operator can match a row to the credential in their
            // deployment config.
            $table->string('last_four', 4);
            $table->json('abilities');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'revoked_at']);
        });

        // A token may see fewer sites than its account has, mirroring
        // `site_user`. An integration that watches one site should not be a
        // credential for all of them.
        Schema::create('api_token_site', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('api_token_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            $table->unique(['api_token_id', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_token_site');
        Schema::dropIfExists('api_tokens');
    }
};
