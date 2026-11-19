<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What each sync run did, shown as "Recent changes". History only: the current
 * state lives on the fit (sync_state) and the source (last_report).
 */
return new class extends Migration
{
    private const TABLE = 'custom_plugin_doctrine_sync_events';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->bigIncrements('id');
            $table->char('run_id', 26); // one ULID per sync run
            $table->unsignedBigInteger('corporation_id');
            // both stay readable after the source or fit is gone: the labels are snapshots
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('fit_id')->nullable();
            $table->string('kind', 20); // added, updated, missing, returned, held, failed
            $table->string('doctrine_label', 100);
            $table->string('fit_label', 255)->nullable(); // "Fit name (Hull)"
            $table->string('detail', 500)->nullable();
            $table->unsignedInteger('created_by')->nullable(); // NULL = the hourly sync
            $table->string('created_by_name', 255)->nullable(); // kept when the user is gone
            $table->timestamp('created_at')->useCurrent();

            $table->index(['corporation_id', 'created_at'], 'cp_doctrine_sync_events_corp_created_index');
            $table->index('created_at', 'cp_doctrine_sync_events_created_index'); // pruning
            $table->index('run_id', 'cp_doctrine_sync_events_run_index');

            $table->foreign('source_id', 'cp_doctrine_sync_events_source_foreign')
                ->references('id')->on('custom_plugin_doctrine_sources')->nullOnDelete();
            $table->foreign('fit_id', 'cp_doctrine_sync_events_fit_foreign')
                ->references('id')->on('custom_plugin_doctrine_fits')->nullOnDelete();
            $table->foreign('created_by', 'cp_doctrine_sync_events_created_by_foreign')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
