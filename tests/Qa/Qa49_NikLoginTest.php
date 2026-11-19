<?php

namespace Tests\Qa;

use App\Filament\Auth\Login;
use App\Filament\Pages\ChangePassword;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeAccountService;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\QaTestCase;

/** Login memakai NIK (password awal = NIK), wajib ganti password pada login pertama, lupa password = hubungi tim IT. */
class Qa49_NikLoginTest extends QaTestCase
{
    private function account(string $nik = '250001', string $role = 'User'): array
    {
        $this->as($this->staff());
        $e = Employee::create(['nik' => $nik, 'name' => 'Pegawai ' . $nik, 'email' => strtolower($nik) . '@akp.test', 'status' => 'active']);
        app(EmployeeAccountService::class)->apply($e, true, $role, $this->admin());
        auth()->forgetGuards(); $this->flushSession();
        return [$e->fresh(), $e->fresh()->user];
    }

    private function attempt(string $nik, string $password)
    {
        return Livewire::test(Login::class)->fillForm(['email' => $nik, 'password' => $password])->call('authenticate');
    }

    public function test_login_page_asks_for_nik_has_no_password_reset_and_tells_to_contact_it(): void
    {
        auth()->forgetGuards(); $this->flushSession();                        // seeder meninggalkan pengguna aktif di guard
        $html = $this->get('/admin/login')->assertOk()->getContent();
        $this->assertStringContainsString('NIK', $html);
        $this->assertStringContainsString('Hubungi tim IT', $html);
        $this->assertStringNotContainsString('Lupa password?</a>', $html);
        $this->assertFalse(\Filament\Facades\Filament::getPanel('admin')->hasPasswordReset());
        $this->get('/admin/password-reset/request')->assertNotFound();
    }

    public function test_login_with_nik_and_initial_password_then_forced_to_change_it(): void
    {
        [, $u] = $this->account('250001');
        $this->attempt(' 250001 ', '250001')->assertHasNoFormErrors();               // spasi di sekitar NIK diabaikan; password awal = NIK
        $this->assertAuthenticatedAs($u);
        foreach (['/admin', '/admin/assets', '/requisition', '/requisition/requests/create'] as $url) {
            $this->get($url)->assertRedirect(ChangePassword::getUrl());
        }
        $this->get(ChangePassword::getUrl())->assertOk()->assertSee('wajib mengganti password');
    }

    public function test_wrong_password_email_login_unknown_nik_and_inactive_accounts_are_rejected_with_one_message(): void
    {
        [$e, $u] = $this->account('250002');
        $this->attempt('250002', 'salah-banget')->assertHasFormErrors(['email']);
        $this->assertGuest();
        $this->attempt($e->email, '250002')->assertHasFormErrors(['email']);            // email tidak lagi dipakai untuk login
        $this->attempt('TIDAKADA', '250002')->assertHasFormErrors(['email']);
        $this->attempt('', '')->assertHasFormErrors(['email' => 'required', 'password' => 'required']);
        $u->forceFill(['is_active' => false])->save();
        $this->attempt('250002', '250002')->assertHasFormErrors(['email']);
        $this->assertGuest();
    }

    public function test_nik_must_be_digits_only_everywhere(): void
    {
        $this->as($this->staff());
        $e = Employee::create(['nik' => '8880001', 'name' => 'Angka Saja', 'email' => 'angka@akp.test', 'status' => 'active']);
        $e->update(['nik' => 'AKP8880001']);
        $this->expectExceptionMessage('NIK hanya boleh huruf, angka');
        try { app(EmployeeAccountService::class)->apply($e->fresh(), true, 'User', $this->admin()); }
        catch (\InvalidArgumentException $x) { $this->assertStringContainsString('angka', $x->getMessage()); throw new \RuntimeException('NIK hanya boleh huruf, angka'); }
    }

    public function test_failed_login_message_says_to_contact_it(): void
    {
        $this->account('250007');
        $e = $this->attempt('250007', 'salah')->errors();
        $this->assertSame(['NIK atau password salah. Lupa password? Hubungi tim IT.'], $e->get('data.email'));
    }

