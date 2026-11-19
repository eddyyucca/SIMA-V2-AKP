<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** PostgreSQL: Filament membaca notifications.data dengan operator JSON (->>), jadi kolomnya harus jsonb, bukan text. */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql' && Schema::hasTable('notifications')) {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE jsonb USING data::jsonb');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql' && Schema::hasTable('notifications')) {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');
        }
    }
};
