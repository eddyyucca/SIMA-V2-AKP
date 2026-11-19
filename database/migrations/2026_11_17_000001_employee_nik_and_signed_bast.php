<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** NIK karyawan + file PDF BAST yang sudah ditandatangani (hasil scan) yang diunggah manual. Hanya menambah kolom. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            $t->string('nik', 50)->nullable()->unique()->after('id');
        });
        Schema::table('arrival_plans', function (Blueprint $t) {
            $t->string('nik', 50)->nullable()->after('plan_no');
        });
        Schema::table('asset_assignments', function (Blueprint $t) {
            $t->string('bast_signed_path')->nullable()->after('bast_pdf_path');
            $t->dateTime('bast_signed_at')->nullable()->after('bast_signed_path');
            $t->foreignId('bast_signed_by')->nullable()->after('bast_signed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('asset_assignments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('bast_signed_by');
            $t->dropColumn(['bast_signed_path', 'bast_signed_at']);
        });
        Schema::table('arrival_plans', fn (Blueprint $t) => $t->dropColumn('nik'));
        Schema::table('employees', function (Blueprint $t) {
            $t->dropUnique(['nik']);
            $t->dropColumn('nik');
        });
    }
};
