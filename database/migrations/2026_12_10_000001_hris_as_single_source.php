<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRIS = satu-satunya sumber data karyawan, department, dan section. Kolom tambahan diisi hanya oleh sinkronisasi HRIS.
 * hris_missing_at: karyawan lokal yang tidak ditemukan di HRIS (sisa impor SIMA); dilaporkan, tidak pernah dihapus.
 * departments.hris_synced_at: department yang berasal dari HRIS; yang lain (sisa manual/SIMA) disembunyikan dari pilihan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            $t->string('business_unit', 150)->nullable()->after('department_id');   // 'division' di HRIS
            $t->string('section', 150)->nullable()->after('division');
            $t->string('employment_status', 100)->nullable();
            $t->string('supervisor_nik', 50)->nullable();
            $t->timestamp('hris_missing_at')->nullable();
        });
        Schema::table('departments', function (Blueprint $t) {
            $t->timestamp('hris_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('departments', fn (Blueprint $t) => $t->dropColumn('hris_synced_at'));
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn(['business_unit', 'section', 'employment_status', 'supervisor_nik', 'hris_missing_at']));
    }
};
