<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Satuan consumable tidak lagi dibatasi 5 pilihan tetap, supaya satuan baru bisa ditambah dari form. Nilai lama tidak berubah. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('consumables')) return;
        $driver = DB::getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE consumables MODIFY unit VARCHAR(30) NOT NULL DEFAULT 'pcs'");
        } elseif ($driver === 'pgsql') {
            // enum di PostgreSQL = varchar + CHECK; buang CHECK-nya
            DB::statement('ALTER TABLE consumables DROP CONSTRAINT IF EXISTS consumables_unit_check');
            DB::statement('ALTER TABLE consumables ALTER COLUMN unit TYPE VARCHAR(30)');
        }
    }

    public function down(): void
    {
        // dibiarkan: mengembalikan ke enum bisa membuang satuan baru
    }
};
