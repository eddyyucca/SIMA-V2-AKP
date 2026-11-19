<?php

namespace Tests\Requisition;

use App\Filament\Pages\MyItems;
use App\Http\Responses\LoginResponse;
use App\Models\Accessory;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Employee;
use App\Models\ITRequest;
use App\Models\License;
use App\Models\LicenseSeat;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Tests\RequisitionTestCase;

/** Hal-hal khusus penggabungan System Requisition ke IT Asset: login tunggal, peran, tombol topbar, kartu aset yang dipakai. */
class MergeIntegrationTest extends RequisitionTestCase
{
    /** Karyawan yang tertaut ke akun, dengan satu aset dipegang. */
    private function employeeWithAsset(User $user, string $status = 'deployed'): array
    {
        $emp = Employee::create(['name' => 'Budi Pemakai', 'email' => $user->email, 'nik' => 'REQ-' . $user->id, 'status' => 'active', 'user_id' => $user->id]);
        $asset = Asset::where('status', 'available')->whereNull('employee_id')->orderBy('id')->firstOrFail();
        $asset->update(['employee_id' => $emp->id, 'status' => $status]);
        return [$emp, $asset->fresh()];
    }

    public function test_guest_is_sent_to_the_it_asset_login_not_a_separate_requisition_login(): void
    {
        $this->get('/requisition')->assertRedirect(url('/admin/login'));
        $this->get('/requisition/requests/create')->assertRedirect(url('/admin/login'));
        $this->get('/login')->assertRedirect(route('filament.admin.auth.login'));
        // halaman login/daftar/lupa-password milik System Requisition tidak ada lagi
        foreach (['/register', '/forgot-password', '/requisition/login', '/requisition/register', '/requisition/profile', '/requisition/settings/users'] as $gone) {
            $this->get($gone)->assertNotFound();
        }
    }

    public function test_after_login_regular_users_go_to_the_card_page_and_it_staff_to_the_it_asset_dashboard(): void
    {
        $login = fn (User $u) => (new LoginResponse)->toResponse(tap(Request::create('/admin/login'), fn ($r) => $r->setUserResolver(fn () => $u)))->getTargetUrl();

        $this->assertSame(route('requests.create'), $login($this->makeUser('user')));
        $this->assertSame(url('/admin'), $login($this->makeUser('admin')));
        $this->assertSame(url('/admin'), $login($this->makeUser('super_admin')));
        $this->assertSame(url('/admin'), $login($this->itMgmt()));                                 // Manajemen punya akses lihat IT Asset

        // membuka /admin sebagai karyawan biasa juga langsung ke kartu
        $this->loginAs($this->makeUser('user'))->get('/admin')->assertRedirect(route('requests.create'));
        $this->loginAs($this->makeUser('admin'))->get('/admin')->assertOk();
    }

    public function test_system_requisition_button_is_in_the_it_asset_topbar_for_everyone_who_sees_it(): void
    {
        // tim IT -> dashboard approval SR; Manajemen (peran user di SR) -> halaman kartu request
        foreach ([[$this->makeUser('admin'), route('dashboard')], [$this->makeUser('super_admin'), route('dashboard')], [$this->itMgmt(), route('requests.create')]] as [$u, $target]) {
            $html = $this->loginAs($u)->get('/admin/assets')->assertOk()->getContent();
            $this->assertStringContainsString('ITERA', $html);
            $this->assertStringContainsString('href="' . $target . '"', $html);
        }
    }

