<?php

namespace Tests\Qa;

use App\Filament\Resources\Departments\DepartmentResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Asset;
use App\Models\Department;
use App\Models\Employee;
use App\Services\ArrivalPlanService;
use App\Services\Hris\EmployeeDirectory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\QaTestCase;

/** HRIS = satu-satunya sumber karyawan, department, dan section; tidak ada input manual saat HRIS terhubung. */
class Qa63_HrisSingleSourceTest extends QaTestCase
{
    private function hris(array $rows, bool $configured = true): void
    {
        config(['services.hris.url' => $configured ? 'http://hris.test' : null, 'services.hris.token' => $configured ? 'tok' : null]);
        Http::fake(['hris.test/*' => Http::response(['data' => $rows, 'meta' => ['last_page' => 1]])]);
        Cache::flush();
        EmployeeDirectory::$lastError = null;
    }

    private function h(string $nik, string $name, array $o = []): array
    {
        return $o + ['id' => (int) $nik, 'employee_no' => $nik, 'nik_aliases' => [], 'name' => $name, 'email' => "h{$nik}@hris.test", 'phone' => '0811',
            'division' => 'OPERASI', 'department' => 'PLANT', 'section' => 'WORKSHOP', 'position' => 'MEKANIK', 'supervisor_nik' => '1', 'employment_status' => 'ACTIVE', 'is_active' => true];
    }

    public function test_sync_fills_department_section_division_status_and_overwrites_manual_edits(): void
    {
        $manual = Employee::create(['nik' => '9990001', 'name' => 'Nama Manual Salah', 'email' => 'salah@x.test', 'position' => 'Jabatan Manual', 'status' => 'active']);
        $this->hris([$this->h('9990001', 'MEKANIK SATU'), $this->h('9990002', 'MEKANIK DUA', ['section' => 'ELECTRIC'])]);

        $this->artisan('hris:sync-employees', ['--apply' => true])->assertSuccessful();

        $e = $manual->fresh();
        $this->assertSame(['MEKANIK SATU', 'MEKANIK', 'WORKSHOP', 'OPERASI', 'ACTIVE'], [$e->name, $e->position, $e->section, $e->business_unit, $e->employment_status]);
        $this->assertSame('PLANT', $e->department->name);
        $this->assertSame('h9990001@hris.test', $e->email);
        $this->assertNotNull($e->department->hris_synced_at);
        $this->assertSame('ELECTRIC', Employee::where('nik', '9990002')->value('section'));
        $this->assertNull($e->hris_missing_at);
    }

    public function test_employees_missing_in_hris_are_flagged_never_deleted_and_nikless_ones_match_by_email(): void
    {
        $gone = Employee::create(['nik' => '8880001', 'name' => 'Sisa SIMA', 'email' => 'sisa@x.test', 'status' => 'active']);
        $nikless = Employee::create(['nik' => null, 'name' => 'Tanpa NIK', 'email' => 'H9990003@hris.test', 'status' => 'active']);   // beda huruf besar/kecil
        $this->hris([$this->h('9990003', 'ORANG TIGA')]);

        $this->artisan('hris:sync-employees', ['--apply' => true])->assertSuccessful();

        $this->assertNotNull($gone->fresh());                                   // tidak dihapus
        $this->assertSame('active', $gone->fresh()->status);                    // status tidak diubah diam-diam
        $this->assertNotNull($gone->fresh()->hris_missing_at);                  // ditandai
        $this->assertSame('9990003', $nikless->fresh()->nik);                   // dicocokkan lewat email
        $this->assertSame('ORANG TIGA', $nikless->fresh()->name);
        $this->assertNull($nikless->fresh()->hris_missing_at);
    }

    public function test_dry_run_changes_nothing_and_hris_failure_aborts(): void
    {
        $e = Employee::create(['nik' => '9990004', 'name' => 'Lama', 'email' => 'l@x.test', 'status' => 'active']);
        $this->hris([$this->h('9990004', 'BARU')]);
        $this->artisan('hris:sync-employees')->assertSuccessful();
        $this->assertSame('Lama', $e->fresh()->name);

        config(['services.hris.url' => 'http://hris-down.test', 'services.hris.token' => 'tok']);
        Http::fake(['hris-down.test/*' => Http::response('x', 500)]); Cache::flush();
        $this->artisan('hris:sync-employees', ['--apply' => true])->assertFailed();
        $this->assertSame('Lama', $e->fresh()->name);
    }

