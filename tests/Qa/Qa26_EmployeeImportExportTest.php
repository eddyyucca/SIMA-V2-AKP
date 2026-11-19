<?php

namespace Tests\Qa;

use App\Filament\Resources\Employees\Pages\ManageEmployees;
use App\Models\Department;
use App\Models\Employee;
use App\Services\EmployeeImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\QaTestCase;

class Qa26_EmployeeImportExportTest extends QaTestCase
{
    private function svc(): EmployeeImportService { return app(EmployeeImportService::class); }

    public function test_import_creates_updates_and_blank_cells_do_not_overwrite(): void
    {
        $dept = Department::firstOrFail();
        $existing = Employee::whereNotNull('nik')->whereNotNull('position')->firstOrFail();
        $oldPos = $existing->position; $oldEmail = $existing->email;

        $res = $this->svc()->import([
            ['nik' => '5550100', 'nama' => 'Karyawan Baru', 'email' => 'Baru@Qa.test', 'jabatan' => 'Staff', 'department' => strtoupper($dept->name), 'telepon' => '0812', 'status' => 'Aktif'],
            ['nik' => $existing->nik, 'nama' => 'Nama Diperbarui', 'email' => $oldEmail, 'jabatan' => '', 'department' => '', 'telepon' => '', 'status' => ''],
            ['nik' => '', 'nama' => '', 'email' => '', 'jabatan' => '', 'department' => '', 'telepon' => '', 'status' => ''],     // baris kosong dilewati
        ]);
        $this->assertSame(1, $res['created']); $this->assertSame(1, $res['updated']); $this->assertSame([], $res['errors']);

        $new = Employee::where('nik', '5550100')->firstOrFail();
        $this->assertSame('baru@qa.test', $new->email);                       // email dirapikan huruf kecil
        $this->assertSame($dept->id, $new->department_id);                    // nama department tidak peka huruf besar/kecil
        $this->assertSame('active', $new->status);
        $upd = $existing->fresh();
        $this->assertSame('Nama Diperbarui', $upd->name);
        $this->assertSame($oldPos, $upd->position);                           // sel kosong tidak menimpa
        $this->assertSame($existing->department_id, $upd->department_id);
    }

    public function test_import_match_by_email_when_nik_blank_and_numeric_cells_are_kept_as_text(): void
    {
        $e = Employee::whereNull('nik')->first() ?? tap(Employee::first(), fn ($x) => $x->update(['nik' => null]));
        $res = $this->svc()->import([
            ['nik' => '', 'nama' => 'Via Email', 'email' => $e->email, 'telepon' => 81234567890.0],
            ['nik' => 3201234567890123.0, 'nama' => 'Angka Besar', 'email' => 'angka@qa.test'],
        ]);
        $this->assertSame([], $res['errors']);
        $this->assertSame('Via Email', $e->fresh()->name);
        $this->assertSame('81234567890', $e->fresh()->phone);
        $this->assertNotNull(Employee::where('email', 'angka@qa.test')->value('nik'));
    }

    public function test_bad_rows_are_rejected_with_reasons_and_good_rows_still_imported(): void
    {
        $other = Employee::whereNotNull('nik')->orderBy('id')->firstOrFail();
        $before = Employee::count();
        $res = $this->svc()->import([
            ['nik' => '5550201', 'nama' => 'Baik', 'email' => 'baik@qa.test'],                                   // 2: lolos
            ['nik' => '5550202', 'nama' => '', 'email' => 'x@qa.test'],                                            // 3: nama kosong
            ['nik' => '5550203', 'nama' => 'Email Salah', 'email' => 'bukan-email'],                               // 4
            ['nik' => 'ada spasi', 'nama' => 'NIK Salah', 'email' => 'nik@qa.test'],                           // 5
            ['nik' => '5550204', 'nama' => 'Dept Asing', 'email' => 'dept@qa.test', 'department' => 'Departemen Tidak Ada'],   // 6
            ['nik' => '5550205', 'nama' => 'Status Aneh', 'email' => 'st@qa.test', 'status' => 'mungkin'],         // 7
            ['nik' => '5550201', 'nama' => 'Dobel NIK', 'email' => 'dobel@qa.test'],                              // 8: NIK sama dengan baris 2
            ['nik' => '5550206', 'nama' => 'Dobel Email', 'email' => 'BAIK@qa.test'],                             // 9
            ['nik' => $other->nik, 'nama' => 'Konflik', 'email' => Employee::where('id', '!=', $other->id)->value('email')],   // 10: NIK milik A, email milik B
        ]);
        $this->assertSame(1, $res['created']);
        $this->assertCount(8, $res['errors']);
        $msg = implode("\n", $res['errors']);
        foreach (['Baris 3: nama wajib', 'Baris 4: email', 'Baris 5: NIK', 'Baris 6: department', 'Baris 7: status', 'Baris 8:', 'Baris 9:', 'Baris 10:'] as $needle) {
            $this->assertStringContainsString($needle, $msg);
        }
        $this->assertSame($before + 1, Employee::count());
    }

    public function test_unknown_department_can_be_created_and_deleted_employee_is_reported(): void
    {
        $res = $this->svc()->import([['nik' => '5551001', 'nama' => 'Dept Baru', 'email' => 'dn@qa.test', 'department' => 'Divisi Baru QA']], createDepartments: true);
        $this->assertSame(1, $res['created']);
        $this->assertNotNull(Department::where('name', 'Divisi Baru QA')->first());

        $gone = Employee::where('nik', '5551001')->firstOrFail(); $gone->delete();
        $res = $this->svc()->import([['nik' => '5551001', 'nama' => 'Dept Baru', 'email' => 'dn@qa.test']]);
        $this->assertSame(0, $res['created'] + $res['updated']);
        $this->assertStringContainsString('sudah dihapus', $res['errors'][0]);
    }

