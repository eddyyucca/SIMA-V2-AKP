<?php

namespace Tests\Qa;

use App\Filament\Pages\ImportAssets;
use App\Filament\Resources\Assets\Pages\CreateAsset;
use App\Filament\Resources\Assets\Pages\EditAsset;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Filament\Resources\Departments\Pages\ManageDepartments;
use App\Filament\Resources\Employees\Pages\ManageEmployees;
use App\Filament\Resources\Locations\Pages\ManageLocations;
use App\Filament\Resources\Vendors\Pages\ManageVendors;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Vendor;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\QaTestCase;

class Qa02_MasterAndAssetsTest extends QaTestCase
{
    public function test_admin_can_crud_every_master_table_and_data_is_soft_deleted(): void
    {
        $this->as($this->admin());
        $cases = [
            [ManageCategories::class, Category::class, ['name' => 'Kategori QA', 'type' => 'asset', 'code_prefix' => 'QAX'], ['name' => 'Kategori QA Ubah']],
            [ManageDepartments::class, Department::class, ['name' => 'Divisi QA'], ['name' => 'Divisi QA Ubah']],
            [ManageLocations::class, Location::class, ['name' => 'Lokasi QA', 'branch' => 'QA'], ['name' => 'Lokasi QA Ubah']],
            [ManageVendors::class, Vendor::class, ['name' => 'Vendor QA', 'email' => 'qa@vendor.test'], ['name' => 'Vendor QA Ubah']],
        ];
        foreach ($cases as [$page, $model, $create, $edit]) {
            Livewire::test($page)->callAction('create', $create)->assertHasNoActionErrors();
            $row = $model::where('name', $create['name'])->firstOrFail();

            Livewire::test($page)->callAction(TestAction::make('edit')->table($row), $edit)->assertHasNoActionErrors();
            $this->assertSame($edit['name'], $row->fresh()->name);

            Livewire::test($page)->callAction(TestAction::make('delete')->table($row))->assertHasNoActionErrors();
            $this->assertSoftDeleted($row);          // data master tidak dihapus permanen
            $this->assertNotNull($model::withTrashed()->find($row->id));
        }
    }

    public function test_master_forms_validate_required_fields(): void
    {
        $this->as($this->admin());
        Livewire::test(ManageCategories::class)->callAction('create', ['name' => '', 'type' => ''])->assertHasActionErrors(['name' => 'required', 'type' => 'required']);
        Livewire::test(ManageVendors::class)->callAction('create', ['name' => '', 'email' => 'bukan-email'])->assertHasActionErrors(['name' => 'required', 'email']);
        Livewire::test(ManageLocations::class)->callAction('create', ['name' => ''])->assertHasActionErrors(['name' => 'required']);
    }

    public function test_staff_cannot_touch_master_data_but_admin_can(): void
    {
        $cat = Category::first();
        $this->as($this->staff());
        Livewire::test(ManageCategories::class)->assertActionHidden('create')->assertActionHidden(TestAction::make('edit')->table($cat))->assertActionHidden(TestAction::make('delete')->table($cat));
        Livewire::test(ManageLocations::class)->assertActionHidden('create');
        Livewire::test(ManageVendors::class)->assertActionHidden('create');
        Livewire::test(ManageDepartments::class)->assertActionHidden('create');
    }

