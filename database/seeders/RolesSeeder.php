<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Peran & izin saja (tanpa akun/data demo). Idempoten: aman dijalankan di setiap start produksi. */
class RolesSeeder extends Seeder
{
    public const PERMISSIONS = ['manage master', 'manage inventory', 'view inventory', 'view reports', 'delete data', 'approve disposal', 'self service'];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::PERMISSIONS as $p) Permission::findOrCreate($p);

        Role::findOrCreate('Admin')->syncPermissions(self::PERMISSIONS);
        Role::findOrCreate('Staff IT')->syncPermissions(['manage inventory', 'view inventory', 'view reports', 'self service']);
        Role::findOrCreate('User')->syncPermissions(['self service']);
    }
}
