<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A doctrine on doctrine.nisuwaz.com that a corporation follows.
 *
 * MySQL DDL is not transactional, so every step of these three migrations
 * checks what is already there and a run that stopped half way can be repeated.
 */
return new class extends Migration
{
    private const TABLE = 'custom_plugin_doctrine_sources';

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
            // no FK to corporation_infos: SeAT upserts those rows from ESI
            $table->unsignedBigInteger('corporation_id');
            $table->string('slug', 40); // upstream address, [a-z0-9-]
            $table->string('name', 100); // upstream name at the last sync
            // false = no longer followed: its fits stay and stop updating
            $table->boolean('following')->default(true);
            $table->string('upstream_status', 10)->nullable(); // active, testing, retired
            $table->unsignedInteger('upstream_version')->nullable(); // last version applied
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_changed_at')->nullable();
            $table->string('last_error', 255)->nullable();
            // what the last run could not apply: {"held": [...], "failed": [...]}
            $table->json('last_report')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['corporation_id', 'slug'], 'cp_doctrine_sources_corp_slug_unique');
            $table->foreign('created_by', 'cp_doctrine_sources_created_by_foreign')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
