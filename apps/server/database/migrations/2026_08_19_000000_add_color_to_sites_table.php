<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The palette is repeated here rather than read from App\Enums\SiteColor.
     *
     * A migration has to keep producing the same result years from now. Reading
     * the enum would make this backfill change meaning the moment the palette
     * is edited, which is exactly what a migration must not do.
     *
     * @var list<string>
     */
    private const PALETTE = ['red', 'blue', 'ochre', 'pine', 'violet', 'rust'];

    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('color', 16)->nullable()->after('domain');
        });

        // An existing install must not land on a screen of identically coloured
        // rows. Assign in creation order WITHIN each account, so one desk's
        // sites are distinct from each other until the palette repeats -- which
        // is the only comparison an agent actually makes.
        $position = [];

        DB::table('sites')
            ->select('id', 'account_id')
            ->orderBy('account_id')
            ->orderBy('id')
            ->each(function (object $site) use (&$position): void {
                $account = (int) $site->account_id;
                $index = $position[$account] ?? 0;
                $position[$account] = $index + 1;

                DB::table('sites')
                    ->where('id', $site->id)
                    ->update(['color' => self::PALETTE[$index % count(self::PALETTE)]]);
            });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('color');
        });
    }
};
