<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a site prove that an external id is the host's claim and not a caller's.
 *
 * `visitors.external_id` arrives from the host page through a public endpoint
 * and has never been checked -- the widget README says so in as many words.
 * The product nonetheless treats it as identity: `VisitorLabel` ranks it ahead
 * of `anonymous_id`, so wherever a visitor has no name or email the external id
 * IS the name an agent reads. An agent looking at `customer-4821` has no way to
 * tell a claim from a fact.
 *
 * This is the same shape every comparable product ships (Intercom calls it
 * Identity Verification): the host HMACs the identifier with a secret that
 * never reaches the browser, and the server recomputes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            // Encrypted rather than hashed, because verification has to
            // RECOMPUTE the HMAC -- the server needs the secret back, not a
            // one-way digest of it. Same choice, for the same reason, as the
            // outbound webhook signing secret.
            $table->text('identity_secret')->nullable();

            // Enough to match a row to the value in a host's deployment config
            // without being enough to sign with, mirroring `api_tokens`.
            $table->string('identity_secret_last_four', 4)->nullable();

            // 'off' or 'required'. A STRING rather than a boolean because the
            // interesting third state is already visible from here: a
            // "report-only" mode that verifies and records without refusing
            // would let a host confirm their integration before enforcing it,
            // and a boolean column would have to be migrated to add it.
            //
            // Defaulting to 'off' for existing rows is not a preference, it is
            // forced: turning verification on for a site whose host has not
            // deployed the hashing code yet would stop identifying every one of
            // their customers, silently, on upgrade.
            $table->string('identity_verification', 16)->default('off');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn([
                'identity_secret',
                'identity_secret_last_four',
                'identity_verification',
            ]);
        });
    }
};
