<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A ledger row outlives its account, as the ledger file on the storage volume
 * already does (ADR 0026 §8). The rows are what fills a new volume's ledger:
 * one that went with its account left that volume knowing some erasures and
 * not others, while a restore from before the account was removed brings the
 * account back with the erased contacts in it. A row holds internal IDs, the
 * site's key and counts only, so keeping it keeps nothing about anyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_erasures', function (Blueprint $table): void {
            $table->dropForeign(['account_id']);
        });
    }

    public function down(): void
    {
        // The constraint cannot come back over rows whose account is gone.
        DB::table('visitor_erasures')
            ->whereNotIn('account_id', DB::table('accounts')->select('id'))
            ->delete();

        Schema::table('visitor_erasures', function (Blueprint $table): void {
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
        });
    }
};