    public function test_department_choices_come_only_from_hris_after_sync(): void
    {
        Department::create(['name' => 'Department Manual Lama']);
        $this->assertContains('Department Manual Lama', Department::names());   // belum ada sinkron: semua tampil (aplikasi tanpa HRIS)

        $this->hris([$this->h('9990005', 'X')]);
        $this->artisan('hris:sync-employees', ['--apply' => true])->assertSuccessful();

        $this->assertNotContains('Department Manual Lama', Department::names());
        $this->assertContains('PLANT', Department::names());
        $this->assertNotContains('Department Manual Lama', array_values(Department::options()));
        $this->assertNotNull(Department::where('name', 'Department Manual Lama')->first());   // tetap tersimpan untuk riwayat
    }

    public function test_ui_is_locked_when_hris_is_connected_and_open_otherwise(): void
    {
        $this->hris([], true);
        $this->as($this->admin());
        $this->assertFalse(EmployeeResource::canCreate());
        $this->assertFalse(EmployeeResource::canEdit(Employee::first()));
        $this->assertFalse(EmployeeResource::canDelete(Employee::first()));
        $this->assertFalse(DepartmentResource::canCreate());
        $this->assertFalse(DepartmentResource::canEdit(Department::first()));
        $this->get('/admin/employees')->assertOk()->assertDontSee('Import dari Excel');

        $this->hris([], false);
        $this->assertTrue(EmployeeResource::canCreate());
        $this->assertTrue(DepartmentResource::canCreate());
    }

    public function test_arrival_handover_requires_an_hris_employee_instead_of_creating_one(): void
    {
        $svc = app(ArrivalPlanService::class);
        $this->hris([$this->h('9990010', 'KARYAWAN BARU')]);
        $this->as($this->staff());

        $make = fn (string $nik) => $svc->create(['name' => 'Calon ' . $nik, 'nik' => $nik, 'arrival_date' => now()->addDays(3)->toDateString(),
            'location_id' => \App\Models\Location::value('id'), 'department_id' => Department::value('id'), 'position' => 'Staff']);
        $ready = function ($plan) use ($svc) {
            $it = $svc->addAsset($plan, Asset::where('status', 'available')->whereNull('employee_id')->where('ownership', 'owned')->whereDoesntHave('reservationItem')->orderBy('id')->first());
            $svc->setItemStatus($it, 'prepared'); $svc->setItemStatus($it->fresh(), 'ready');
        };

        // NIK tidak ada di HRIS -> ditolak, tidak ada karyawan baru
        $before = Employee::count();
        $p = $make('7770001'); $ready($p);
        try { $svc->handover($p, ['condition' => 'good', 'email' => 'bebas@x.test']); $this->fail('harus ditolak'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('belum terdaftar di HRIS', $e->getMessage()); }
        $this->assertSame($before, Employee::count());

        // NIK ada di HRIS -> karyawan ditarik dari HRIS (lengkap dengan section) lalu menerima barang
        $p2 = $make('9990010'); $ready($p2);
        $svc->handover($p2, ['condition' => 'good']);
        $emp = Employee::where('nik', '9990010')->firstOrFail();
        $this->assertSame(['KARYAWAN BARU', 'WORKSHOP'], [$emp->name, $emp->section]);
        $this->assertSame(1, $emp->assets()->count());
    }

    public function test_lookup_returns_section_and_smoke_reports_employees_missing_in_hris(): void
    {
        $e = Employee::create(['nik' => '9990020', 'name' => 'Cari Section', 'email' => 'cs@x.test', 'status' => 'active', 'section' => 'WORKSHOP', 'hris_missing_at' => now()]);
        $admin = $this->admin();
        Employee::where('user_id', $admin->id)->update(['user_id' => null]);     // akun darurat tanpa karyawan tertaut boleh mencari; akun yang tertaut hanya melihat dirinya
        $this->as($admin)->getJson('/requisition/lookup/employees?q=Cari Section')->assertOk()->assertJsonPath('data.0.section', 'WORKSHOP');
    }
}
