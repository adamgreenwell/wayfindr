<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The column the presence surfaces actually filter on.
 *
 * `(site_id, last_seen_at)` was indexed when the directory ordered and filtered
 * by that column. Presence moved every one of those reads to
 * `last_web_seen_at` -- the directory's Active filter, the conversation queue's
 * presence filter, and the live board's whole query -- so the index that made
 * them fast now covers a column none of them look at.
 *
 * It matters more than a normal ordering index, because presence changes what
 * `visitors` holds. Before it, a row meant somebody opened the chat; after, on
 * an opted-in site, it means somebody loaded a page. The table it scans grows
 * from "people who got in touch" to "everybody who visited", and the range scan
 * behind every board refresh runs against that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table): void {
            $table->index(['site_id', 'last_web_seen_at'], 'visitors_site_id_last_web_seen_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table): void {
            $table->dropIndex('visitors_site_id_last_web_seen_at_index');
        });
    }
};
