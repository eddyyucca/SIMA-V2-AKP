<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pemetaan id SIMA lama -> id baru (impor SIMA, idempoten). Tetap disimpan sebagai jejak audit setelah cutover. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_map', function (Blueprint $t) {
            $t->id();
            $t->string('entity', 40);
            $t->unsignedBigInteger('legacy_id');
            $t->unsignedBigInteger('new_id');
            $t->timestamps();
            $t->unique(['entity', 'legacy_id']);
            $t->index(['entity', 'new_id']);
        });
    }

    public function down(): void { Schema::dropIfExists('legacy_map'); }
};
