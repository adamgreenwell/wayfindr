<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-set instance configuration that overrides env for the
 * operationally-configurable areas (mail, storage, scanning, backups) so an
 * operator can configure how Wayfindr runs from the browser instead of editing
 * `.env` and restarting (ADR 0011). One row per setting key; secret values are
 * stored encrypted at rest by the OperatorSettings service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_settings');
    }
};
