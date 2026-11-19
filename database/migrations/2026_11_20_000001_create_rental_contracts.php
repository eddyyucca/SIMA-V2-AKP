<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arsip kontrak sewa per aset: dua slot (Kontrak 1 & Kontrak 2), tiap slot punya riwayat
 * kontrak awal + pembaruan tahunan. Baris terbaru per slot = kontrak berjalan. Tidak pernah dihapus otomatis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_contracts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('asset_id')->constrained();
            $t->unsignedTinyInteger('slot')->default(1);          // 1 = Kontrak 1, 2 = Kontrak 2
            $t->string('kind', 10)->default('initial');           // initial | renewal
            $t->string('contract_no')->nullable();
            $t->date('start_date');
            $t->date('end_date');
            $t->string('path');
            $t->string('note')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['asset_id', 'slot', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_contracts');
    }
};
