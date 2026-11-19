<?php

namespace Tests\Qa;

use App\Filament\Resources\Employees\Pages\ManageEmployees;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeAccountService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\QaTestCase;

class Qa31_EmployeeLoginAccessTest extends QaTestCase
{
    private function svc(): EmployeeAccountService { return app(EmployeeAccountService::class); }

    private function create(array $d)
    {
        return Livewire::test(ManageEmployees::class)->callAction('create', $d + ['name' => 'Karyawan Uji', 'email' => 'uji@login.test', 'status' => 'active', 'nik' => '7770101']);
    }

    private function made(string $email = 'uji@login.test'): Employee { return Employee::where('email', $email)->firstOrFail(); }

    public function test_employee_without_login_has_no_account_by_default(): void
    {
        Mail::fake();
        $this->as($this->staff());
        $this->create([])->assertHasNoActionErrors();
        $e = $this->made();
        $this->assertNull($e->user_id);
        $this->assertNull(User::where('email', 'uji@login.test')->first());
        Mail::assertNothingSent();
    }

    public function test_staff_it_can_only_give_user_role_while_admin_can_give_others(): void
    {
        $this->as($this->staff());
        $this->assertSame(['User'], array_keys($this->svc()->assignableRoles(auth()->user())));
        $this->create(['can_login' => true, 'login_role' => 'Admin'])->assertHasActionErrors();       // peran tidak ada di pilihan
        $this->assertNull(Employee::where('email', 'uji@login.test')->first());

        $e = Employee::create(['nik' => '777001', 'name' => 'Via Layanan', 'email' => 'layanan@login.test', 'status' => 'active']);
        try { $this->svc()->apply($e, true, 'Admin', $this->staff()); $this->fail('harus ditolak'); }
        catch (\InvalidArgumentException $x) { $this->assertStringContainsString('Hanya Admin', $x->getMessage()); }
        $this->assertNull($e->fresh()->user_id);

        $this->as($this->admin());
        $this->assertContains('Staff IT', array_keys($this->svc()->assignableRoles(auth()->user())));
        $this->svc()->apply($e, true, 'Staff IT', $this->admin());
        $this->assertSame(['Staff IT'], $e->fresh()->user->roles->pluck('name')->all());
    }

    public function test_turning_login_off_deactivates_the_account_and_blocks_access_then_it_can_be_reactivated(): void
    {
        Mail::fake();
        $this->as($this->staff());
        $this->create(['can_login' => true, 'login_role' => 'User']);
        $e = $this->made(); $uid = $e->user_id;

        Livewire::test(ManageEmployees::class)->callAction(TestAction::make('edit')->table($e), ['name' => 'Karyawan Uji', 'email' => 'uji@login.test', 'status' => 'active', 'can_login' => false])->assertHasNoActionErrors();
        $u = User::findOrFail($uid);
        $this->assertFalse($u->is_active);
        $this->assertSame($uid, $e->fresh()->user_id);                                          // akun tidak dihapus
        $this->as($u)->get('/admin')->assertForbidden();                                         // akun nonaktif tidak bisa masuk

        $this->as($this->staff());
        Livewire::test(ManageEmployees::class)->callAction(TestAction::make('edit')->table($e->fresh()), ['name' => 'Karyawan Uji', 'email' => 'uji@login.test', 'status' => 'active', 'can_login' => true, 'login_role' => 'User'])->assertHasNoActionErrors();
        $this->assertTrue($u->fresh()->is_active);
        $this->assertSame(1, User::where('email', 'uji@login.test')->count());                  // tidak membuat akun ganda
        $this->as($u->fresh())->get('/admin')->assertRedirect(\App\Filament\Pages\ChangePassword::getUrl());     // akun baru: wajib ganti password dulu
    }

    public function test_resigned_employee_loses_login_automatically_and_cannot_be_given_login(): void
    {
        $this->as($this->staff());
        $e = Employee::create(['nik' => '777002', 'name' => 'Akan Resign', 'email' => 'resign@login.test', 'status' => 'active']);
        $this->svc()->apply($e, true, 'User', $this->staff());
        $u = $e->fresh()->user;
        $this->assertTrue($u->is_active);

        $e->update(['status' => 'resigned']);
        $this->assertFalse($u->fresh()->is_active);
        try { $this->svc()->apply($e->fresh(), true, 'User', $this->staff()); $this->fail('harus ditolak'); }
        catch (\InvalidArgumentException $x) { $this->assertStringContainsString('resigned', $x->getMessage()); }
    }