    public function test_employee_form_rules_and_status(): void
    {
        $this->as($this->staff());
        $dept = Department::first();
        Livewire::test(ManageEmployees::class)->callAction('create', ['name' => 'Karyawan QA', 'email' => 'qa@inventaris.test', 'department_id' => $dept->id, 'status' => 'active'])->assertHasNoActionErrors();
        $e = Employee::where('email', 'qa@inventaris.test')->firstOrFail();
        // email harus unik
        Livewire::test(ManageEmployees::class)->callAction('create', ['name' => 'Dobel', 'email' => 'qa@inventaris.test', 'status' => 'active'])->assertHasActionErrors(['email']);
        // modal "Barang" menampilkan aset, accessories, lisensi
        $siti = $this->emp('Siti Rahma');
        Livewire::test(ManageEmployees::class)->mountAction(TestAction::make('items')->table($siti))->assertHasNoErrors();
        $html = view('filament.employee-items', ['employee' => $siti->fresh()])->render();
        $this->assertStringContainsString('Accessories', $html);
        $this->assertStringContainsString('Seat lisensi', $html);
        $this->assertStringContainsString($siti->assets()->first()->asset_tag, $html);
        $this->assertTrue($siti->holdsItems());
        $this->assertFalse($e->holdsItems());
    }

    public function test_asset_create_generates_tag_validates_unique_serial_and_defaults(): void
    {
        $this->as($this->staff());
        $cat = Category::where('code_prefix', 'NB')->firstOrFail();
        $tag = Asset::generateTag($cat);
        $this->assertMatchesRegularExpression('/^AKP-IT-NB-\d{4}$/', $tag);

        Livewire::test(CreateAsset::class)->fillForm([
            'category_id' => $cat->id, 'asset_tag' => $tag, 'brand' => 'Lenovo', 'model' => 'QA Book', 'serial_number' => 'SN-QA-0001',
            'condition' => 'good', 'purchase_price' => 7500000, 'purchase_date' => now()->subMonth()->toDateString(), 'warranty_until' => now()->addYear()->toDateString(),
        ])->call('create')->assertHasNoFormErrors();
        $a = Asset::where('asset_tag', $tag)->firstOrFail();
        $this->assertSame('available', $a->status);                 // status otomatis Tersedia
        $this->assertSame('SN-QA-0001', $a->serial_number);

        // serial number duplikat ditolak di form (bukan error database)
        Livewire::test(CreateAsset::class)->fillForm([
            'category_id' => $cat->id, 'asset_tag' => Asset::generateTag($cat), 'serial_number' => 'SN-QA-0001', 'condition' => 'good',
        ])->call('create')->assertHasFormErrors(['serial_number']);
        // kode aset duplikat ditolak
        Livewire::test(CreateAsset::class)->fillForm(['category_id' => $cat->id, 'asset_tag' => $tag, 'condition' => 'good'])->call('create')->assertHasFormErrors(['asset_tag']);
        // kategori wajib
        Livewire::test(CreateAsset::class)->fillForm(['asset_tag' => 'X-1', 'condition' => 'good'])->call('create')->assertHasFormErrors(['category_id' => 'required']);
    }

    public function test_asset_tag_sequence_is_per_category_and_year(): void
    {
        $lpt = Category::where('code_prefix', 'NB')->first();
        $mnt = Category::where('code_prefix', 'MNT')->first();
        $nextL = (int) substr(Asset::generateTag($lpt), -3);
        $nextM = (int) substr(Asset::generateTag($mnt), -3);
        $this->assertSame(Asset::withTrashed()->where('asset_tag', 'like', 'AKP-IT-NB-%')->count() + 1, $nextL);
        $this->assertSame(Asset::withTrashed()->where('asset_tag', 'like', 'AKP-IT-MNT-%')->count() + 1, $nextM);
    }

    public function test_asset_photo_and_documents_upload_and_size_limit(): void
    {
        $this->as($this->staff());
        $cat = Category::where('code_prefix', 'PRT')->firstOrFail();
        $ok = Livewire::test(CreateAsset::class)->fillForm([
            'category_id' => $cat->id, 'asset_tag' => Asset::generateTag($cat), 'condition' => 'good',
            'photo_path' => UploadedFile::fake()->image('foto.png', 600, 400)->size(300),
            'documents' => [UploadedFile::fake()->create('invoice.pdf', 200, 'application/pdf')],
        ])->call('create')->assertHasNoFormErrors();
        $a = Asset::orderByDesc('id')->first();
        $this->assertNotNull($a->photo_path);
        $this->assertCount(1, $a->documents);
        $this->assertTrue(\Storage::disk('public')->exists($a->photo_path));

        // > 5 MB ditolak
        Livewire::test(CreateAsset::class)->fillForm([
            'category_id' => $cat->id, 'asset_tag' => Asset::generateTag($cat), 'condition' => 'good',
            'photo_path' => UploadedFile::fake()->image('besar.png')->size(6000),
        ])->call('create')->assertHasFormErrors(['photo_path']);
    }

