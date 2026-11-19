<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIMA Asset Agent v7 (custom SIMA): antrean PERINTAH untuk laptop terpilih. Agent mengambilnya lewat jawaban heartbeat
 * dan hanya menjalankan jenis perintah dari DAFTAR TETAP (lihat App\Models\AssetAgentCommand::TYPES), bukan perintah bebas.
 * Tabel ini sekaligus catatan audit: siapa meminta apa, kapan, dan apa hasilnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_agent_commands', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->string('type', 30);                   // refresh | collect_log | diagnostics | notify_user
            $table->text('params')->nullable();           // JSON, mis. {"message": "..."} untuk notify_user
            $table->string('status', 12)->default('pending'); // pending | sent | done | failed | expired | cancelled
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->mediumText('result')->nullable();     // dipotong di sisi server (lihat AssetAgentCommand::RESULT_MAX)

            $table->index(['asset_id', 'status']);
            $table->index(['asset_id', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_agent_commands');
    }
};