    public function test_staff_it_cannot_touch_privileged_accounts_but_can_still_edit_the_employee(): void
    {
        $this->as($this->staff());
        // karyawan yang tertaut ke akun Admin
        $adminUser = $this->admin();
        $e = Employee::create(['nik' => '777003', 'name' => 'Pemilik Akun Admin', 'email' => 'admin@inventaris.test', 'status' => 'active', 'user_id' => $adminUser->id]);
        try { $this->svc()->apply($e, false, 'User', $this->staff()); $this->fail('harus ditolak'); }
        catch (\InvalidArgumentException $x) { $this->assertStringContainsString('berperan khusus', $x->getMessage()); }
        $this->assertTrue($adminUser->fresh()->is_active);

        // mengubah data lain tanpa menyentuh login tidak terhalang
        Livewire::test(ManageEmployees::class)->callAction(TestAction::make('edit')->table($e->fresh()), ['name' => 'Nama Baru', 'email' => 'admin@inventaris.test', 'status' => 'active', 'can_login' => true, 'login_role' => 'Admin'])->assertHasActionErrors();   // Staff IT tidak punya pilihan Admin
        $this->assertTrue($adminUser->fresh()->is_active);

        // menautkan lewat email ke akun berperan khusus yang belum tertaut ditolak
        $special = User::create(['name' => 'Khusus', 'email' => 'khusus@login.test', 'password' => 'x-secret-123', 'is_active' => true]);
        $special->assignRole('Staff IT');
        $other = Employee::create(['nik' => '777004', 'name' => 'Email Staff', 'email' => 'khusus@login.test', 'status' => 'active']);
        try { $this->svc()->apply($other, true, 'User', $this->staff()); $this->fail('harus ditolak'); }
        catch (\InvalidArgumentException $x) { $this->assertStringContainsString('berperan khusus', $x->getMessage()); }
    }

    public function test_cannot_disable_your_own_account_and_existing_plain_account_is_linked_not_duplicated(): void
    {
        $staffUser = $this->staff();
        $this->as($this->admin());
        $e = Employee::create(['nik' => '777005', 'name' => 'Staff Sendiri', 'email' => $staffUser->email, 'status' => 'active', 'user_id' => $this->admin()->id]);
        try { $this->svc()->apply($e, false, 'User', $this->admin()); $this->fail('harus ditolak'); }
        catch (\InvalidArgumentException $x) { $this->assertStringContainsString('sendiri', $x->getMessage()); }

        $plain = User::create(['name' => 'Lama', 'email' => 'lama@login.test', 'password' => 'x-secret-123', 'is_active' => false]);
        $plain->assignRole('User');
        $n = Employee::create(['nik' => '777006', 'name' => 'Tautkan Lama', 'email' => 'lama@login.test', 'status' => 'active']);
        $this->svc()->apply($n, true, 'User', $this->admin());
        $this->assertSame($plain->id, $n->fresh()->user_id);
        $this->assertTrue($plain->fresh()->is_active);
        $this->assertSame(1, User::where('email', 'lama@login.test')->count());
    }

    /** Menandai resign lewat form Ubah pada karyawan yang punya akses login harus berhasil (akun otomatis nonaktif). */
    public function test_marking_an_employee_with_login_as_resigned_from_the_edit_form_works(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->as($this->staff());
        $this->create(['can_login' => true, 'login_role' => 'User']);
        $e = $this->made();
        $this->assertTrue($e->user->is_active);

        // form Ubah mengirim can_login=true (keadaan sebelumnya) bersama status baru resigned
        Livewire::test(ManageEmployees::class)->callAction(TestAction::make('edit')->table($e), ['name' => $e->name, 'email' => $e->email, 'status' => 'resigned', 'can_login' => true, 'login_role' => 'User'])
            ->assertHasNoActionErrors();
        $this->assertSame('resigned', $e->fresh()->status);
        $this->assertFalse($e->fresh()->user->is_active);

        // memberi akses login kepada karyawan yang sudah resign tetap ditolak
        $x = Employee::create(['nik' => '777009', 'name' => 'Sudah Resign QA', 'email' => 'sudah.resign@login.test', 'status' => 'resigned']);
        Livewire::test(ManageEmployees::class)->callAction(TestAction::make('edit')->table($x), ['name' => $x->name, 'email' => $x->email, 'status' => 'resigned', 'can_login' => true, 'login_role' => 'User'])->assertNotified('Akses login tidak disimpan');
        $this->assertNull($x->fresh()->user_id);
    }

