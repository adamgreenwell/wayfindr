<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the visit a visitor is currently on began (ADR 0019 §3a).
 *
 * Neither existing column can answer it. `last_seen_at` is overwritten by every
 * heartbeat, so the moment a visit started is destroyed by the next report --
 * which is exactly the value "how long have they been here" needs.
 * `created_at` answers when we first ever saw them, which is the different
 * question #747 asks under `returning or new`.
 *
 * Nullable because every existing row predates presence, and a visitor who has
 * only ever made contact never gets one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table): void {
            $table->timestamp('current_visit_started_at')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table): void {
            $table->dropColumn('current_visit_started_at');
        });
    }
};
