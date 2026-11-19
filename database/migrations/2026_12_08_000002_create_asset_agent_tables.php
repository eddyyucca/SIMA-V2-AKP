<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIMA Asset Agent (custom SIMA). Lihat docs/rencana-agent-monitoring-aset.md.
 *
 * asset_agent_tokens = satu token per aset (di-hash, tidak pernah disimpan plaintext); laptop yang memegang
 *                       token ini mengirim heartbeat atas nama aset tersebut. Dicabut (revoked_at) saat aset
 *                       di-checkin atau diarsipkan, supaya laptop lama tidak terus lapor ke aset yang sudah pindah.
 * asset_heartbeats    = riwayat mentah tiap laporan (boleh di-prune berkala). Nilai TERKINI ada di
 *                       assets.agent_last_seen / assets.agent_status (migrasi sebelumnya), dibaca tanpa join.
 *
 * Tanpa foreign key constraint ke assets/users, mengikuti konvensi tabel custom SIMA lain di repo ini
 * (mis. bast_histories): relasi dijaga di level aplikasi, bukan constraint, karena assets.id bertipe
 * int unsigned (bukan bigint) sehingga foreignId() bawaan Laravel (bigint) tidak cocok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_agent_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->string('token_hash', 191);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index('asset_id');
        });

        Schema::create('asset_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->string('hostname', 191)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('os_version', 191)->nullable();
            $table->unsignedBigInteger('disk_free_bytes')->nullable();
            $table->unsignedSmallInteger('agent_version')->nullable();
            $table->timestamp('reported_at');
            $table->timestamps();
            $table->index(['asset_id', 'reported_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_heartbeats');
        Schema::dropIfExists('asset_agent_tokens');
    }
};
