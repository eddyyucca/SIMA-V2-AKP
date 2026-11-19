<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Arsip PDF BAST bertanda tangan: banyak file per aset (satu atau lebih per mutasi), tidak pernah dihapus otomatis.
 * Menggantikan kolom bast_signed_* (satu file per BAST) yang dipindahkan ke tabel ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_bast_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('asset_id')->constrained();
            $t->foreignId('asset_assignment_id')->nullable()->constrained('asset_assignments')->nullOnDelete();
            $t->string('path');
            $t->string('note')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['asset_id', 'created_at']);
        });

        // pindahkan unggahan yang sudah ada (jika ada) supaya tidak ada file yang hilang dari daftar
        foreach (DB::table('asset_assignments')->whereNotNull('bast_signed_path')->get() as $r) {
            DB::table('asset_bast_files')->insert([
                'asset_id' => $r->asset_id, 'asset_assignment_id' => $r->id, 'path' => $r->bast_signed_path,
                'uploaded_by' => $r->bast_signed_by, 'created_at' => $r->bast_signed_at ?? now(), 'updated_at' => $r->bast_signed_at ?? now(),
            ]);
        }

        Schema::table('asset_assignments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('bast_signed_by');
            $t->dropColumn(['bast_signed_path', 'bast_signed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('asset_assignments', function (Blueprint $t) {
            $t->string('bast_signed_path')->nullable();
            $t->dateTime('bast_signed_at')->nullable();
            $t->foreignId('bast_signed_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::dropIfExists('asset_bast_files');
    }
};
