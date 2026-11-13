<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_bootstrap_requests', function (Blueprint $table): void {
            // Every supported headless bootstrap competes for the same row.
            $table->unsignedTinyInteger('id')->primary();
            $table->uuid('request_id')->unique();
            $table->char('request_hash', 64);
            $table->string('status', 16);
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->char('claim_file_sha256', 64)->nullable();
            $table->json('receipt')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_bootstrap_requests');
    }
};
