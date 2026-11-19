<?php

namespace Tests;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\User;

abstract class QaTestCase extends TestCase
{
    use UsesDemoData;

    protected function admin(): User { return User::where('email', 'admin@inventaris.test')->firstOrFail(); }
    protected function staff(): User { return User::where('email', 'staff@inventaris.test')->firstOrFail(); }
    /**
     * Peran Manajemen sudah dihapus dari aplikasi (disamakan dengan karyawan biasa). Pengguna uji ini hanya mewakili KONFIGURASI IZIN "hanya lihat"
     * (izin langsung tanpa peran), supaya aturan izin lihat-saja pada kebijakan dan halaman tetap teruji.
     */
    protected function mgmt(): User
    {
        $u = User::firstOrCreate(['email' => 'viewer@inventaris.test'], ['name' => 'Pengguna Izin Lihat', 'password' => 'Inventaris#2026', 'is_active' => true]);
        $u->syncRoles([]);
        $u->syncPermissions(['view inventory', 'view reports', 'approve disposal', 'self service']);
        return $u;
    }
    protected function plain(): User { return User::where('email', 'user@inventaris.test')->firstOrFail(); }

    protected function as(User $u): static
    {
        auth()->forgetGuards();
        $this->flushSession();
        $this->actingAs($u);
        return $this;
    }

    protected function emp(string $name): Employee { return Employee::where('name', $name)->firstOrFail(); }

    /** Aset Tersedia, tanpa pemegang, tanpa tiket/disposal terbuka. */
    protected function freeAsset(): Asset
    {
        return Asset::where('status', 'available')->whereNull('employee_id')
            ->whereDoesntHave('repairs', fn ($q) => $q->where('status', '!=', 'done'))
            ->whereDoesntHave('disposals', fn ($q) => $q->where('status', 'pending'))
            ->whereDoesntHave('reservationItem')
            ->orderBy('id')->firstOrFail();
    }

    protected function held(): Asset
    {
        return Asset::where('status', 'deployed')->whereNotNull('employee_id')
            ->whereDoesntHave('repairs', fn ($q) => $q->where('status', '!=', 'done'))->orderBy('id')->firstOrFail();
    }
}