    public function test_asset_edit_is_audited_with_old_and_new_values(): void
    {
        $this->as($this->staff());
        $a = Asset::where('status', 'available')->first();
        Livewire::test(EditAsset::class, ['record' => $a->getKey()])->fillForm(['condition' => 'minor_damage', 'notes' => 'Lecet di sudut'])->call('save')->assertHasNoFormErrors();
        $log = Activity::where('subject_type', Asset::class)->where('subject_id', $a->id)->where('event', 'updated')->latest('id')->first();
        $this->assertNotNull($log, 'perubahan aset harus tercatat di audit log');
        $this->assertSame($this->staff()->id, $log->causer_id);       // siapa
        $this->assertSame('good', $log->attribute_changes['old']['condition'] ?? $log->properties['old']['condition'] ?? null);   // nilai lama
        $this->assertSame('minor_damage', $log->attribute_changes['attributes']['condition'] ?? $log->properties['attributes']['condition'] ?? null); // nilai baru
        $this->assertNotNull($log->created_at);                       // kapan
    }

    public function test_asset_status_cannot_be_edited_manually(): void
    {
        $this->as($this->staff());
        $a = Asset::where('status', 'available')->first();
        Livewire::test(EditAsset::class, ['record' => $a->getKey()])->fillForm(['status' => 'deployed'])->call('save');
        $this->assertSame('available', $a->fresh()->status);
    }

    public function test_asset_list_search_filters_and_pagination(): void
    {
        $this->as($this->staff());
        $first = Asset::orderBy('id')->first();
        $t = Livewire::test(ListAssets::class);
        $t->assertCanSeeTableRecords(Asset::orderByDesc('asset_tag')->limit(5)->get())
            ->searchTable($first->asset_tag)->assertCanSeeTableRecords([$first])->assertCountTableRecords(1)
            ->searchTable($first->serial_number)->assertCanSeeTableRecords([$first]);

        $repair = Asset::where('ownership', 'owned')->where('status', 'repair')->get();
        $this->assertGreaterThan(0, $repair->count());
        Livewire::test(ListAssets::class)->filterTable('status', 'repair')->assertCanSeeTableRecords($repair)->assertCountTableRecords($repair->count());

        $lpt = Category::where('code_prefix', 'NB')->first();
        Livewire::test(ListAssets::class)->filterTable('category_id', $lpt->id)->assertCountTableRecords(Asset::where('ownership', 'owned')->where('category_id', $lpt->id)->count());

        $loc = Location::where('name', 'Cabang Palu')->first();
        Livewire::test(ListAssets::class)->filterTable('location_id', $loc->id)->assertCountTableRecords(Asset::where('ownership', 'owned')->where('location_id', $loc->id)->count());

        $dept = Department::where('name', 'Finance')->first();
        $expected = Asset::where('ownership', 'owned')->whereHas('employee', fn ($q) => $q->where('department_id', $dept->id))->count();
        $this->assertGreaterThan(0, $expected);
        Livewire::test(ListAssets::class)->filterTable('department', $dept->id)->assertCountTableRecords($expected);

        Livewire::test(ListAssets::class)->filterTable('condition', 'minor_damage')->assertCountTableRecords(Asset::where('ownership', 'owned')->where('condition', 'minor_damage')->count());
    }

