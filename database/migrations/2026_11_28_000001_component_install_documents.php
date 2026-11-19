<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemasangan / pelepasan komponen pada aset (upgrade, perbaikan) kini punya dokumen BAST sendiri (type 'install'):
 * alasan, perubahan spesifikasi (sebelum -> sesudah), dan tiap baris berstatus dipasang / dilepas. Hanya menambah kolom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accessory_handovers', function (Blueprint $t) {
            $t->string('reason', 30)->nullable()->after('notes');           // Upgrade | Perbaikan | Penambahan
            $t->json('meta')->nullable()->after('reason');                  // mis. perubahan spesifikasi sebelum -> sesudah
        });
        Schema::table('accessory_handover_items', function (Blueprint $t) {
            $t->string('action', 10)->nullable()->after('qty');             // install | remove (khusus dokumen pemasangan)
        });
    }

    public function down(): void
    {
        Schema::table('accessory_handover_items', fn (Blueprint $t) => $t->dropColumn('action'));
        Schema::table('accessory_handovers', fn (Blueprint $t) => $t->dropColumn(['reason', 'meta']));
    }
};
