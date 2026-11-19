<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Login memakai NIK: kolom penanda "wajib ganti password" (password awal = NIK).
 * Peran Manajemen dihapus: akun yang memakainya dipindah ke peran User (karyawan biasa).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('must_change_password')->default(false)->after('is_active');
        });

        $role = DB::table('roles')->where('name', 'Manajemen')->first();
        if ($role) {
            $user = DB::table('roles')->where('name', 'User')->first();
            if ($user) {
                $ids = DB::table('model_has_roles')->where('role_id', $role->id)->pluck('model_id');
                foreach ($ids as $id) {
                    DB::table('model_has_roles')->insertOrIgnore(['role_id' => $user->id, 'model_type' => 'App\\Models\\User', 'model_id' => $id]);
                }
            }
            DB::table('model_has_roles')->where('role_id', $role->id)->delete();
            DB::table('role_has_permissions')->where('role_id', $role->id)->delete();
            DB::table('roles')->where('id', $role->id)->delete();
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('must_change_password'));
    }
};
