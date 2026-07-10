<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The idle-expiry sweep (wayfindr:expire-idle-cobrowse-sessions) runs every
     * five minutes and filters granted, not-yet-ended sessions by last activity:
     * status = 'granted' AND ended_at IS NULL AND updated_at <= cutoff. The
     * existing indexes lead with conversation_id / site_id, so this composite
     * keeps the sweep bounded as retained session history grows — equality
     * columns first (status, ended_at), then the updated_at range.
     */
    public function up(): void
    {
        Schema::table('cobrowse_sessions', function (Blueprint $table) {
            $table->index(['status', 'ended_at', 'updated_at'], 'cobrowse_sessions_idle_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::table('cobrowse_sessions', function (Blueprint $table) {
            $table->dropIndex('cobrowse_sessions_idle_expiry_index');
        });
    }
};
