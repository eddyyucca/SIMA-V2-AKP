<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIMA Asset Agent (custom SIMA): data detail tambahan dari agen, murni fakta perangkat yang dibaca lewat
 * WMI/CIM (bukan log — tetap satu baris terkini per aset, lihat migrasi sebelumnya). Lihat
 * docs/rencana-agent-monitoring-aset.md § "Data detail".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_heartbeats', function (Blueprint $table) {
            // Hardware (dibandingkan dengan data aset di SIMA untuk deteksi penukaran perangkat)
            $table->string('manufacturer', 191)->nullable()->after('os_version');
            $table->string('model', 191)->nullable()->after('manufacturer');
            $table->string('serial_number', 191)->nullable()->after('model');
            $table->string('cpu', 191)->nullable()->after('serial_number');
            $table->unsignedBigInteger('ram_total_bytes')->nullable()->after('cpu');
            $table->unsignedBigInteger('disk_total_bytes')->nullable()->after('disk_free_bytes');
            $table->string('os_build', 50)->nullable()->after('ram_total_bytes');
            $table->string('os_architecture', 20)->nullable()->after('os_build');
            $table->string('domain', 191)->nullable()->after('os_architecture');
            $table->timestamp('last_boot_at')->nullable()->after('domain');

            // Kepatuhan IT
            $table->string('bitlocker_status', 20)->nullable()->after('last_boot_at'); // 'on' | 'off' | 'unknown'
            $table->boolean('defender_enabled')->nullable()->after('bitlocker_status');
            $table->timestamp('defender_signature_at')->nullable()->after('defender_enabled');
            $table->timestamp('last_patch_at')->nullable()->after('defender_signature_at');
        });
    }

    public function down(): void
    {
        Schema::table('asset_heartbeats', function (Blueprint $table) {
            $table->dropColumn([
                'manufacturer', 'model', 'serial_number', 'cpu', 'ram_total_bytes', 'disk_total_bytes',
                'os_build', 'os_architecture', 'domain', 'last_boot_at',
                'bitlocker_status', 'defender_enabled', 'defender_signature_at', 'last_patch_at',
            ]);
        });
    }
};
