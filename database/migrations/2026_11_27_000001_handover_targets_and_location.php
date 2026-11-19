<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stok keluar accessories / consumable / komponen bisa ditujukan ke karyawan, department, lokasi, atau perangkat dan selalu punya
 * dokumen BAST. Hanya menambah kolom (penerima non-karyawan, lokasi pada riwayat stok); employee_id BAST boleh kosong untuk
 * tujuan non-karyawan, dan jumlah baris BAST boleh desimal (consumable seperti meter / rim). Data lama tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accessory_handovers', function (Blueprint $t) {
            $t->string('target_type', 12)->default('employee')->after('type');            // employee | department | location | asset
            $t->foreignId('department_id')->nullable()->after('employee_id')->constrained('departments')->nullOnDelete();
            $t->foreignId('location_id')->nullable()->after('department_id')->constrained('locations')->nullOnDelete();
            $t->foreignId('asset_id')->nullable()->after('location_id')->constrained('assets')->nullOnDelete();
            $t->foreignId('pic_id')->nullable()->after('asset_id')->constrained('employees')->nullOnDelete();   // PIC penerima untuk tujuan non-karyawan
        });
        Schema::table('accessory_handovers', function (Blueprint $t) {
            $t->unsignedBigInteger('employee_id')->nullable()->change();
        });
        Schema::table('accessory_handover_items', function (Blueprint $t) {
            $t->decimal('qty', 12, 2)->unsigned()->change();
        });
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->foreignId('location_id')->nullable()->after('department_id')->constrained('locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('location_id');
        });
        Schema::table('accessory_handovers', function (Blueprint $t) {
            foreach (['pic_id', 'asset_id', 'location_id', 'department_id'] as $c) $t->dropConstrainedForeignId($c);
            $t->dropColumn('target_type');
        });
    }
};
