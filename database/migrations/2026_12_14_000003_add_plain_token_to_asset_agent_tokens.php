<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Token agent baru ditampilkan (terenkripsi) sampai agent terpasang / token diperbarui, lalu dihapus. Hanya menambah kolom. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_agent_tokens', fn (Blueprint $t) => $t->text('plain_token')->nullable());
    }

    public function down(): void
    {
        Schema::table('asset_agent_tokens', fn (Blueprint $t) => $t->dropColumn('plain_token'));
    }
};