    public function test_import_action_runs_from_the_employee_page_and_reports_counts(): void
    {
        Storage::fake('local');
        $this->as($this->staff());
        $csv = "nik,nama,email,jabatan,department,telepon,status\n5551101,Lewat Upload,upload@qa.test,Staff,,0811,aktif\n5551102,,rusak@qa.test,,,,\n";
        Livewire::test(ManageEmployees::class)->callAction('import', ['file' => UploadedFile::fake()->createWithContent('karyawan.csv', $csv), 'create_departments' => false])
            ->assertHasNoActionErrors()->assertNotified();
        $this->assertSame('Lewat Upload', Employee::where('nik', '5551101')->value('name'));
        $this->assertNull(Employee::where('email', 'rusak@qa.test')->first());
        $this->assertSame([], Storage::disk('local')->files('employee-imports'));         // berkas unggahan dihapus setelah diproses
    }

    public function test_export_contains_all_employees_as_text_and_roundtrips_into_import(): void
    {
        $this->as($this->staff());
        Livewire::test(ManageEmployees::class)->callAction('export_csv')->assertFileDownloaded('karyawan-' . now()->format('Ymd') . '.csv');
        Livewire::test(ManageEmployees::class)->callAction('export_xlsx')->assertFileDownloaded('karyawan-' . now()->format('Ymd') . '.xlsx');

        $rows = $this->svc()->exportRows();
        $this->assertCount(Employee::count(), $rows);
        $this->assertContainsOnlyString($rows[0]);
        // hasil export bisa diimpor kembali tanpa perubahan dan tanpa error
        $assoc = array_map(fn ($r) => array_combine(EmployeeImportService::HEAD, $r), $rows);
        $res = $this->svc()->import($assoc);
        $this->assertSame([], $res['errors']);
        $this->assertSame(0, $res['created']);
        $this->assertSame(count($rows), $res['updated']);
    }

    public function test_permissions_and_template(): void
    {
        $this->as($this->mgmt());
        Livewire::test(ManageEmployees::class)->assertActionHidden('import');
        $this->as($this->staff());
        Livewire::test(ManageEmployees::class)->assertActionVisible('import');
        $this->get(route('template.employees'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        auth()->forgetGuards();
        $this->get(route('template.employees'))->assertRedirect();
    }

    /** File xlsx/xls/csv dibaca dari isinya: nama berekstensi salah (mis. .zip dari Google Sheets/LibreOffice) tetap bisa diimpor. */
    public function test_import_detects_file_type_from_content_not_extension(): void
    {
        $this->as($this->staff());
        $rows = [['5551201', 'Dari Xlsx', 'xlsx@qa.test', 'Staff', '', '0811', 'aktif']];
        $xlsx = tempnam(sys_get_temp_dir(), 'x') . '.xlsx';
        \Maatwebsite\Excel\Facades\Excel::store(new \App\Reports\TextExport(EmployeeImportService::HEAD, $rows), 'qa.xlsx', 'local');
        copy(Storage::disk('local')->path('qa.xlsx'), $xlsx);
        Storage::disk('local')->delete('qa.xlsx');

        $this->assertSame(\Maatwebsite\Excel\Excel::XLSX, \App\Support\SpreadsheetFile::readerType($xlsx));
        file_put_contents($csv = tempnam(sys_get_temp_dir(), 'c'), "nik,nama,email\n5551202,Dari Csv,csv@qa.test\n");
        $this->assertSame(\Maatwebsite\Excel\Excel::CSV, \App\Support\SpreadsheetFile::readerType($csv));

        Storage::fake('local');
        // xlsx (disimpan server dengan ekstensi tebakan) dan csv bertitik koma + BOM, lewat tombol Import
        Livewire::test(ManageEmployees::class)->callAction('import', ['file' => UploadedFile::fake()->createWithContent('Karyawan.xlsx', file_get_contents($xlsx)), 'create_departments' => false])
            ->assertHasNoActionErrors();
        $this->assertSame('Dari Xlsx', Employee::where('nik', '5551201')->value('name'));
        $semi = "\xEF\xBB\xBFnik;nama;email;jabatan\n5551203;Titik Koma;semi@qa.test;Staff\n";
        Livewire::test(ManageEmployees::class)->callAction('import', ['file' => UploadedFile::fake()->createWithContent('Karyawan Excel Indonesia.csv', $semi), 'create_departments' => false])
            ->assertHasNoActionErrors();
        $this->assertSame('Titik Koma', Employee::where('nik', '5551203')->value('name'));
        // ekstensi yang tidak didukung ditolak di formulir
        Livewire::test(ManageEmployees::class)->callAction('import', ['file' => UploadedFile::fake()->createWithContent('data.pdf', '%PDF-1.4'), 'create_departments' => false])
            ->assertHasActionErrors(['file']);
        // file rusak: notifikasi merah, bukan error 500
        Livewire::test(ManageEmployees::class)->callAction('import', ['file' => UploadedFile::fake()->createWithContent('rusak.xlsx', "PK\x03\x04 bukan xlsx beneran"), 'create_departments' => false])
            ->assertNotified('File tidak bisa dibaca');
    }
}
