<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The visitor index sorts and filters on last_seen_at within a site, and
 * nothing supported that.
 *
 * `visitors` carried unique keys on (site_id, external_id) and
 * (site_id, anonymous_id) and an index on (site_id, email) -- all point
 * lookups. A list query could use the site_id prefix and then filtered and
 * sorted unindexed across every visitor ever recorded for that site, which
 * grows without bound because visitors have no retention policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table): void {
            $table->index(['site_id', 'last_seen_at'], 'visitors_site_id_last_seen_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table): void {
            $table->dropIndex('visitors_site_id_last_seen_at_index');
        });
    }
};
