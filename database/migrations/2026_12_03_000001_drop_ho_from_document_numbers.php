<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Nomor dokumen tidak lagi memuat "HO": 010/IT/BPB/HO/AKP/XI/2026 -> 010/IT/BPB/AKP/XI/2026 (dokumen dibuat dari nomor di database, jadi ikut berubah). */
return new class extends Migration {
    public function up(): void
    {
        DB::table('accessory_handovers')->where('bast_number', 'like', '%/HO/AKP/%')->update(['bast_number' => DB::raw("REPLACE(bast_number, '/HO/AKP/', '/AKP/')")]);
    }

    public function down(): void
    {
        DB::table('accessory_handovers')->where('bast_number', 'like', '%/IT/%/AKP/%')->where('bast_number', 'not like', '%/HO/%')
            ->update(['bast_number' => DB::raw("REPLACE(bast_number, '/AKP/', '/HO/AKP/')")]);
    }
};
