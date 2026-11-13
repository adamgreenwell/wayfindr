<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_ai_usage_limits', function (Blueprint $table): void {
            $table->foreignId('account_id')->primary()->constrained()->cascadeOnDelete();
            // Missing/null inherits; zero is an explicit prohibition.
            $table->unsignedBigInteger('daily_requests')->nullable();
            $table->unsignedBigInteger('daily_tokens')->nullable();
            $table->unsignedInteger('concurrency')->nullable();
            $table->unsignedInteger('max_output_tokens')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_ai_usage_limits');
    }
};
