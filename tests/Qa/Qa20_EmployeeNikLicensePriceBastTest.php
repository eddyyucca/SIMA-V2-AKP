<?php

namespace Tests\Qa;

use App\Filament\Resources\Employees\Pages\ManageEmployees;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Filament\Resources\Licenses\Pages\ManageLicenses;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Department;
use App\Models\Employee;
use App\Models\License;
use App\Models\Location;
use App\Reports\ReportCatalog;
use App\Services\ArrivalPlanService;
use App\Services\AssetService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\QaTestCase;

class Qa20_EmployeeNikLicensePriceBastTest extends QaTestCase
{
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $p) Storage::disk('local')->delete($p);
        parent::tearDown();
    }

    private function pdf(string $name = 'bast-ttd.pdf'): File
    {
        return \Illuminate\Http\UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    /** Aset bebas yang sudah punya satu BAST serah terima. */
    private function assetWithBast(): array
    {
        $this->as($this->staff());
        $asset = $this->freeAsset();
        $to = $this->emp('Andi Saputra');
        $a = app(AssetService::class)->checkout($asset, $to, ['condition' => 'good']);
        return [$asset->fresh(), $a->fresh(), $to];
    }

    // ---------- Karyawan ----------

    public function test_employee_form_saves_nik_jabatan_and_department(): void
    {
        $this->as($this->admin());
        $dept = Department::first();
        Livewire::test(ManageEmployees::class)->callAction('create', [
            'nik' => '9001001', 'name' => 'Karyawan Nik', 'email' => 'nik@inventaris.test', 'position' => 'Surveyor', 'department_id' => $dept->id, 'status' => 'active',
        ])->assertHasNoActionErrors();
        $e = Employee::where('email', 'nik@inventaris.test')->firstOrFail();
        $this->assertSame(['9001001', 'Surveyor', $dept->id], [$e->nik, $e->position, $e->department_id]);
    }

    public function test_nik_must_be_unique_and_clean(): void
    {
        $this->as($this->admin());
        $existing = Employee::whereNotNull('nik')->firstOrFail();
        $base = ['name' => 'Dobel Nik', 'email' => 'dobel.nik@inventaris.test', 'status' => 'active'];
        Livewire::test(ManageEmployees::class)->callAction('create', $base + ['nik' => $existing->nik])->assertHasActionErrors(['nik']);
        Livewire::test(ManageEmployees::class)->callAction('create', $base + ['nik' => 'ada spasi'])->assertHasActionErrors(['nik']);
        Livewire::test(ManageEmployees::class)->callAction('create', $base + ['nik' => str_repeat('1', 51)])->assertHasActionErrors(['nik']);
        $this->assertDatabaseMissing('employees', ['email' => 'dobel.nik@inventaris.test']);
        // NIK boleh kosong (data lama belum punya NIK), dan dua karyawan tanpa NIK tidak bentrok
        Livewire::test(ManageEmployees::class)->callAction('create', $base)->assertHasNoActionErrors();
        Livewire::test(ManageEmployees::class)->callAction('create', ['email' => 'kosong2@inventaris.test', 'name' => 'Tanpa Nik 2', 'status' => 'active'])->assertHasNoActionErrors();
        // mengubah data sendiri dengan NIK yang sama tidak dianggap duplikat
        Livewire::test(ManageEmployees::class)->callAction(TestAction::make('edit')->table($existing), ['nik' => $existing->nik, 'position' => 'Jabatan Baru'])->assertHasNoActionErrors();
        $this->assertSame('Jabatan Baru', $existing->fresh()->position);
    }

    public function test_employee_table_shows_nik_jabatan_department_and_searches_by_nik(): void
    {
        $this->as($this->admin());
        $e = Employee::whereNotNull('nik')->firstOrFail();
        $other = Employee::whereNotNull('nik')->where('id', '!=', $e->id)->firstOrFail();
        Livewire::test(ManageEmployees::class)
            ->assertTableColumnExists('nik')->assertTableColumnExists('position')->assertTableColumnExists('department.name')
            ->assertTableColumnStateSet('nik', $e->nik, $e)
            ->searchTable($e->nik)->assertCanSeeTableRecords([$e])->assertCanNotSeeTableRecords([$other]);
    }

    public function test_label_divisi_is_gone_everywhere_and_renamed_department(): void
    {
        $files = array_merge(
            iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS))),
            iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS))),
        );
        foreach ($files as $f) {
            if (! preg_match('/\.php$/', $f->getFilename())) continue;
            if (str_contains($f->getPathname(), 'Services/Hris/')) continue;   // nama field API HRIS ('division') dipetakan ke kolom business_unit
            $this->assertStringNotContainsStringIgnoringCase('divisi', file_get_contents($f->getPathname()), $f->getPathname());
        }
        $this->as($this->admin());
        $this->get('/admin/departments')->assertOk()->assertSee('Department');
    }

    public function test_employee_items_report_has_nik_jabatan_department_columns(): void
    {
        [$title, $head, $rows] = ReportCatalog::build('employee_items', []);
        $this->assertSame(['NIK', 'Karyawan', 'Jabatan', 'Department', 'Status', 'Jenis', 'Barang', 'Jumlah'], $head);
        $this->assertNotEmpty($rows);
        foreach ($rows as $r) $this->assertCount(count($head), $r);
        $this->assertTrue(collect($rows)->contains(fn ($r) => filled($r[0]) && filled($r[2])), 'ada baris dengan NIK dan jabatan');
    }

    public function test_bast_pdf_shows_employee_nik_jabatan_and_department(): void
    {
        [, $a, $to] = $this->assetWithBast();
        $to->update(['nik' => 'AKP-BAST-7', 'position' => 'Jabatan BAST']);
        $html = view('pdf.bast', ['a' => $a->fresh()->load(['asset.category', 'from', 'to.department', 'location', 'creator']), 'sigReceiver' => null, 'sigIt' => null])->render();
        $this->assertStringContainsString('AKP-BAST-7', $html);
        $this->assertStringContainsString('Jabatan BAST', $html);
        $this->assertStringContainsString((string) $to->department?->name, $html);
        app(AssetService::class)->makeBast($a->fresh());                         // PDF sungguhan tetap bisa dibuat
        $this->assertTrue(Storage::disk('local')->exists($a->fresh()->bast_pdf_path));
    }

    // ---------- Rencana kedatangan membawa NIK ----------

    public function test_arrival_plan_carries_nik_to_the_new_employee_and_rejects_duplicates(): void
    {
        $this->as($this->staff());
        $svc = app(ArrivalPlanService::class);
        $base = ['arrival_date' => now()->addDays(4)->toDateString(), 'location_id' => Location::first()->id];
        $taken = Employee::whereNotNull('nik')->value('nik');
        try { $svc->create($base + ['name' => 'Calon Dobel', 'nik' => $taken]); $this->fail('NIK sudah dipakai'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('NIK', $e->getMessage()); }

        $p = $svc->create($base + ['name' => 'Calon Nik', 'nik' => ' AKP-NEW-1 ', 'position' => 'Operator']);
        $this->assertSame('AKP-NEW-1', $p->nik);
        $asset = $this->freeAsset();
        $svc->addAsset($p, $asset);
        foreach ($p->items()->get() as $it) { $svc->setItemStatus($it, 'prepared'); $svc->setItemStatus($it->fresh(), 'ready'); }
        $emp = $svc->handover($p->fresh(), ['email' => 'calon.nik@inventaris.test', 'condition' => 'good']);
        $this->assertSame('AKP-NEW-1', Employee::where('email', 'calon.nik@inventaris.test')->value('nik'));
    }

    // ---------- Lisensi & langganan ----------

    public function test_license_table_has_price_column_and_total(): void
    {
        $this->as($this->admin());
        $l = License::where('price', '>', 0)->firstOrFail();
        $expected = 'Rp' . "\u{a0}" . number_format((float) $l->price, 0, ',', '.');
        Livewire::test(ManageLicenses::class)
            ->assertTableColumnExists('price')
            ->assertCanRenderTableColumn('price')
            ->assertSee('Total harga')
            ->assertTableColumnFormattedStateSet('price', $expected, $l);
    }

    public function test_license_price_can_be_saved_and_rejects_negative(): void
    {
        $this->as($this->admin());
        $l = License::firstOrFail();
        Livewire::test(ManageLicenses::class)->callAction(TestAction::make('edit')->table($l), ['price' => 1250000])->assertHasNoActionErrors();
        $this->assertEquals(1250000, $l->fresh()->price);
        Livewire::test(ManageLicenses::class)->callAction(TestAction::make('edit')->table($l), ['price' => -5])->assertHasActionErrors(['price']);
        $this->assertEquals(1250000, $l->fresh()->price);
    }

    public function test_license_report_lists_price(): void
    {
        [, $head, $rows] = ReportCatalog::build('licenses', []);
        $this->assertContains('Harga (Rp)', $head);
        $i = array_search('Harga (Rp)', $head);
        $l = License::where('price', '>', 0)->firstOrFail();
        $row = collect($rows)->first(fn ($r) => $r[0] === $l->name);
        $this->assertSame(number_format((float) $l->price, 0, ',', '.'), $row[$i]);
    }

}
