<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BAST aset: kelengkapan (komponen terpasang) ikut diperbarui saat komponen dipasang / dilepas pada aset yang sedang dipegang.
 * Kolom ini mencatat kapan kelengkapan BAST itu terakhir diperbarui (kosong = sesuai tanggal BAST). Hanya menambah kolom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_assignments', function (Blueprint $t) {
            $t->dateTime('equipment_updated_at')->nullable()->after('assigned_at');
        });
    }

    public function down(): void
    {
        Schema::table('asset_assignments', fn (Blueprint $t) => $t->dropColumn('equipment_updated_at'));
    }
};
