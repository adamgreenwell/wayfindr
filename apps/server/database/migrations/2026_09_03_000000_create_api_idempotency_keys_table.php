<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The short-lived receipt behind idempotent public API writes (#784).
 *
 * The caller's key is hashed rather than retained. It is not a bearer secret,
 * but it is still caller-authored operational data and the hash is everything
 * Wayfindr needs to recognise a retry. The response body is not copied here
 * either: storing a second transcript or ticket payload would create a second
 * retention surface for the most sensitive data in the product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('api_token_id')->constrained()->cascadeOnDelete();
            $table->string('key_hash', 64);
            $table->string('request_hash', 64);
            $table->string('resource_type', 32);
            $table->unsignedBigInteger('resource_id');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['api_token_id', 'key_hash']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};
