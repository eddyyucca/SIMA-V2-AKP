<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Dokumen pendukung per baris Riwayat Stok (faktur / surat jalan / BAST bertanda tangan). Hanya menambah tabel baru. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movement_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_movement_id')->constrained('stock_movements')->cascadeOnDelete();
            $t->string('path');
            $t->string('original_name')->nullable();
            $t->string('note')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movement_files');
    }
};
