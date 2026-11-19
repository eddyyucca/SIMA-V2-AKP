<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIMA Asset Agent v4 (custom SIMA): jaringan, baterai, semua disk, Windows Update tertunda, dan daftar software.
 * Tetap satu baris TERKINI per aset (bukan log). Semua kolom nullable: agent lama tidak mengirimnya.
 * Software dan update tertunda dikirim agent sekali sehari (bagian "inventaris"), sisanya tiap laporan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_heartbeats', function (Blueprint $table) {
            // Jaringan
            $table->string('network_type', 20)->nullable();          // 'wifi' | 'ethernet' | 'other'
            $table->string('wifi_ssid', 191)->nullable();
            $table->string('mac_address', 17)->nullable();
            $table->string('gateway', 45)->nullable();

            // Baterai (perangkat tanpa baterai: battery_present = false)
            $table->boolean('battery_present')->nullable();
            $table->unsignedTinyInteger('battery_percent')->nullable();
            $table->boolean('battery_charging')->nullable();
            $table->unsignedTinyInteger('battery_health_percent')->nullable();

            // Disk: semua drive tetap + kesehatan disk fisik (JSON)
            $table->text('disks')->nullable();
            $table->text('physical_disks')->nullable();

            // Windows Update
            $table->boolean('reboot_pending')->nullable();
            $table->unsignedSmallInteger('pending_updates_count')->nullable();
            $table->text('pending_updates')->nullable();             // JSON: judul update (maks. 25)
            $table->timestamp('updates_checked_at')->nullable();

            // Software terpasang (JSON, dikirim sehari sekali)
            $table->longText('software')->nullable();
            $table->unsignedSmallInteger('software_count')->nullable();
            $table->timestamp('software_reported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('asset_heartbeats', function (Blueprint $table) {
            $table->dropColumn([
                'network_type', 'wifi_ssid', 'mac_address', 'gateway',
                'battery_present', 'battery_percent', 'battery_charging', 'battery_health_percent',
                'disks', 'physical_disks',
                'reboot_pending', 'pending_updates_count', 'pending_updates', 'updates_checked_at',
                'software', 'software_count', 'software_reported_at',
            ]);
        });
    }
};
