<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_plugin_doctrine_fit_targets', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            // a missing row means target 0; fit and staging must share a corporation (checked in app)
            $table->unsignedBigInteger('fit_id');
            $table->unsignedBigInteger('staging_id');
            $table->unsignedInteger('target')->default(0);
            $table->timestamps();

            $table->primary(['fit_id', 'staging_id']);
            $table->index('staging_id', 'cp_doctrine_fit_targets_staging_index');

            $table->foreign('fit_id')->references('id')->on('custom_plugin_doctrine_fits')->cascadeOnDelete();
            $table->foreign('staging_id')->references('id')->on('custom_plugin_doctrine_stagings')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_plugin_doctrine_fit_targets');
    }
};
