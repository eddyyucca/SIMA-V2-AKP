<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tahap persetujuan atasan ditiadakan: semua request langsung ke Tim IT. Request yang sempat berstatus "Awaiting Supervisor"
 * dipindahkan ke "Pending" (antrean Tim IT). Kolom supervisor_* tetap ada (hanya riwayat); tidak ada data yang dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('i_t_requests')->where('status', 'Awaiting Supervisor')->update(['status' => 'Pending']);
    }

    public function down(): void
    {
        // tidak dapat dipulihkan: status asal tidak tercatat
    }
};
