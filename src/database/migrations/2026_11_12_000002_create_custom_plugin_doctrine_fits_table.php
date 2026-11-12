<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_plugin_doctrine_fits', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->bigIncrements('id');
            $table->unsignedBigInteger('corporation_id');
            $table->string('doctrine', 100);
            $table->string('fit_name', 100);
            $table->unsignedInteger('ship_type_id');
            $table->text('eft'); // single source of truth for fitted modules and EFT cargo
            $table->json('bays')->nullable(); // {"FuelBay": [{"name", "qty", "typeId"}], ...}
            $table->json('container')->nullable(); // [{"name", "qty", "typeId"}]
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['corporation_id', 'doctrine', 'fit_name'], 'cp_doctrine_fits_corp_doctrine_name_unique');
            $table->index(['corporation_id', 'ship_type_id'], 'cp_doctrine_fits_corp_ship_index');

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_plugin_doctrine_fits');
    }
};
