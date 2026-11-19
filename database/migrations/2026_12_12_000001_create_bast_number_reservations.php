<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nomor BAST yang sudah tercetak pada formulir (sebelum serah terima dicatat). Nomor dicadangkan saat formulir dicetak,
 * lalu dipakai persis oleh serah terima yang dicatat sesudahnya (aset + jenis sama), sehingga nomor di kertas = nomor di sistem.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('bast_number_reservations', function (Blueprint $t) {
            $t->id();
            $t->string('bast_number')->unique();
            $t->foreignId('asset_id')->constrained();
            $t->string('type', 20);
            $t->foreignId('reserved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('asset_assignment_id')->nullable()->constrained('asset_assignments')->nullOnDelete();
            $t->timestamp('used_at')->nullable();
            $t->timestamps();
            $t->index(['asset_id', 'type', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bast_number_reservations');
    }
};
