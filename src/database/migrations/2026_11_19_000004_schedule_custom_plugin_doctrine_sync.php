<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data only: registers the hourly doctrine sync with SeAT's scheduler. It
 * does nothing until doctrine import is configured and turned on.
 */
return new class extends Migration
{
    private const COMMAND = 'custom-plugin:doctrines:sync';

    public function up(): void
    {
        if (DB::table('schedules')->where('command', self::COMMAND)->exists()) {
            return;
        }
        DB::table('schedules')->insert([
            'command' => self::COMMAND,
            'expression' => '7 * * * *', // hourly, off the busy top of the hour
            'allow_overlap' => false,
            'allow_maintenance' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('schedules')->where('command', self::COMMAND)->delete();
    }
};
