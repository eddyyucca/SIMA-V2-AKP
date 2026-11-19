<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIMA Agent Helper (custom SIMA): program kecil di sesi pengguna yang menampilkan PENGUMUMAN dari IT dan melayani
 * CHAT yang dimulai IT. Tabel di sini: token khusus program pembantu, pengumuman beserta sasaran dan laporan
 * penerimaannya, serta sesi dan pesan chat. Chat hanya bisa dibuka dan ditutup oleh IT (lihat AgentChatSession).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Token khusus program pembantu: HANYA untuk pengumuman dan chat satu aset (bukan token agent), dirotasi otomatis.
        Schema::create('asset_helper_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_id')->unique();
            $table->string('token_hash', 64)->unique();      // SHA-256 dari token acak 40 karakter
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->text('body');
            $table->string('severity', 12)->default('info');  // info | important | urgent
            $table->boolean('require_ack')->default(false);   // wajib dikonfirmasi pengguna ("Saya sudah membaca")
            $table->string('target_mode', 10)->default('all'); // all | selected
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('ended_at')->nullable();         // dihentikan manual oleh IT
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_announcement_targets', function (Blueprint $table) {
            $table->unsignedBigInteger('announcement_id');
            $table->unsignedBigInteger('asset_id');
            $table->primary(['announcement_id', 'asset_id']);
        });

        Schema::create('agent_announcement_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('announcement_id');
            $table->unsignedBigInteger('asset_id');
            $table->timestamp('shown_at')->nullable();
            $table->timestamp('acked_at')->nullable();
            $table->timestamps();
            $table->unique(['announcement_id', 'asset_id']);
        });

        Schema::create('agent_chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_id')->index();
            $table->unsignedBigInteger('started_by')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->string('status', 8)->default('open');     // open | closed
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 12)->nullable();   // admin | idle
        });

        Schema::create('agent_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('chat_session_id')->index();
            $table->string('sender', 4);                      // it | user
            $table->unsignedBigInteger('user_id')->nullable();   // admin IT yang menulis (sender = it)
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_chat_messages');
        Schema::dropIfExists('agent_chat_sessions');
        Schema::dropIfExists('agent_announcement_deliveries');
        Schema::dropIfExists('agent_announcement_targets');
        Schema::dropIfExists('agent_announcements');
        Schema::dropIfExists('asset_helper_tokens');
    }
};
