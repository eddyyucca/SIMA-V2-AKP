<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIMA Asset Agent: pencarian token yang cepat. Sebelumnya setiap laporan agent memeriksa bcrypt ke SEMUA token aktif
 * (O(N) per laporan: dengan 400 laptop, puluhan detik CPU per laporan). Kini token acak berentropi tinggi juga disimpan
 * sebagai SHA-256 beriindeks (token_lookup) untuk pencarian langsung; bcrypt tetap diperiksa untuk SATU token yang cocok.
 * Token lama (token_lookup kosong) tetap berlaku dan diisi otomatis saat pertama kali dipakai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_agent_tokens', function (Blueprint $table) {
            $table->string('token_lookup', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('asset_agent_tokens', function (Blueprint $table) {
            $table->dropUnique(['token_lookup']);
            $table->dropColumn('token_lookup');
        });
    }
};