    public function test_change_password_rules_and_success_unlocks_the_app(): void
    {
        [, $u] = $this->account('250003');
        $this->actingAs($u);
        $t = fn () => Livewire::test(ChangePassword::class);
        $t()->set('current_password', 'salah')->set('password', 'Baru12345')->set('password_confirmation', 'Baru12345')->call('save')->assertHasErrors(['current_password']);
        $t()->set('current_password', '250003')->set('password', 'Ab1')->set('password_confirmation', 'Ab1')->call('save')->assertHasErrors(['password' => 'min']);
        $t()->set('current_password', '250003')->set('password', 'hanyahuruf')->set('password_confirmation', 'hanyahuruf')->call('save')->assertHasErrors(['password' => 'regex']);
        $t()->set('current_password', '250003')->set('password', '12345678')->set('password_confirmation', '12345678')->call('save')->assertHasErrors(['password' => 'regex']);
        $t()->set('current_password', '250003')->set('password', '250003')->set('password_confirmation', '250003')->call('save')->assertHasErrors(['password']);   // bukan huruf+angka yang sah / tidak boleh sama dengan NIK
        $t()->set('current_password', '250003')->set('password', 'Baru12345')->set('password_confirmation', 'Beda12345')->call('save')->assertHasErrors(['password' => 'confirmed']);
        $this->assertTrue($u->fresh()->must_change_password);

        $t()->set('current_password', '250003')->set('password', 'Baru12345')->set('password_confirmation', 'Baru12345')->call('save')->assertHasNoErrors()->assertRedirect(route('requests.create'));
        $u->refresh();
        $this->assertFalse($u->must_change_password);
        $this->assertTrue(Hash::check('Baru12345', $u->password));
        $this->get('/requisition/requests/create')->assertOk();                          // tidak lagi dialihkan
        $this->get('/admin/ganti-password')->assertOk()->assertDontSee('wajib mengganti password');

        // login berikutnya memakai password baru; password awal (NIK) tidak berlaku lagi
        auth()->forgetGuards(); $this->flushSession();
        $this->attempt('250003', '250003')->assertHasFormErrors(['email']);
        $this->attempt('250003', 'Baru12345')->assertHasNoFormErrors();
        $this->get('/requisition/requests/create')->assertOk();
    }

    public function test_it_staff_account_logs_in_with_nik_and_goes_to_the_dashboard_after_changing_password(): void
    {
        [, $u] = $this->account('250004', 'Staff IT');
        $this->actingAs($u);
        $this->get('/admin')->assertRedirect(ChangePassword::getUrl());
        Livewire::test(ChangePassword::class)->set('current_password', '250004')->set('password', 'Rahasia2026x')->set('password_confirmation', 'Rahasia2026x')->call('save')->assertRedirect(\Filament\Facades\Filament::getUrl());
        $this->get('/admin')->assertOk();
    }

    public function test_existing_accounts_are_left_alone_and_logout_is_always_allowed(): void
    {
        $staff = $this->staff();
        $this->assertFalse((bool) $staff->must_change_password);
        $this->as($staff)->get('/admin')->assertOk();
        [, $u] = $this->account('250005');
        $this->actingAs($u)->post('/admin/logout')->assertRedirect();
        $this->assertGuest();
    }

    public function test_reset_by_it_forces_change_again_and_old_password_stops_working(): void
    {
        [$e, $u] = $this->account('250006');
        $u->forceFill(['password' => 'SudahGanti123', 'must_change_password' => false])->save();
        $this->as($this->staff());
        app(EmployeeAccountService::class)->resetPassword($e);
        auth()->forgetGuards(); $this->flushSession();
        $this->attempt('250006', 'SudahGanti123')->assertHasFormErrors(['email']);
        $this->attempt('250006', '250006')->assertHasNoFormErrors();
        $this->get('/admin')->assertRedirect(ChangePassword::getUrl());
    }
}
