<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The erased contact's site, by its public key, kept on the ledger row itself
 * (ADR 0026 §8). `site_id` is nulled when the site is purged, and a restore
 * that brings the site back can only re-apply the erasure safely by matching
 * the key: an archive from another install can hold a site, and visitors,
 * under the same IDs. Rows written before this column copy the key while
 * their site still has one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_erasures', function (Blueprint $table): void {
            $table->string('site_public_key')->nullable()->after('site_id');
        });

        DB::table('visitor_erasures')
            ->whereNotNull('site_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                $keys = DB::table('sites')->whereIn('id', $rows->pluck('site_id')->unique()->all())->pluck('public_key', 'id');

                foreach ($rows as $row) {
                    DB::table('visitor_erasures')->where('id', $row->id)->update(['site_public_key' => $keys[$row->site_id] ?? null]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('visitor_erasures', function (Blueprint $table): void {
            $table->dropColumn('site_public_key');
        });
    }
};
