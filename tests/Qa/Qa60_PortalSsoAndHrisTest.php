<?php

namespace Tests\Qa;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\QaTestCase;

/** Fase 1: login lewat Portal AKPSatu (SSO), sinkron karyawan dari HRIS, dan API aset untuk HRIS. */
class Qa60_PortalSsoAndHrisTest extends QaTestCase
{
    private const SECRET = 'rahasia-uji-sso-0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        config(['sso.enabled' => true, 'sso.secret' => self::SECRET, 'sso.app_code' => 'asset', 'sso.portal_url' => 'http://portal.test']);
    }

    private function token(array $over = []): string
    {
        $now = time();
        return JWT::encode(array_merge([
            'iss' => 'http://portal.test', 'portal' => 'http://portal.test', 'aud' => 'asset', 'sub' => '77', 'iat' => $now, 'nbf' => $now, 'exp' => $now + 60,
            'jti' => (string) Str::uuid(), 'usr' => '3260001', 'emp' => '3260001', 'name' => 'Budi Uji', 'email' => 'budi.uji@akp.test',
            'role' => 'user', 'level' => 10, 'admin' => false,
        ], $over), self::SECRET, 'HS256', 'asset');
    }

    private function ssoCallback(array $over = [])
    {
        return $this->get('/sso/callback?token=' . urlencode($this->token($over)));
    }

    public function test_login_page_redirects_to_portal_when_sso_is_enabled(): void
    {
        $this->get('/admin/login')->assertRedirect('http://portal.test/login?app=asset');
    }

    public function test_local_login_form_is_only_open_with_flag_and_setting(): void
    {
        config(['sso.allow_local_login' => true]);
        $this->get('/admin/login?lokal=1')->assertOk();
        config(['sso.allow_local_login' => false]);
        $this->flushSession();
        $this->get('/admin/login?lokal=1')->assertRedirect('http://portal.test/login?app=asset');
    }

    public function test_sso_disabled_keeps_the_nik_login_form(): void
    {
        config(['sso.enabled' => false]);
        $this->get('/admin/login')->assertOk();
    }

    public function test_valid_token_logs_in_new_employee_as_plain_user_and_creates_records(): void
    {
        $this->ssoCallback()->assertRedirect('/admin');
        $user = auth()->user();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('User'));
        $this->assertSame('77', $user->portal_user_id);
        $emp = Employee::where('nik', '3260001')->first();
        $this->assertSame($user->id, $emp->user_id);
        $this->assertFalse((bool) $user->must_change_password);
    }

    public function test_portal_level_maps_to_local_role_and_super_admin_is_admin(): void
    {
        $this->ssoCallback(['emp' => '3260002', 'usr' => '3260002', 'sub' => '78', 'role' => 'staff', 'email' => 'staff.uji@akp.test']);
        $this->assertTrue(auth()->user()->hasRole('Staff IT'));
        $this->assertFalse(auth()->user()->hasRole('Admin'));

        auth()->forgetGuards(); $this->flushSession();
        $this->ssoCallback(['emp' => '3260003', 'usr' => '3260003', 'sub' => '79', 'role' => 'user', 'admin' => true, 'email' => 'adm.uji@akp.test']);
        $this->assertTrue(auth()->user()->hasRole('Admin'));
    }

    public function test_unknown_level_gets_only_user_role(): void
    {
        $this->ssoCallback(['emp' => '3260004', 'usr' => '3260004', 'sub' => '80', 'role' => 'dewa', 'email' => 'x.uji@akp.test']);
        $this->assertTrue(auth()->user()->hasRole('User'));
        $this->assertCount(1, auth()->user()->roles);
    }

    public function test_existing_local_account_is_matched_by_nik_and_role_is_synced_down(): void
    {
        $u = User::create(['name' => 'Lama', 'email' => 'lama@akp.test', 'password' => 'x', 'is_active' => true]);
        $u->assignRole('Admin');
        Employee::create(['nik' => '3260005', 'name' => 'Lama', 'email' => 'lama@akp.test', 'status' => 'active', 'user_id' => $u->id]);

        $this->ssoCallback(['emp' => '3260005', 'usr' => '3260005', 'sub' => '81', 'role' => 'user', 'email' => 'lama@akp.test']);

        $this->assertSame($u->id, auth()->id());
        $this->assertTrue(auth()->user()->hasRole('User'));
        $this->assertFalse(auth()->user()->hasRole('Admin'));   // Portal berwenang atas peran
        $this->assertSame(1, User::where('email', 'lama@akp.test')->count());
    }

    public function test_resigned_employee_and_deactivated_account_are_denied(): void
    {
        Employee::create(['nik' => '3260006', 'name' => 'Resign', 'email' => 'r@akp.test', 'status' => 'resigned']);
        $this->ssoCallback(['emp' => '3260006', 'usr' => '3260006', 'sub' => '82', 'email' => 'r@akp.test'])->assertForbidden();
        $this->assertGuest();

        $u = User::create(['name' => 'Off', 'email' => 'off@akp.test', 'password' => 'x', 'is_active' => false]);
        Employee::create(['nik' => '3260007', 'name' => 'Off', 'email' => 'off@akp.test', 'status' => 'active', 'user_id' => $u->id]);
        $this->ssoCallback(['emp' => '3260007', 'usr' => '3260007', 'sub' => '83', 'email' => 'off@akp.test'])->assertForbidden();
        $this->assertGuest();
    }

    public function test_bad_tokens_are_rejected(): void
    {
        $this->ssoCallback(['aud' => 'hris'])->assertForbidden();
        $this->ssoCallback(['exp' => time() - 600, 'nbf' => time() - 700, 'iat' => time() - 700])->assertForbidden();
        $this->get('/sso/callback?token=sampah')->assertForbidden();
        $this->get('/sso/callback')->assertForbidden();

        $t = $this->token(['emp' => '3260008', 'usr' => '3260008', 'sub' => '84']);
        $this->get('/sso/callback?token=' . urlencode($t))->assertRedirect();
        auth()->forgetGuards(); $this->flushSession();
        $this->get('/sso/callback?token=' . urlencode($t))->assertForbidden();   // jti dipakai ulang
        $this->assertGuest();
    }

    public function test_unauthenticated_admin_is_sent_to_portal_and_expired_portal_session_logs_out(): void
    {
        $this->get('/admin')->assertRedirect();   // ke login -> Portal
        $this->ssoCallback(['emp' => '3260009', 'usr' => '3260009', 'sub' => '85', 'role' => 'admin', 'email' => 'e@akp.test']);
        $this->assertAuthenticated();

        $this->withSession(['portal.started_at' => time() - 9 * 3600])->get('/requisition/requests/create')
            ->assertRedirect('http://portal.test/login?app=asset');
        $this->assertGuest();
    }

    public function test_logout_returns_to_portal_and_ends_local_session(): void
    {
        $this->ssoCallback(['emp' => '3260010', 'usr' => '3260010', 'sub' => '86', 'email' => 'f@akp.test']);
        $this->assertAuthenticated();
        $this->post('/sso/logout')->assertRedirect('http://portal.test');
        $this->assertGuest();
    }

    public function test_module_manifest_and_health_for_portal(): void
    {
        $this->getJson('/sso/manifest')->assertOk()->assertJsonPath('app', 'asset')->assertJsonStructure(['roles']);
        $r = $this->getJson('/sso/health?challenge=abc')->assertOk();
        $this->assertSame(hash_hmac('sha256', 'abc', self::SECRET), $r->json('proof'));
    }

    // ---- API untuk HRIS ----

    public function test_integration_api_requires_token(): void
    {
        config(['services.integration_token' => 'tok-123']);
        $this->getJson('/api/integrations/employees/123/assets')->assertUnauthorized();
        $this->withToken('salah')->getJson('/api/integrations/employees/123/assets')->assertUnauthorized();
        config(['services.integration_token' => null]);
        $this->withToken('tok-123')->getJson('/api/integrations/employees/123/assets')->assertUnauthorized();   // kosong = mati
    }

    public function test_integration_api_lists_held_assets_in_sima_format(): void
    {
        config(['services.integration_token' => 'tok-123']);
        $asset = Asset::where('status', 'deployed')->whereNotNull('employee_id')->firstOrFail();
        $nik = $asset->employee->nik;

        $r = $this->withToken('tok-123')->getJson("/api/integrations/employees/{$nik}/assets")->assertOk()
            ->assertJsonStructure(['employee_no', 'found', 'count', 'assets' => [['asset_tag', 'name', 'model', 'category', 'assigned_at', 'url']]]);
        $this->assertTrue($r->json('found'));
        $this->assertContains($asset->asset_tag, collect($r->json('assets'))->pluck('asset_tag')->all());

        $this->withToken('tok-123')->getJson('/api/integrations/employees/9999999/assets')->assertOk()
            ->assertJson(['found' => false, 'count' => 0, 'assets' => []]);
    }

    // ---- sinkron HRIS ----

    private function fakeHris(array $rows): void
    {
        config(['services.hris.url' => 'http://hris.test', 'services.hris.token' => 'ht']);
        Http::fake(['hris.test/*' => Http::response(['data' => $rows, 'meta' => ['last_page' => 1]])]);
        \Illuminate\Support\Facades\Cache::flush();
    }

    private function h(string $nik, string $name, array $over = []): array
    {
        return array_merge(['id' => (int) $nik, 'employee_no' => $nik, 'nik_aliases' => [], 'name' => $name, 'email' => "n{$nik}@hris.test",
            'department' => 'IT', 'position' => 'Staff', 'phone' => '0812', 'employment_status' => 'PKWT', 'is_active' => true], $over);
    }

    public function test_hris_sync_dry_run_changes_nothing_and_apply_creates_updates_and_resigns(): void
    {
        $existing = Employee::create(['nik' => '3270001', 'name' => 'Nama Lama', 'email' => 'old@akp.test', 'status' => 'active']);
        $u = User::create(['name' => 'Nama Lama', 'email' => 'old@akp.test', 'password' => 'x', 'is_active' => true]);
        $existing->forceFill(['user_id' => $u->id])->saveQuietly();
        $this->fakeHris([$this->h('3270001', 'Nama Baru', ['is_active' => false]), $this->h('3270002', 'Orang Baru')]);

        $this->artisan('hris:sync-employees')->assertSuccessful();
        $this->assertSame('Nama Lama', $existing->fresh()->name);
        $this->assertNull(Employee::where('nik', '3270002')->first());

        $this->artisan('hris:sync-employees --apply')->assertSuccessful();
        $this->assertSame('Nama Baru', $existing->fresh()->name);
        $this->assertSame('resigned', $existing->fresh()->status);
        $this->assertFalse((bool) $u->fresh()->is_active);          // resign -> akun nonaktif (hook model)
        $new = Employee::where('nik', '3270002')->first();
        $this->assertSame('active', $new->status);
        $this->assertSame('IT', $new->department?->name);
    }

    public function test_hris_sync_matches_by_alias_and_never_deletes_local_only_employees(): void
    {
        $local = Employee::create(['nik' => '3270010', 'name' => 'Cuma Lokal', 'email' => 'lokal@akp.test', 'status' => 'active']);
        $old = Employee::create(['nik' => '1000', 'name' => 'NIK Lama', 'email' => 'lama2@akp.test', 'status' => 'active']);
        $this->fakeHris([$this->h('3270011', 'NIK Lama', ['nik_aliases' => ['1000']])]);

        $this->artisan('hris:sync-employees --apply')->assertSuccessful();

        $this->assertSame('3270011', $old->fresh()->nik);            // alias ditemukan -> NIK utama diperbarui, bukan duplikat
        $this->assertSame(1, Employee::where('name', 'NIK Lama')->count());
        $this->assertNotNull($local->fresh());
        $this->assertSame('active', $local->fresh()->status);        // tidak ada di HRIS: dilaporkan saja
    }

    public function test_hris_failure_aborts_without_touching_data(): void
    {
        config(['services.hris.url' => 'http://hris.test', 'services.hris.token' => 'ht']);
        Http::fake(['hris.test/*' => Http::response('boom', 500)]);
        \Illuminate\Support\Facades\Cache::flush();
        $before = Employee::count();

        $this->artisan('hris:sync-employees --apply')->assertFailed();
        $this->assertSame($before, Employee::count());
    }

    public function test_login_creates_employee_from_hris_with_department(): void
    {
        $this->fakeHris([$this->h('3260020', 'Dari HRIS', ['department' => 'Finance & Accounting'])]);
        $this->ssoCallback(['emp' => '3260020', 'usr' => '3260020', 'sub' => '90', 'name' => 'Dari HRIS', 'email' => 'dh@akp.test']);
        $this->assertSame('Finance & Accounting', Employee::where('nik', '3260020')->first()->department?->name);
    }
}