    public function test_requisition_roles_are_derived_from_it_asset_roles(): void
    {
        $this->assertSame('super_admin', $this->makeUser('super_admin')->role);
        $this->assertSame('admin', $this->makeUser('admin')->role);
        $this->assertSame('user', $this->makeUser('user')->role);
        $this->assertSame('user', $this->itMgmt()->role);                                          // Manajemen = user di System Requisition
        $this->assertTrue($this->makeUser('admin')->canManageRequests());
        $this->assertFalse($this->makeUser('user')->canManageRequests());
        $this->assertTrue($this->makeUser('super_admin')->isSuperAdmin());
        $this->assertFalse($this->makeUser('admin')->isSuperAdmin());
        $this->assertSame('Super Admin', $this->makeUser('super_admin')->roleLabel());

        // penerima notifikasi request baru = akun AKTIF berperan Admin / Staff IT saja
        $inactive = $this->makeUser('admin'); $inactive->forceFill(['is_active' => false])->save();
        $ids = User::requisitionAdmins()->pluck('id');
        $this->assertTrue($ids->contains($this->itStaff()->id) && $ids->contains($this->itAdmin()->id));
        $this->assertFalse($ids->contains($inactive->id));
        $this->assertFalse($ids->contains($this->itPlain()->id));
        $this->assertFalse($ids->contains($this->itMgmt()->id));
        $this->assertSame($this->itPlain()->employee->nik, $this->itPlain()->username);              // username = NIK karyawan
    }

    public function test_deactivated_it_asset_account_cannot_use_system_requisition(): void
    {
        $u = $this->makeUser('user');
        $this->loginAs($u)->get('/requisition/requests/create')->assertOk();
        $u->forceFill(['is_active' => false])->save();
        $this->loginAs($u->fresh())->get('/requisition/requests/create')->assertForbidden();
    }

    public function test_request_page_has_no_database_data_for_the_requester(): void
    {
        // pemohon mengisi manual: tidak ada daftar aset/barang dari database dan tidak ada kartu "Aset yang Anda Pakai"
        $user = $this->makeUser('user');
        [$emp, $asset] = $this->employeeWithAsset($user);
        $page = $this->loginAs($user)->get('/requisition/requests/create')->assertOk()->getContent();
        $this->assertStringNotContainsString('myAssetsSection', $page);
        $this->assertStringNotContainsString('Aset yang Anda Pakai', $page);
        $this->assertStringNotContainsString($asset->asset_tag, $page);
        $this->assertStringContainsString('Pilih Jenis Request', $page);
        $this->assertStringNotContainsString("openModalForm('TT')", $page);                       // kartu TT dihapus
        $this->assertStringNotContainsString('id="formTT"', $page);
    }

    public function test_navigation_has_no_old_login_or_profile_links_and_points_back_to_it_asset(): void
    {
        $html = $this->loginAs($this->makeUser('user'))->get('/requisition/requests/create')->assertOk()->getContent();
        $this->assertStringNotContainsString('Login IT', $html);
        $this->assertStringNotContainsString('/profile', $html);
        $this->assertStringContainsString(MyItems::getUrl(), $html);
        $this->assertStringContainsString(route('logout'), $html);

        $staff = $this->loginAs($this->makeUser('admin'));
        $staffHtml = $staff->get('/requisition/requests/create')->getContent();
        $this->assertStringContainsString(url('/admin'), $staffHtml);                             // tautan "IT Asset"
        $dash = $staff->get('/requisition')->assertOk()->getContent();
        $this->assertStringContainsString('IT Asset', $dash);
        $this->assertStringContainsString(\App\Filament\Resources\Employees\EmployeeResource::getUrl(), $dash);   // Kelola Akun = Karyawan IT Asset
    }

    public function test_logout_from_system_requisition_ends_the_it_asset_session_too(): void
    {
        $u = $this->makeUser('user');
        $this->loginAs($u)->post('/requisition/logout')->assertRedirect(route('filament.admin.auth.login'));
        $this->assertGuest();
    }