    public function test_login_account_uses_nik_as_username_and_initial_password_and_sends_no_email(): void
    {
        Mail::fake();
        $this->as($this->staff());
        $this->create(['can_login' => true, 'login_role' => 'User'])->assertHasNoActionErrors()->assertNotified();
        $u = $this->made()->user;
        $this->assertNotNull($u);
        $this->assertTrue($u->is_active);
        $this->assertSame(['User'], $u->roles->pluck('name')->all());
        $this->assertTrue(Hash::check('7770101', $u->password));                  // password awal = NIK (tersimpan ter-hash)
        $this->assertNotSame('7770101', $u->getRawOriginal('password'));
        $this->assertTrue($u->must_change_password);                                   // wajib ganti saat login pertama
        $this->assertSame('7770101', $u->username);
        Mail::assertNothingSent();                                                     // tanpa email selamat datang
    }

    public function test_nik_is_required_long_enough_and_unique_for_login_accounts(): void
    {
        $this->as($this->staff());
        $this->create(['can_login' => true, 'login_role' => 'User', 'nik' => ''])->assertHasActionErrors(['nik' => 'required']);
        $this->create(['can_login' => true, 'login_role' => 'User', 'nik' => '123'])->assertHasActionErrors(['nik' => 'min']);
        $this->assertNull(Employee::where('email', 'uji@login.test')->first());
        $this->create(['can_login' => false, 'nik' => ''])->assertHasNoActionErrors();   // tanpa login, NIK boleh kosong
        $this->create(['can_login' => false, 'nik' => '7770109', 'email' => 'dua@login.test'])->assertHasNoActionErrors();
        $this->create(['can_login' => true, 'login_role' => 'User', 'nik' => '7770109', 'email' => 'tiga@login.test'])->assertHasActionErrors(['nik']);
    }

    public function test_reset_password_returns_it_to_nik_and_forces_change_and_ends_sessions(): void
    {
        $this->as($this->staff());
        $with = Employee::create(['nik' => '7770201', 'name' => 'Punya Akun', 'email' => 'punya@login.test', 'status' => 'active']);
        $this->svc()->apply($with, true, 'User', $this->staff());
        $without = Employee::create(['nik' => '7770202', 'name' => 'Tanpa Akun', 'email' => 'tanpa@login.test', 'status' => 'active']);
        $u = $with->fresh()->user;
        $u->forceFill(['password' => 'SudahDiganti123', 'must_change_password' => false])->save();

        $t = Livewire::test(ManageEmployees::class);
        $t->assertActionVisible(TestAction::make('reset_password')->table($with))->assertActionHidden(TestAction::make('reset_password')->table($without));
        $t->callAction(TestAction::make('reset_password')->table($with))->assertNotified('Password direset ke NIK');
        $this->assertTrue(Hash::check('7770201', $u->fresh()->password));
        $this->assertTrue($u->fresh()->must_change_password);

        $t->filterTable('login', 'yes')->searchTable('login.test')->assertCanSeeTableRecords([$with])->assertCanNotSeeTableRecords([$without]);
        $t->filterTable('login', 'no')->assertCanSeeTableRecords([$without])->assertCanNotSeeTableRecords([$with]);
        $t->assertTableColumnStateSet('login', 'Tidak', $without);
        // akun berperan khusus tidak bisa direset oleh Staff IT
        $special = Employee::create(['nik' => '7770203', 'name' => 'Pemilik Admin', 'email' => 'admin2@login.test', 'status' => 'active', 'user_id' => $this->admin()->id]);
        $this->expectExceptionMessage('berperan khusus');
        $this->svc()->resetPassword($special, $this->staff());
    }
}
