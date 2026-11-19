<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIMA Asset Agent v6 (custom SIMA):
 *  - hasil benchmark kecepatan baca/tulis RAM dan disk (MB/s), diukur agent sekali sehari;
 *  - riwayat perubahan spesifikasi perangkat (ditulis server saat laporan agent berbeda dari laporan sebelumnya).
 * Berbeda dengan asset_heartbeats (satu baris terkini), asset_spec_changes memang LOG: satu baris per perubahan,
 * jumlahnya dibatasi (lihat AssetSpecChangeRecorder::MAX_ROWS_PER_ASSET) supaya tidak membengkak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_heartbeats', function (Blueprint $table) {
            $table->unsignedInteger('ram_read_mbps')->nullable();
            $table->unsignedInteger('ram_write_mbps')->nullable();
            $table->unsignedInteger('disk_read_mbps')->nullable();
            $table->unsignedInteger('disk_write_mbps')->nullable();
            $table->timestamp('benchmark_at')->nullable();
        });

        Schema::create('asset_spec_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->string('category', 20);       // identity | hardware | os | software
            $table->string('field', 40);          // kunci bidang, mis. cpu, ram_total_bytes, software_added
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('changed_at')->useCurrent();

            $table->index(['asset_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_spec_changes');

        Schema::table('asset_heartbeats', function (Blueprint $table) {
            $table->dropColumn(['ram_read_mbps', 'ram_write_mbps', 'disk_read_mbps', 'disk_write_mbps', 'benchmark_at']);
        });
    }
};
