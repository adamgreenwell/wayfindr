<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('name_key', 80);
            $table->json('permissions');
            $table->timestamps();

            $table->unique(['account_id', 'name_key']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('custom_role_id')
                ->nullable()
                ->after('account_role')
                ->constrained('custom_roles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('custom_role_id');
        });

        Schema::dropIfExists('custom_roles');
    }
};
