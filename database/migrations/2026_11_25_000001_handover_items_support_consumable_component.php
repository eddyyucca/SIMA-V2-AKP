<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BAST barang pendukung kini memuat accessories, consumable, dan komponen: baris barang memakai relasi polimorfik
 * (stockable_type / stockable_id). Data lama dipertahankan: accessory_id tetap terisi dan baris lama diisi ulang ke kolom baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accessory_handover_items', function (Blueprint $t) {
            $t->string('stockable_type')->nullable()->after('accessory_handover_id');
            $t->unsignedBigInteger('stockable_id')->nullable()->after('stockable_type');
            $t->index(['stockable_type', 'stockable_id']);
        });
        Schema::table('accessory_handover_items', function (Blueprint $t) {
            $t->unsignedBigInteger('accessory_id')->nullable()->change();
        });
        DB::table('accessory_handover_items')->whereNull('stockable_type')->whereNotNull('accessory_id')
            ->update(['stockable_type' => 'App\\Models\\Accessory', 'stockable_id' => DB::raw('accessory_id')]);
    }

    public function down(): void
    {
        Schema::table('accessory_handover_items', function (Blueprint $t) {
            $t->dropIndex(['stockable_type', 'stockable_id']);
            $t->dropColumn(['stockable_type', 'stockable_id']);
        });
    }
};
