<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIMA Asset Agent v5 (custom SIMA): detail prosesor dan memori. Tetap satu baris TERKINI per aset.
 * Semua kolom nullable: agent lama tidak mengirimnya. Modul RAM disimpan sebagai JSON (satu entri per keping).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_heartbeats', function (Blueprint $table) {
            // Prosesor (nama model sudah ada di kolom `cpu`)
            $table->string('cpu_manufacturer', 64)->nullable();
            $table->unsignedSmallInteger('cpu_cores')->nullable();
            $table->unsignedSmallInteger('cpu_logical')->nullable();
            $table->unsignedInteger('cpu_max_mhz')->nullable();
            $table->unsignedInteger('cpu_current_mhz')->nullable();
            $table->unsignedTinyInteger('cpu_load_percent')->nullable();
            $table->unsignedInteger('cpu_l2_kb')->nullable();
            $table->unsignedInteger('cpu_l3_kb')->nullable();

            // Memori (total sudah ada di `ram_total_bytes`)
            $table->unsignedBigInteger('ram_free_bytes')->nullable();
            $table->unsignedTinyInteger('ram_slots_total')->nullable();
            $table->unsignedBigInteger('ram_max_bytes')->nullable();
            $table->text('ram_modules')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('asset_heartbeats', function (Blueprint $table) {
            $table->dropColumn([
                'cpu_manufacturer', 'cpu_cores', 'cpu_logical', 'cpu_max_mhz', 'cpu_current_mhz',
                'cpu_load_percent', 'cpu_l2_kb', 'cpu_l3_kb',
                'ram_free_bytes', 'ram_slots_total', 'ram_max_bytes', 'ram_modules',
            ]);
        });
    }
};
