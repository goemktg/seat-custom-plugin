<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which shared fit of the doctrine site a synced fit is a copy of, so a copy
 * that another followed doctrine brings in is recognised as the same fit.
 *
 * Meaningful only while source_id is set. Existing rows get theirs on their
 * doctrine's next sync; the doctrine site's data is not kept here to fill it now.
 */
return new class extends Migration
{
    private const TABLE = 'custom_plugin_doctrine_fits';

    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'source_shared_id')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                // the doctrine site's shared_fits.id; its size is the site's, so 64 bits
                $table->unsignedBigInteger('source_shared_id')->nullable()->after('source_eft_hash');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn(self::TABLE, 'source_shared_id')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropColumn('source_shared_id');
            });
        }
    }
};