    public function test_bulk_label_pdf_for_many_assets(): void
    {
        $this->as($this->staff());
        $records = Asset::orderBy('id')->limit(6)->get();
        Livewire::test(ListAssets::class)->selectTableRecords($records->pluck('id')->all())->callAction(TestAction::make('labels')->table()->bulk())->assertHasNoActionErrors();
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.labels', ['assets' => $records])->output();
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_asset_detail_page_shows_all_connected_information(): void
    {
        $this->as($this->staff());
        $a = Asset::where('model', 'ThinkPad E14 Gen 5')->where('serial_number', '!=', null)->orderByDesc('id')->firstOrFail();
        $this->get(\App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $a]))
            ->assertOk()
            ->assertSee($a->asset_tag)
            ->assertSee($a->employee->name)             // pemegang saat ini
            ->assertSee('RAM DDR4 16GB')                // komponen terpasang
            ->assertSee('BAST/')                        // riwayat pemegang + nomor BAST
            ->assertSee('Windows 11 Pro')               // lisensi pada aset
            ->assertSee('Riwayat perbaikan');
    }

    public function test_import_validates_duplicates_and_unknown_categories(): void
    {
        $this->as($this->staff());
        $existingSerial = Asset::whereNotNull('serial_number')->value('serial_number');
        $csv = "kategori,merek,model,serial_number,tanggal_beli,harga,garansi_sampai,lokasi,vendor\n"
            . "Laptop,Lenovo,QA One,IMP-0001,2026-01-15,9500000,2029-01-15,Kantor Pusat,PT Sumber Teknologi\n"
            . "Monitor,Dell,QA Two,IMP-0002,2026-02-01,2400000,2029-02-01,Kantor Pusat,\n"
            . "Laptop,HP,Dobel File,IMP-0001,2026-03-01,1,,,\n"                      // duplikat di dalam file
            . "Laptop,HP,Dobel DB,{$existingSerial},2026-03-01,1,,,\n"                // duplikat di database
            . "Kategori Ngawur,X,Y,IMP-0003,2026-03-01,1,,,\n";                       // kategori tidak dikenal
        $before = Asset::count();
        $t = Livewire::test(ImportAssets::class)->set('file', UploadedFile::fake()->createWithContent('aset.csv', $csv))->call('import');
        $this->assertSame($before + 2, Asset::count());
        $this->assertSame(2, $t->get('imported'));
        $this->assertCount(3, $t->get('errors_list'));
        $this->assertTrue(Asset::where('serial_number', 'IMP-0001')->exists());
        $this->assertMatchesRegularExpression('/^AKP-IT-NB-\d{4}$/', Asset::where('serial_number', 'IMP-0001')->value('asset_tag'));
        $this->assertSame('available', Asset::where('serial_number', 'IMP-0002')->value('status'));
        $this->get(route('template.assets'))->assertOk()->assertSee('kategori,merek,model,serial_number')->assertSee('biaya_sewa_bulanan,name,cpu,ram');
    }

    public function test_import_reads_spec_columns_and_grows_the_option_lists(): void
    {
        $this->as($this->staff());
        $csv = "kategori,merek,model,serial_number,cpu,RAM,Name\n"
            . "Laptop,Lenovo,Spec One,IMP-SPEC-1,Intel Core i9-99999 QA,16 GB,PC-IMPORT-1\n"
            . "Laptop,Lenovo,Spec Two,IMP-SPEC-2,,,\n";
        Livewire::test(ImportAssets::class)->set('file', UploadedFile::fake()->createWithContent('aset.csv', $csv))->call('import');
        $one = Asset::where('serial_number', 'IMP-SPEC-1')->firstOrFail();
        $this->assertSame('Intel Core i9-99999 QA', $one->spec_data['cpu']);
        $this->assertSame('16 GB', $one->spec_data['ram']);
        $this->assertSame('PC-IMPORT-1', $one->spec_data['name']);
        $this->assertNull(Asset::where('serial_number', 'IMP-SPEC-2')->value('spec_data'));
        $this->assertContains('Intel Core i9-99999 QA', Asset::specFields()['cpu'][3]);
    }
}
