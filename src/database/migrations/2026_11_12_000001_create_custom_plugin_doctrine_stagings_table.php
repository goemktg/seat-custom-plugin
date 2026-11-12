<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_plugin_doctrine_stagings', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->bigIncrements('id');
            // no FK to corporation_infos: SeAT upserts those rows from ESI
            $table->unsignedBigInteger('corporation_id');
            $table->unsignedBigInteger('location_id'); // station or structure id
            $table->string('name', 255);
            $table->timestamps();

            $table->unique(['corporation_id', 'location_id'], 'cp_doctrine_stagings_corp_location_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_plugin_doctrine_stagings');
    }
};