    public function test_push_notifications_are_skipped_when_vapid_keys_are_not_configured(): void
    {
        $item = ITRequest::factory()->create();
        $notifiable = $this->makeUser('admin');
        config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);
        foreach ([\App\Notifications\NewRequestSubmitted::class, \App\Notifications\RequestStatusUpdated::class] as $cls) {
            $n = new $cls($item, ...(($cls === \App\Notifications\RequestStatusUpdated::class) ? ['Pending'] : []));
            $this->assertSame([], $n->via($notifiable), $cls);
        }
        config(['webpush.vapid.public_key' => 'a', 'webpush.vapid.private_key' => 'b']);
        $this->assertSame([\NotificationChannels\WebPush\WebPushChannel::class], (new \App\Notifications\NewRequestSubmitted($item))->via($notifiable));
    }

    public function test_peminjaman_reminder_command_is_registered_and_scheduled(): void
    {
        $this->assertArrayHasKey('peminjaman:remind', Artisan::all());
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->command);
        $this->assertTrue($events->contains(fn ($c) => str_contains((string) $c, 'peminjaman:remind')));
    }

    public function test_weekly_report_pptx_is_generated_with_the_pinned_spreadsheet_library(): void
    {
        ITRequest::factory()->create(['type' => 'TICKET_SISTEM', 'category' => 'Hardware', 'status' => 'Completed', 'created_at' => now(), 'approved_at' => now()->subHour(), 'completed_at' => now()]);
        ITRequest::factory()->create(['type' => 'WO', 'status' => 'In Progress', 'created_at' => now()]);

        $res = $this->loginAs($this->makeUser('admin'))->get('/requisition/settings/reports/weekly');
        $res->assertOk();
        $file = $res->baseResponse->getFile()->getPathname();
        $head = file_get_contents($file, false, null, 0, 4);
        $this->assertSame("PK\x03\x04", $head);                                                   // berkas OOXML (zip) yang valid
        $zip = new \ZipArchive; $this->assertTrue($zip->open($file) === true);
        $this->assertNotFalse($zip->locateName('ppt/presentation.xml'));
        $this->assertNotFalse($zip->locateName('[Content_Types].xml'));
        $this->assertGreaterThan(1, collect(range(0, $zip->numFiles - 1))->filter(fn ($i) => str_starts_with($zip->getNameIndex($i), 'ppt/slides/slide'))->count());
        $zip->close();

        $this->loginAs($this->makeUser('user'))->get('/requisition/settings/reports/weekly')->assertForbidden();   // karyawan biasa tidak boleh
    }

    private function ticket(array $over = []): array
    {
        return $over + ['type' => 'TICKET_SISTEM', 'title' => 'Printer macet', 'category' => 'Hardware', 'employee_name' => 'Andi Saputra', 'employee_nik' => 'AKP1001',
            'department' => \App\Models\Department::value('name'), 'position' => 'Staff', 'description' => 'Uji'];
    }

    public function test_nik_is_digits_only_in_requests_just_like_it_asset(): void
    {
        $u = $this->makeUser('user');
        $emp = $this->makeRequester(['name' => 'Tanpa Nik', 'nik' => null]);          // NIK hanya diisi di formulir bila belum ada di database
        $ticket = fn (array $over = []) => $this->ticket(['employee_id' => $emp->id] + $over);
        foreach (['1001', '3201202401', '12345'] as $nik) {
            $this->loginAs($u)->postJson('/requisition/requests', $ticket(['employee_nik' => $nik]))->assertOk();
        }
        foreach (['AKP1001', '3201-2024.01/IT', 'AKP 1001', '10 01', 'NIK_1'] as $bad) {
            $this->loginAs($u)->postJson('/requisition/requests', $ticket(['employee_nik' => $bad]))->assertStatus(422)->assertJsonValidationErrors('employee_nik');
        }
        $this->assertSame('1001', ITRequest::where('user_id', $u->id)->orderBy('id')->value('nik'));
        // halaman memaksa angka saja
        $html = $this->loginAs($u)->get('/requisition/requests/create')->getContent();
        $this->assertStringContainsString('pattern="[0-9]*"', $html);
        $this->assertStringContainsString("replace(/[^0-9]/g, '')", $html);
    }

    public function test_request_forms_are_not_prefilled_from_the_employee_record(): void
    {
        $u = $this->makeUser('user');
        $dept = \App\Models\Department::first();
        Employee::create(['name' => 'Siti Aminah', 'email' => $u->email, 'nik' => '7788', 'position' => 'Akuntan', 'department_id' => $dept->id, 'status' => 'active', 'user_id' => $u->id]);
        $html = $this->loginAs($u)->get('/requisition/requests/create')->assertOk()->getContent();
        $this->assertStringNotContainsString('"nik":"7788"', $html);
        $this->assertStringNotContainsString('var p = {', $html);
    }
}
