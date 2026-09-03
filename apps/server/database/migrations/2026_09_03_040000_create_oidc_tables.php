<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account-owned OpenID Connect federation (ADR 0022).
 *
 * Provider credentials are encrypted by the model. An identity binds the
 * provider's stable subject to one already-existing account user; it carries
 * no access token, refresh token, ID token, claims, or role material.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oidc_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->uuid('configuration_version');
            $table->string('name');
            $table->text('issuer_url');
            $table->string('client_id');
            $table->text('client_secret');
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('oidc_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('oidc_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('subject');
            $table->timestamp('last_signed_in_at')->nullable();
            $table->timestamps();

            $table->unique(['oidc_connection_id', 'subject'], 'oidc_connection_subject_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oidc_identities');
        Schema::dropIfExists('oidc_connections');
    }
};
