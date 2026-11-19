<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Penghubung ke Portal AKPSatu (akun) dan HRIS (karyawan). Kolom lama tidak berubah. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('portal_user_id', 64)->nullable()->unique()->after('email');
        });
        Schema::table('employees', function (Blueprint $t) {
            $t->unsignedBigInteger('hris_id')->nullable()->after('nik');
            $t->timestamp('hris_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn(['hris_id', 'hris_synced_at']));
        Schema::table('users', function (Blueprint $t) {
            $t->dropUnique(['portal_user_id']);
            $t->dropColumn('portal_user_id');
        });
    }
};
