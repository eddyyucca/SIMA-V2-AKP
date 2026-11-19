<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Foto per model aset (laptop, monitor, ...): dipakai semua aset bermodel itu yang belum punya foto sendiri. Hanya menambah kolom. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('asset_models', 'photo_path')) {
            Schema::table('asset_models', fn (Blueprint $t) => $t->string('photo_path')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('asset_models', 'photo_path')) {
            Schema::table('asset_models', fn (Blueprint $t) => $t->dropColumn('photo_path'));
        }
    }
};
