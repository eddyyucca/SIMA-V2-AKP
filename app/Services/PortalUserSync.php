<?php

namespace App\Services;

use Akp\PortalSso\Exceptions\LoginDenied;
use Akp\PortalSso\PortalUser;
use App\Models\Employee;
use App\Models\User;
use App\Services\Hris\EmployeeDirectory;
use App\Services\Hris\EmployeeSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Pengguna Portal -> akun lokal. Portal berwenang atas identitas dan peran; aplikasi ini atas datanya.
 * Kunci pencocokan: NIK (employees.nik, termasuk NIK alias HRIS), lalu portal_user_id, lalu email. Selalu fail-closed:
 * akun / karyawan nonaktif ditolak, level tak dikenal hanya mendapat peran User.
 */
class PortalUserSync
{
    public function __construct(private EmployeeDirectory $hris, private EmployeeSync $sync) {}

    /** @throws LoginDenied */
    public function resolve(PortalUser $p): User
    {
        return DB::transaction(function () use ($p) {
            $employee = $this->employeeFor($p);
            if ($employee && ($employee->status !== 'active' || $employee->trashed())) {
                throw new LoginDenied('Karyawan sudah tidak aktif. Hubungi Tim IT bila ini keliru.');
            }

            $user = $employee?->user
                ?? User::where('portal_user_id', $p->id)->first()
                ?? ($p->email ? User::where('email', $p->email)->first() : null);

            if ($user && ! $user->is_active) throw new LoginDenied('Akun dinonaktifkan di aplikasi ini. Hubungi Tim IT.');

            if (! $user) {
                $user = User::create([
                    'name' => $p->name, 'email' => $p->email ?: $this->placeholderEmail($p),
                    'password' => Str::random(40), 'is_active' => true, 'must_change_password' => false,
                ]);
            }
            $user->forceFill(['portal_user_id' => $p->id, 'must_change_password' => false])->save();
            if ($employee && ! $employee->user_id) $employee->forceFill(['user_id' => $user->id])->saveQuietly();

            $user->syncRoles([$this->roleFor($p)]);

            return $user;
        });
    }

    private function employeeFor(PortalUser $p): ?Employee
    {
        $nik = trim((string) $p->employee_no);
        if ($nik === '') return null;

        if ($e = Employee::withTrashed()->where('nik', $nik)->first()) return $e;

        // belum ada di aplikasi ini: ambil dari HRIS (termasuk NIK alias); HRIS mati -> buat dari data token
        if ($h = $this->hris->find($nik)) return $this->sync->upsert($h)['employee'];

        return Employee::create([
            'nik' => $nik, 'name' => $p->name, 'status' => 'active', 'hris_missing_at' => \App\Support\Hris::managed() ? now() : null,
            'email' => $p->email && ! Employee::withTrashed()->where('email', $p->email)->exists() ? $p->email : 'nik' . Str::slug($nik) . '@akp.test',
        ]);
    }

    private function roleFor(PortalUser $p): string
    {
        $name = $p->is_admin ? 'Admin' : (config('sso.role_map')[$p->role] ?? 'User');
        return Role::where('name', $name)->exists() ? $name : 'User';
    }

    private function placeholderEmail(PortalUser $p): string
    {
        return Str::slug($p->username ?: 'portal-' . $p->id) . '@portal.akp.test';
    }
}
