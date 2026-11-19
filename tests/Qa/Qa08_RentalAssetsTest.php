<?php

namespace Tests\Qa;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ImportAssets;
use App\Filament\Resources\Assets\Pages\CreateAsset;
use App\Filament\Resources\Assets\Pages\EditAsset;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Filament\Resources\Disposals\Pages\ManageDisposals;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Disposal;
use App\Models\License;
use App\Models\LicenseSeat;
use App\Models\Vendor;
use App\Reports\ReportCatalog;
use App\Services\AssetService;
use App\Services\DisposalService;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\QaTestCase;

class Qa08_RentalAssetsTest extends QaTestCase
{
    private function rental(string $state = 'free'): Asset
    {
        $q = Asset::where('ownership', 'rental');
        return match ($state) {
            'free' => $q->where('status', 'available')->firstOrFail(),
            'held' => $q->whereNotNull('employee_id')->where('status', 'deployed')->orderBy('id')->firstOrFail(),
            'returned' => $q->where('status', 'returned')->firstOrFail(),
            'overdue' => $q->where('status', '!=', 'returned')->whereDate('rental_end', '<', now()->toDateString())->firstOrFail(),
        };
    }

    public function test_demo_has_complete_rental_data_documents_and_contract(): void
    {
        $rentals = Asset::where('ownership', 'rental')->get();
        $this->assertGreaterThanOrEqual(5, $rentals->count());
        foreach ($rentals as $a) {
            $this->assertNotNull($a->rental_start); $this->assertNotNull($a->rental_end); $this->assertNotNull($a->vendor_id);
            $this->assertNotEmpty($a->rental_contract_no);
            $this->assertGreaterThan(0, (float) $a->rental_monthly_cost);
            $this->assertEquals(0, (float) $a->purchase_price);                     // aset sewa tidak punya harga beli
            $this->assertNotNull($a->photo_path);
            $this->assertNotEmpty($a->documents);                                   // kontrak sewa (PDF)
            $this->assertTrue(\Storage::disk('public')->exists($a->documents[0]));
        }
        $this->assertTrue($rentals->contains('status', 'returned'));               // ada yang sudah dikembalikan
        $this->assertTrue($rentals->contains(fn ($a) => $a->status !== 'returned' && $a->rental_days_left < 0));   // ada yang lewat jatuh tempo
        $this->assertTrue($rentals->contains(fn ($a) => $a->status !== 'returned' && $a->rental_days_left >= 0 && $a->rental_days_left <= 30));
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_create_rental_asset_requires_contract_fields_and_clears_purchase_data(): void
    {
        $this->as($this->staff());
        $cat = Category::where('code_prefix', 'NB')->firstOrFail();
        $vendor = Vendor::where('name', 'PT Rental Teknologi Nusantara')->firstOrFail();
        $tag = Asset::generateTag($cat);

        // tanggal mulai, berakhir, dan vendor wajib untuk aset sewa
        Livewire::test(\App\Filament\Resources\RentalAssets\Pages\CreateRentalAsset::class)->fillForm(['category_id' => $cat->id, 'asset_tag' => $tag, 'condition' => 'good'])
            ->call('create')->assertHasFormErrors(['rental_start' => 'required', 'rental_end' => 'required', 'vendor_id' => 'required']);
        // berakhir tidak boleh sebelum mulai
        Livewire::test(\App\Filament\Resources\RentalAssets\Pages\CreateRentalAsset::class)->fillForm(['category_id' => $cat->id, 'asset_tag' => $tag, 'condition' => 'good', 'vendor_id' => $vendor->id,
            'rental_start' => '2026-06-01', 'rental_end' => '2026-01-01', 'rental_monthly_cost' => 500000])->call('create')->assertHasFormErrors(['rental_end']);

        Livewire::test(\App\Filament\Resources\RentalAssets\Pages\CreateRentalAsset::class)->fillForm([
            'category_id' => $cat->id, 'asset_tag' => $tag, 'brand' => 'Acer', 'model' => 'Sewa QA', 'serial_number' => 'RENT-QA-1', 'condition' => 'good',
            'vendor_id' => $vendor->id, 'rental_contract_no' => 'KTR-QA-1', 'rental_start' => '2026-01-01', 'rental_end' => '2027-12-31',
            'rental_monthly_cost' => 800000, 'rental_deposit' => 1600000, 'purchase_price' => 9999999,
        ])->call('create')->assertHasNoFormErrors();
        $a = Asset::where('asset_tag', $tag)->firstOrFail();
        $this->assertSame('rental', $a->ownership);
        $this->assertSame('available', $a->status);
        $this->assertEquals(0, (float) $a->purchase_price);                          // harga beli diabaikan untuk sewa
        $this->assertSame('2026-01-01', $a->purchase_date->toDateString());
        $this->assertEquals(800000, (float) $a->rental_monthly_cost);

        // aset milik sendiri: data sewa dikosongkan
        $tag2 = Asset::generateTag($cat);
        Livewire::test(CreateAsset::class)->fillForm(['category_id' => $cat->id, 'asset_tag' => $tag2, 'condition' => 'good', 'ownership' => 'owned', 'purchase_price' => 5000000,
            'rental_contract_no' => 'SISA', 'rental_monthly_cost' => 123])->call('create')->assertHasNoFormErrors();
        $o = Asset::where('asset_tag', $tag2)->firstOrFail();
        $this->assertSame('owned', $o->ownership);
        $this->assertNull($o->rental_contract_no);
        $this->assertEquals(0, (float) $o->rental_monthly_cost);
        $this->assertEquals(5000000, (float) $o->purchase_price);
    }

    public function test_rental_is_visible_filterable_and_searchable_in_asset_list(): void
    {
        $this->as($this->staff());
        $rent = Asset::where('ownership', 'rental')->get();
        Livewire::test(\App\Filament\Resources\RentalAssets\Pages\ListRentalAssets::class)->removeTableFilter('active')->assertCanSeeTableRecords($rent)->assertCountTableRecords($rent->count());
        Livewire::test(ListAssets::class)->assertCanNotSeeTableRecords($rent)->assertCountTableRecords(Asset::where('ownership', 'owned')->count());
        $soon = Asset::where('ownership', 'rental')->where('status', '!=', 'returned')->whereDate('rental_end', '<=', now()->addDays(30)->toDateString())->get();
        $this->assertGreaterThanOrEqual(2, $soon->count());
        Livewire::test(\App\Filament\Resources\RentalAssets\Pages\ListRentalAssets::class)->filterTable('expiring', true)->assertCanSeeTableRecords($soon)->assertCountTableRecords($soon->count());
        Livewire::test(\App\Filament\Resources\RentalAssets\Pages\ListRentalAssets::class)->removeTableFilter('active')->filterTable('status', 'returned')->assertCountTableRecords(Asset::where('ownership', 'rental')->where('status', 'returned')->count());
    }

    public function test_rental_asset_can_be_handed_over_like_any_other_asset(): void
    {
        $this->as($this->staff());
        $a = $this->rental('free');                                                   // printer sewa yang tersedia
        $emp = $this->emp('Yusuf Maulana');
        Livewire::test(ViewAsset::class, ['record' => $a->getKey()])->callAction('checkout', ['employee_id' => $emp->id, 'condition' => 'good'])->assertHasNoActionErrors();
        $this->assertSame('deployed', $a->fresh()->status);
        $this->assertSame('rental', $a->fresh()->ownership);                          // tetap sewa setelah serah terima
        $this->assertNotNull($a->assignments()->first()->bast_pdf_path);
    }

    public function test_return_to_vendor_rules_and_effects(): void
    {
        $this->as($this->staff());
        $held = $this->rental('held');
        $page = fn (Asset $x) => Livewire::test(ViewAsset::class, ['record' => $x->getKey()]);

        // masih dipegang karyawan → ditolak
        $page($held)->callAction('return_vendor', ['returned_at' => now()->toDateString(), 'condition' => 'good', 'data_wiped' => true])->assertNotifiedFailure();
        $this->assertNotSame('returned', $held->fresh()->status);

        // tarik dulu (checkin) lalu kembalikan; wipe data wajib
        $this->as($this->staff());
        $page($held)->callAction('checkin', ['condition' => 'good'])->assertHasNoActionErrors();
        $held->refresh();
        $page($held)->callAction('return_vendor', ['returned_at' => now()->toDateString(), 'condition' => 'good', 'data_wiped' => false])->assertHasActionErrors(['data_wiped']);
        $this->assertNotSame('returned', $held->fresh()->status);

        // perbaikan terbuka → ditolak
        $repair = app(AssetService::class)->reportDamage($held->fresh(), null, 'Layar bergaris');
        $page($held->fresh())->callAction('return_vendor', ['returned_at' => now()->toDateString(), 'condition' => 'good', 'data_wiped' => true])->assertNotifiedFailure();
        app(AssetService::class)->finishRepair($repair, ['action' => 'Diperbaiki vendor']);

        // seat lisensi pada aset ikut dicabut
        $lic = License::where('name', 'Windows 11 Pro (Volume)')->first();
        app(\App\Services\LicenseService::class)->assign($lic, null, $held->fresh());
        $this->assertSame(1, LicenseSeat::where('asset_id', $held->id)->whereNull('revoked_at')->count());

        $page($held->fresh())->callAction('return_vendor', ['returned_at' => '2026-11-01', 'condition' => 'minor_damage', 'data_wiped' => true, 'notes' => 'BA-QA-1'])->assertHasNoActionErrors();
        $held->refresh();
        $this->assertSame('returned', $held->status);
        $this->assertSame('2026-11-01', $held->returned_at->toDateString());
        $this->assertSame('minor_damage', $held->condition);
        $this->assertSame('BA-QA-1', $held->return_notes);
        $this->assertNull($held->employee_id);
        $this->assertSame(0, LicenseSeat::where('asset_id', $held->id)->whereNull('revoked_at')->count());
        $this->assertNull($held->deleted_at);                                         // data tidak dihapus

        // tidak bisa diproses/dipakai lagi
        $page($held)->assertActionHidden('return_vendor')->assertActionHidden('checkout')->assertActionHidden('extend_rental');
        $this->expectException(\InvalidArgumentException::class);
        app(AssetService::class)->checkout($held, $this->emp('Andi Saputra'));
    }

    public function test_owned_assets_cannot_be_returned_and_rentals_cannot_be_disposed(): void
    {
        $this->as($this->staff());
        $owned = $this->freeAsset();
        Livewire::test(ViewAsset::class, ['record' => $owned->getKey()])->assertActionHidden('return_vendor')->assertActionHidden('extend_rental');
        try {
            app(AssetService::class)->returnToVendor($owned, ['data_wiped' => true]);
            $this->fail('aset milik sendiri tidak boleh dikembalikan ke vendor');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('disposal', $e->getMessage());
        }
        $rent = $this->rental('free');
        Livewire::test(ViewAsset::class, ['record' => $rent->getKey()])->assertActionHidden('dispose');
        Livewire::test(ManageDisposals::class)->callAction('create', ['asset_id' => $rent->id, 'reason' => 'x', 'data_wiped' => true])->assertHasActionErrors(['asset_id']);   // aset sewa tidak ada di pilihan disposal
        $this->assertSame(0, Disposal::where('asset_id', $rent->id)->count());
        $this->expectException(\InvalidArgumentException::class);
        app(DisposalService::class)->request($rent->id, 'x', true);
    }

    public function test_extend_rental_updates_end_date_and_cost_and_is_audited(): void
    {
        $this->as($this->staff());
        $a = $this->rental('overdue');
        $oldEnd = $a->rental_end->toDateString();
        $newEnd = now()->addYear()->toDateString();
        // tanggal baru harus setelah yang lama
        Livewire::test(ViewAsset::class, ['record' => $a->getKey()])->callAction('extend_rental', ['rental_end' => $oldEnd, 'monthly' => 1])->assertNotifiedFailure();
        $this->assertSame($oldEnd, $a->fresh()->rental_end->toDateString());

        Livewire::test(ViewAsset::class, ['record' => $a->getKey()])->callAction('extend_rental', ['rental_end' => $newEnd, 'monthly' => 777000])->assertHasNoActionErrors();
        $a->refresh();
        $this->assertSame($newEnd, $a->rental_end->toDateString());
        $this->assertEquals(777000, (float) $a->rental_monthly_cost);
        $this->assertGreaterThan(0, $a->rental_days_left);                           // tidak lagi lewat jatuh tempo
        $log = \Spatie\Activitylog\Models\Activity::where('subject_type', Asset::class)->where('subject_id', $a->id)->latest('id')->first();
        $this->assertSame($this->staff()->id, $log->causer_id);
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_detail_page_shows_rental_contract_and_return_info(): void
    {
        $this->as($this->staff());
        $a = $this->rental('overdue');
        $this->get(\App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $a]))->assertOk()
            ->assertSee('Aset Sewa (Rental)')->assertSee($a->rental_contract_no)->assertSee('Lewat jatuh tempo')->assertSee('PT Rental Teknologi Nusantara');
        $r = $this->rental('returned');
        $this->get(\App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $r]))->assertOk()
            ->assertSee('Sudah dikembalikan')->assertSee('Dikembalikan pada')->assertSee('BA-RNT-009');
        $this->get(\App\Filament\Resources\Assets\AssetResource::getUrl('edit', ['record' => $a]))->assertOk();
    }

    public function test_dashboard_counts_rentals_and_excludes_returned_assets_from_active_totals(): void
    {
        $this->as($this->admin());
        $d = Livewire::test(Dashboard::class)->instance()->data();
        $active = Asset::where('ownership', 'rental')->where('status', '!=', 'returned');
        $this->assertSame($active->count(), $d['rentalCount']);
        $this->assertEqualsWithDelta((float) $active->sum('rental_monthly_cost'), $d['rentalMonthly'], 0.01);
        $this->assertGreaterThanOrEqual(2, $d['rentalExpiring']->count());              // segera berakhir + lewat jatuh tempo
        $this->assertFalse($d['rentalExpiring']->contains('status', 'returned'));
        $this->assertSame(Asset::active()->count(), $d['total']);
        $this->assertLessThan(Asset::count(), $d['total']);                            // disposed + returned tidak dihitung
        $this->get('/admin')->assertOk()->assertSee('Sewa aset segera berakhir')->assertSee('sewa');
    }

    public function test_rental_report_and_asset_value_report(): void
    {
        $this->as($this->admin());
        [$title, $head, $rows] = ReportCatalog::build('rentals', []);
        $this->assertSame('Aset Sewa', $title);
        $this->assertCount(Asset::where('ownership', 'rental')->count(), $rows);
        foreach ($rows as $r) $this->assertCount(count($head), $r);
        $overdue = collect($rows)->first(fn ($r) => is_int($r[7]) && $r[7] < 0);
        $this->assertNotNull($overdue, 'sewa lewat jatuh tempo muncul dengan sisa hari negatif');
        $ret = collect($rows)->firstWhere(10, 'Dikembalikan ke Vendor');
        $this->assertSame('-', $ret[7]);
        $this->get('/reports/rentals.xlsx')->assertOk();
        $this->get('/reports/rentals.pdf')->assertOk();

        // nilai aset: hanya aset milik sendiri yang aktif; sewa ditampilkan sebagai biaya per bulan
        $value = ReportCatalog::build('asset_value', [])[2];
        $total = collect($value)->first(fn ($r) => str_starts_with($r[0], 'TOTAL'));
        $this->assertSame(Asset::active()->where('ownership', 'owned')->count(), $total[1]);
        $sewa = collect($value)->first(fn ($r) => str_starts_with($r[0], 'ASET SEWA'));
        $this->assertSame(Asset::active()->where('ownership', 'rental')->count(), $sewa[1]);

        // daftar aset memuat kolom kepemilikan
        $assets = ReportCatalog::build('assets', ['status' => 'returned']);
        $this->assertSame('Kepemilikan', end($assets[1]));
        $this->assertSame('Sewa (Rental)', end($assets[2][0]));
    }

    public function test_rental_expiry_notifications_are_sent_to_admin_and_staff(): void
    {
        $a = $this->rental('free');                                                    // printer: sewa berakhir sebentar lagi
        foreach ([30, 7, 1] as $days) {
            $a->update(['rental_end' => now()->addDays($days)->toDateString()]);
            $this->artisan('inventory:notify', ['kind' => 'daily'])->assertSuccessful();
            foreach ([$this->staff(), $this->admin()] as $u) {
                $this->assertTrue($u->notifications()->where('data->title', "Sewa aset berakhir {$days} hari lagi")->exists(), "{$days} hari → {$u->email}");
            }
        }
        $this->assertTrue($this->staff()->notifications()->where('data->title', 'like', 'Sewa aset lewat jatuh tempo%')->exists());   // dari data demo (HP EliteBook)
        $this->assertFalse($this->plain()->notifications()->where('data->title', 'like', 'Sewa aset%')->exists());
    }

    public function test_import_accepts_rental_rows_and_rejects_incomplete_ones(): void
    {
        $this->as($this->staff());
        $csv = "kategori,merek,model,serial_number,tanggal_beli,harga,garansi_sampai,lokasi,vendor,kepemilikan,no_kontrak,sewa_mulai,sewa_berakhir,biaya_sewa_bulanan\n"
            . "Laptop,Dell,Sewa Import,IMP-RENT-1,,0,,Kantor Pusat,PT Rental Teknologi Nusantara,sewa,KTR-IMP-1,2026-01-01,2027-12-31,700000\n"
            . "Laptop,Dell,Sewa Tanpa Tanggal,IMP-RENT-2,,0,,Kantor Pusat,,sewa,KTR-IMP-2,,,700000\n"
            . "Laptop,Dell,Milik Import,IMP-OWN-1,2026-01-01,9000000,2029-01-01,Kantor Pusat,,milik,,,,\n";
        $t = Livewire::test(ImportAssets::class)->set('file', UploadedFile::fake()->createWithContent('sewa.csv', $csv))->call('import');
        $this->assertSame(2, $t->get('imported'));
        $this->assertCount(1, $t->get('errors_list'));
        $r = Asset::where('serial_number', 'IMP-RENT-1')->firstOrFail();
        $this->assertSame('rental', $r->ownership);
        $this->assertSame('KTR-IMP-1', $r->rental_contract_no);
        $this->assertSame('2027-12-31', $r->rental_end->toDateString());
        $this->assertEquals(700000, (float) $r->rental_monthly_cost);
        $this->assertSame('owned', Asset::where('serial_number', 'IMP-OWN-1')->value('ownership'));
        $this->assertFalse(Asset::where('serial_number', 'IMP-RENT-2')->exists());
        $this->get(route('template.assets'))->assertSee('kepemilikan')->assertSee('sewa_berakhir');
    }

    public function test_opname_and_pickers_ignore_returned_assets_but_include_active_rentals(): void
    {
        $this->as($this->staff());
        $returned = $this->rental('returned');
        $this->assertTrue(Asset::active()->where('ownership', 'rental')->exists());
        $this->assertFalse(Asset::active()->whereKey($returned->id)->exists());
        $op = \App\Models\StockOpname::create(['location_id' => $returned->location_id, 'started_at' => now(), 'officer_id' => auth()->id(), 'status' => 'running']);
        Livewire::withQueryParams(['opname' => $op->id])->test(\App\Filament\Pages\OpnameSession::class)->call('finish');
        $this->assertFalse($op->items()->where('asset_id', $returned->id)->exists());   // aset yang sudah dikembalikan tidak ditandai hilang
    }

    public function test_management_can_view_rentals_but_not_modify(): void
    {
        $a = $this->rental('held');
        $this->as($this->mgmt())->get(\App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $a]))->assertOk();
        Livewire::test(ViewAsset::class, ['record' => $a->getKey()])->assertActionHidden('return_vendor');
        $this->as($this->mgmt())->get(\App\Filament\Resources\Assets\AssetResource::getUrl('edit', ['record' => $a]))->assertForbidden();
    }
}
