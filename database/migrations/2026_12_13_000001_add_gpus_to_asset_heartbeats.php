<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Agent v10: kartu grafis (VGA) per perangkat. Hanya menambah kolom; agent lama tidak mengirimnya (NULL = belum dilaporkan). */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('asset_heartbeats', 'gpus')) {
            Schema::table('asset_heartbeats', fn (Blueprint $t) => $t->json('gpus')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('asset_heartbeats', 'gpus')) {
            Schema::table('asset_heartbeats', fn (Blueprint $t) => $t->dropColumn('gpus'));
        }
    }
};
