<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock ledger: purchases, losses and adjustments per fit and staging.
 * A new table only, so it takes no lock on existing data. down() drops it.
 */
return new class extends Migration
{
    private const TABLE = 'custom_plugin_doctrine_fit_stock_entries';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->bigIncrements('id');
            // fit and staging must share a corporation (checked in app, as for targets)
            $table->unsignedBigInteger('fit_id');
            $table->unsignedBigInteger('staging_id');
            $table->string('kind', 16);
            $table->integer('quantity'); // signed; stock = SUM(quantity)
            $table->date('occurred_on');
            $table->string('note', 255)->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->string('created_by_name', 255)->nullable(); // kept when the user is gone
            $table->timestamp('created_at')->useCurrent(); // records are never edited

            // leads with fit_id, so it also serves the fit foreign key
            $table->index(['fit_id', 'staging_id', 'occurred_on'], 'cp_fit_stock_fit_staging_date_index');
            $table->index('staging_id', 'cp_fit_stock_staging_index');

            $table->foreign('fit_id', 'cp_fit_stock_fit_foreign')
                ->references('id')->on('custom_plugin_doctrine_fits')->cascadeOnDelete();
            // a staging with records cannot be deleted; the app says so first
            $table->foreign('staging_id', 'cp_fit_stock_staging_foreign')
                ->references('id')->on('custom_plugin_doctrine_stagings')->restrictOnDelete();
            $table->foreign('created_by', 'cp_fit_stock_created_by_foreign')
                ->references('id')->on('users')->nullOnDelete();
        });

        $table = self::TABLE;
        DB::statement("ALTER TABLE `$table` ADD CONSTRAINT `cp_fit_stock_kind_check` CHECK (`kind` IN ('purchase', 'loss', 'adjust'))");
        DB::statement("ALTER TABLE `$table` ADD CONSTRAINT `cp_fit_stock_quantity_check` CHECK ("
            . "(`kind` = 'purchase' AND `quantity` > 0) OR (`kind` = 'loss' AND `quantity` < 0) OR (`kind` = 'adjust' AND `quantity` <> 0))");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
