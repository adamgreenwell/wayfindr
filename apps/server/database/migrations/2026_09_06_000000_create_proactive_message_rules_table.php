<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proactive_message_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('name', 80);
            $table->string('message', 500);
            $table->string('url_contains', 255)->nullable();
            $table->string('referrer_contains', 255)->nullable();
            $table->unsignedSmallInteger('delay_seconds')->default(30);
            $table->unsignedSmallInteger('minimum_visit_count')->default(1);
            $table->boolean('requires_available_agent')->default(true);
            $table->unsignedInteger('frequency_cap_minutes')->default(10080);
            $table->unsignedInteger('dismissal_snooze_minutes')->default(43200);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();

            $table->unique(['site_id', 'name']);
            $table->index(['site_id', 'is_enabled', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proactive_message_rules');
    }
};
