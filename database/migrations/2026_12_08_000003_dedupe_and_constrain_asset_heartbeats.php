<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SIMA Asset Agent (custom SIMA): asset_heartbeats berubah dari log yang terus bertambah menjadi SATU baris
 * terkini per aset (upsert), supaya tabelnya tidak membengkak dengan ratusan laptop melapor tiap beberapa
 * menit. Baris lama (kalau ada, dari sebelum migrasi ini) dibuang kecuali yang paling baru per aset, lalu
 * asset_id dikunci unik. Lihat docs/rencana-agent-monitoring-aset.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Buang duplikat: untuk tiap asset_id, simpan hanya baris dengan id terbesar (paling baru).
        // (tabel baru di aplikasi ini, jadi praktis kosong; SQL portabel untuk MariaDB dan PostgreSQL)
        DB::statement('DELETE FROM asset_heartbeats WHERE id < (SELECT MAX(h2.id) FROM (SELECT id, asset_id FROM asset_heartbeats) h2 WHERE h2.asset_id = asset_heartbeats.asset_id)');

        Schema::table('asset_heartbeats', function (Blueprint $table) {
            $table->unique('asset_id');
        });
    }

    public function down(): void
    {
        Schema::table('asset_heartbeats', function (Blueprint $table) {
            $table->dropUnique(['asset_id']);
        });
    }
};
