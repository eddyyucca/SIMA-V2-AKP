<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat kode aset: setiap kode yang pernah dipakai sebuah aset (mis. AKP-IT-LPT-001 sebelum menjadi AKP-IT-NB-0018) disimpan di sini
 * supaya label lama yang sudah ditempel tetap bisa discan. Hanya menambah tabel; kode lama di kolom legacy_tag ikut disalin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_tag_aliases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $t->string('tag')->index();
            $t->timestamps();
            $t->unique(['asset_id', 'tag']);
        });

        $now = now();
        DB::table('assets')->whereNotNull('legacy_tag')->where('legacy_tag', '!=', '')->orderBy('id')->get(['id', 'legacy_tag'])->each(
            fn ($a) => DB::table('asset_tag_aliases')->insertOrIgnore(['asset_id' => $a->id, 'tag' => $a->legacy_tag, 'created_at' => $now, 'updated_at' => $now])
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_tag_aliases');
    }
};
