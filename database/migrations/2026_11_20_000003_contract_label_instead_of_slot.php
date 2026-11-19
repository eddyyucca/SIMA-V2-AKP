<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jenis kontrak bebas (label teks) menggantikan slot 1/2. Data lama: slot 1 -> "Kontrak 1", slot 2 -> "Kontrak 2".
 * Bisa diulang bila sempat berhenti di tengah (DDL MySQL/MariaDB tidak bisa di-rollback).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rental_contracts', 'label')) {
            Schema::table('rental_contracts', fn (Blueprint $t) => $t->string('label', 60)->default('Kontrak')->after('asset_id'));
        }
        if (Schema::hasColumn('rental_contracts', 'slot')) {
            DB::table('rental_contracts')->orderBy('id')->each(
                fn ($r) => DB::table('rental_contracts')->where('id', $r->id)->update(['label' => 'Kontrak ' . $r->slot])
            );
        }
        // indeks pengganti dibuat dulu: foreign key asset_id butuh indeks yang berawalan asset_id
        if (! Schema::hasIndex('rental_contracts', ['asset_id', 'label', 'end_date'])) {
            Schema::table('rental_contracts', fn (Blueprint $t) => $t->index(['asset_id', 'label', 'end_date']));
        }
        if (Schema::hasColumn('rental_contracts', 'slot')) {
            if (Schema::hasIndex('rental_contracts', ['asset_id', 'slot', 'end_date'])) {
                Schema::table('rental_contracts', fn (Blueprint $t) => $t->dropIndex(['asset_id', 'slot', 'end_date']));
            }
            Schema::table('rental_contracts', fn (Blueprint $t) => $t->dropColumn('slot'));
        }
    }

    public function down(): void
    {
        Schema::table('rental_contracts', fn (Blueprint $t) => $t->unsignedTinyInteger('slot')->default(1)->after('asset_id'));
        Schema::table('rental_contracts', fn (Blueprint $t) => $t->index(['asset_id', 'slot', 'end_date']));
        Schema::table('rental_contracts', fn (Blueprint $t) => $t->dropIndex(['asset_id', 'label', 'end_date']));
        Schema::table('rental_contracts', fn (Blueprint $t) => $t->dropColumn('label'));
    }
};
