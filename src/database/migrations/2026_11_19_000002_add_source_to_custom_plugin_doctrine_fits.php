<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Links a fit to the followed doctrine it was imported from.
 *
 * Existing rows keep NULL in every new column and stay manual fits.
 */
return new class extends Migration
{
    private const TABLE = 'custom_plugin_doctrine_fits';

    private const FOREIGN = 'cp_doctrine_fits_source_foreign';

    private const INDEX = 'cp_doctrine_fits_source_ship_name_index';

    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'source_id')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unsignedBigInteger('source_id')->nullable()->after('container'); // NULL = manual fit
            });
        }
        if (! Schema::hasColumn(self::TABLE, 'sync_state')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                // NULL = in step with upstream, 'missing' = no longer upstream
                $table->string('sync_state', 20)->nullable()->after('source_id');
            });
        }
        if (! Schema::hasColumn(self::TABLE, 'source_missing_at')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->timestamp('source_missing_at')->nullable()->after('sync_state');
            });
        }
        if (! Schema::hasColumn(self::TABLE, 'source_eft_hash')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                // sha256 of the upstream EFT last applied, to skip unchanged fits and notice local edits
                $table->char('source_eft_hash', 64)->nullable()->after('source_missing_at');
            });
        }
        if (! Schema::hasColumn(self::TABLE, 'source_synced_at')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->timestamp('source_synced_at')->nullable()->after('source_eft_hash');
            });
        }
        // matches a linked fit to its upstream fit; leads with source_id, so it serves the foreign key too
        if (! $this->hasIndex(self::TABLE, self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->index(['source_id', 'ship_type_id', 'fit_name'], self::INDEX);
            });
        }
        if (! $this->hasForeign(self::TABLE, self::FOREIGN)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->foreign('source_id', self::FOREIGN)
                    ->references('id')->on('custom_plugin_doctrine_sources')->nullOnDelete();
            });
        }
    }

    /** Imported fits stay as manual fits; which doctrine they came from is lost. */
    public function down(): void
    {
        // the foreign key first: MariaDB refuses to drop an index or column it still uses
        if ($this->hasForeign(self::TABLE, self::FOREIGN)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropForeign(self::FOREIGN);
            });
        }
        if ($this->hasIndex(self::TABLE, self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }
        foreach (['source_synced_at', 'source_eft_hash', 'source_missing_at', 'sync_state', 'source_id'] as $column) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        return DB::table('information_schema.statistics')
            ->whereRaw('table_schema = DATABASE()')
            ->where('table_name', DB::getTablePrefix() . $table)
            ->where('index_name', $name)
            ->exists();
    }

    private function hasForeign(string $table, string $name): bool
    {
        return DB::table('information_schema.table_constraints')
            ->whereRaw('constraint_schema = DATABASE()')
            ->where('table_name', DB::getTablePrefix() . $table)
            ->where('constraint_name', $name)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }
};
