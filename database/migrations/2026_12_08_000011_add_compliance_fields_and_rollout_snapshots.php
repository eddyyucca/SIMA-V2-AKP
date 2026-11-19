<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIMA Asset Agent v9 (custom SIMA):
 *  - data kepatuhan HANYA-BACA dari laptop: siapa anggota grup Administrators lokal dan status penyimpanan USB
 *    (agent hanya MELAPORKAN keadaan, tidak mengubah apa pun);
 *  - snapshot harian pencapaian rollout agent (untuk grafik tren): satu baris per hari.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_heartbeats', function (Blueprint $table) {
            $table->text('local_admins')->nullable();                 // JSON: [{name, type, source}]
            $table->boolean('builtin_admin_enabled')->nullable();     // akun Administrator bawaan (RID 500) aktif?
            $table->string('usb_storage', 16)->nullable();            // allowed | blocked | write_blocked | unknown
        });

        Schema::create('agent_rollout_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date')->unique();
            $table->unsignedInteger('laptops_total');
            $table->unsignedInteger('installed');                     // laptop yang pernah melapor
            $table->unsignedInteger('online');                        // laptop yang melapor dalam batas online
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_rollout_snapshots');

        Schema::table('asset_heartbeats', function (Blueprint $table) {
            $table->dropColumn(['local_admins', 'builtin_admin_enabled', 'usb_storage']);
        });
    }
};
