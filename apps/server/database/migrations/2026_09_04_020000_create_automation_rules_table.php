<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('event');
            $table->json('conditions');
            $table->json('actions');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();

            $table->unique(['account_id', 'name']);
            $table->index(['account_id', 'is_enabled', 'event', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rules');
    }
};
