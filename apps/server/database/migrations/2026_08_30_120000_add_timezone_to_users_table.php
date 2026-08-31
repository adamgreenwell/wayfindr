<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The clock one agent reads their own dashboard in.
 *
 * Nullable, and null is the common case rather than an edge one: every agent
 * that exists predates this column, so "not chosen" has to mean the install
 * default rather than a broken page.
 *
 * Long enough for the longest IANA identifier -- `America/Argentina/ComodRivadavia`
 * is 32 -- with room, because that list grows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('timezone', 64)->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });
    }
};
