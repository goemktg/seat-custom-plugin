<?php

use Goemktg\Seat\SeatCustomPlugin\Support\DuplicateFit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Within one corporation, fits on the same hull must have different names, so a
 * Hangar Check ship is compared with at most one fit. Stored fits that already
 * break the rule stop the migration with a list to clean up; nothing is renamed,
 * because the name must match the ship's in-game name and only the owner knows
 * which fit to keep.
 */
return new class extends Migration
{
    private const TABLE = 'custom_plugin_doctrine_fits';

    private const OLD_INDEX = 'cp_doctrine_fits_corp_ship_index';

    public function up(): void
    {
        // GROUP BY compares fit_name by the column collation, exactly as the unique key will
        // (GROUP_CONCAT may cut a long id list; the count stays exact)
        $conflicts = DB::table(self::TABLE)
            ->select('corporation_id', 'ship_type_id', 'fit_name', DB::raw('COUNT(*) AS fits'), DB::raw('GROUP_CONCAT(id ORDER BY id) AS ids'))
            ->groupBy('corporation_id', 'ship_type_id', 'fit_name')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('corporation_id')->orderBy('ship_type_id')
            ->get();
        $report = DuplicateFit::migrationReport($conflicts);
        if ($report !== null) {
            throw new RuntimeException($report);
        }

        // MySQL DDL is not transactional: each step is skipped when already done,
        // so a run that stopped half way can simply be repeated
        if (! $this->hasIndex(DuplicateFit::HULL_KEY)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unique(['corporation_id', 'ship_type_id', 'fit_name'], DuplicateFit::HULL_KEY);
            });
        }
        // the new key starts with the same two columns, so the old index adds nothing
        if ($this->hasIndex(self::OLD_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropIndex(self::OLD_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (! $this->hasIndex(self::OLD_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->index(['corporation_id', 'ship_type_id'], self::OLD_INDEX);
            });
        }
        if ($this->hasIndex(DuplicateFit::HULL_KEY)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropUnique(DuplicateFit::HULL_KEY);
            });
        }
    }

    private function hasIndex(string $name): bool
    {
        return DB::table('information_schema.statistics')
            ->whereRaw('table_schema = DATABASE()')
            ->where('table_name', DB::getTablePrefix() . self::TABLE)
            ->where('index_name', $name)
            ->exists();
    }
};
