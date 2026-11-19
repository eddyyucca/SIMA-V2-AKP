<?php

namespace Tests;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Dasar tes System Requisition (digabung ke IT Asset): memakai database tes & data demo IT Asset (lihat UsesDemoData),
 * dan membuat pengguna lewat peran IT Asset. Peran SR diturunkan dari peran IT Asset:
 * super_admin = Admin, admin = Staff IT, user = User.
 */
abstract class RequisitionTestCase extends TestCase
{
    use UsesDemoData;

    protected function setUp(): void
    {
        parent::setUp();
        auth()->forgetGuards();      // status login dari tes sebelumnya tidak boleh menempel
    }

    /** Ganti pengguna di tengah tes: sesi & guard dibersihkan supaya AuthenticateSession tidak mengeluarkan akun baru. */
    protected function loginAs(User $user): static
    {
        auth()->forgetGuards();
        $this->flushSession();
        return $this->actingAs($user);
    }

    private const ROLE = ['super_admin' => 'Admin', 'admin' => 'Staff IT', 'user' => 'User'];

    /** Buat akun aktif dengan peran SR tertentu. 'username' diterjemahkan jadi email "<username>@requisition.test". */
    protected function makeUser(string $srRole = 'user', array $attrs = []): User
    {
        $email = isset($attrs['username']) ? $attrs['username'] . '@requisition.test' : ($attrs['email'] ?? 'u' . Str::lower(Str::random(10)) . '@requisition.test');
        $u = User::create([
            'name' => $attrs['name'] ?? 'Pengguna ' . Str::random(5),
            'email' => $email,
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $u->assignRole(self::ROLE[$srRole]);
        return $u;
    }

    private ?\App\Models\Employee $defaultRequester = null;

    /** Karyawan di database yang dipilih pemohon pada formulir request (nama dipilih dari daftar, data diambil dari database). */
    protected function makeRequester(array $over = []): \App\Models\Employee
    {
        return \App\Models\Employee::create($over + [
            'name' => 'Budi Santoso', 'email' => 'req' . Str::lower(Str::random(8)) . '@requisition.test', 'nik' => (string) random_int(1000000, 9999999), 'position' => 'Staff IT',
            'department_id' => \App\Models\Department::where('name', 'Finance')->value('id') ?? \App\Models\Department::value('id'), 'status' => 'active',
        ]);
    }

    protected function requesterId(): int
    {
        return ($this->defaultRequester ??= $this->makeRequester(['nik' => '123456']))->id;
    }

    // Akun demo IT Asset (nama berawalan "it" supaya tidak bentrok dengan fungsi bantu di tes SR yang dipindahkan)
    protected function itAdmin(): User { return User::where('email', 'admin@inventaris.test')->firstOrFail(); }
    protected function itStaff(): User { return User::where('email', 'staff@inventaris.test')->firstOrFail(); }
    /** Pengguna dengan izin "hanya lihat" (tanpa peran; peran Manajemen sudah dihapus dari aplikasi). */
    protected function itMgmt(): User
    {
        $u = User::firstOrCreate(['email' => 'viewer@inventaris.test'], ['name' => 'Pengguna Izin Lihat', 'password' => 'Inventaris#2026', 'is_active' => true]);
        $u->syncRoles([]);
        $u->syncPermissions(['view inventory', 'view reports', 'approve disposal', 'self service']);
        return $u;
    }
    protected function itPlain(): User { return User::where('email', 'user@inventaris.test')->firstOrFail(); }
}
