<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oidc_connections', function (Blueprint $table): void {
            $table->string('role_claim')->nullable()->after('is_enabled');
            $table->boolean('jit_provisioning_enabled')->default(false)->after('role_claim');
        });

        Schema::table('oidc_identities', function (Blueprint $table): void {
            $table->timestamp('provisioned_at')->nullable()->after('last_signed_in_at');
        });

        Schema::table('users', function (Blueprint $table): void {
            // Persists provenance when an issuer/client change correctly
            // clears the provider-subject binding itself.
            $table->timestamp('oidc_provisioned_at')->nullable()->after('custom_role_id');
        });

        Schema::create('oidc_role_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('oidc_connection_id')->constrained()->cascadeOnDelete();
            $table->string('claim_value');
            $table->string('built_in_role', 20)->nullable();
            $table->foreignId('custom_role_id')
                ->nullable()
                ->constrained('custom_roles')
                ->restrictOnDelete();
            $table->timestamps();

            $table->unique(['oidc_connection_id', 'claim_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oidc_role_mappings');

        Schema::table('oidc_identities', function (Blueprint $table): void {
            $table->dropColumn('provisioned_at');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('oidc_provisioned_at');
        });

        Schema::table('oidc_connections', function (Blueprint $table): void {
            $table->dropColumn(['role_claim', 'jit_provisioning_enabled']);
        });
    }
};
