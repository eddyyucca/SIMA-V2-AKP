<?php

namespace Tests\Qa;

use App\Filament\Pages\MyItems;
use App\Filament\Pages\Offboarding;
use App\Filament\Pages\OpnameSession;
use App\Filament\Resources\Assets\Pages\EditAsset;
use App\Filament\Resources\Disposals\Pages\ManageDisposals;
use App\Filament\Resources\Repairs\Pages\ManageRepairs;
use App\Filament\Resources\StockOpnames\Pages\ManageStockOpnames;
use App\Models\Accessory;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Disposal;
use App\Models\Employee;
use App\Models\License;
use App\Models\LicenseSeat;
use App\Models\Repair;
use App\Models\StockOpname;
use App\Models\User;
use App\Reports\ReportCatalog;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\QaTestCase;

class Qa04_LifecycleAndReportsTest extends QaTestCase
{
    // ------------------------------------------------------------ perbaikan
    public function test_repair_lifecycle_updates_asset_status_both_ways(): void
    {
        $this->as($this->staff());
        $a = $this->held();
        $holder = $a->employee;

        Livewire::test(ManageRepairs::class)->callAction('create', ['asset_id' => $a->id, 'reported_by' => $holder->id, 'issue' => 'Layar pecah'])->assertHasNoActionErrors();
        $r = Repair::where('asset_id', $a->id)->latest('id')->first();
        $this->assertSame('repair', $a->fresh()->status);                    // status → Dalam Perbaikan
        $this->assertSame('deployed', $a->fresh()->status_before_repair);
        $this->assertSame('reported', $r->status);

        Livewire::test(ManageRepairs::class)->callAction(TestAction::make('process')->table($r))->assertHasNoActionErrors();
        $this->assertSame('in_progress', $r->fresh()->status);

        Livewire::test(ManageRepairs::class)->callAction(TestAction::make('finish')->table($r), ['action' => 'Ganti LCD', 'cost' => 1250000, 'technician_vendor' => 'Service Center', 'warranty_claim' => true])->assertHasNoActionErrors();
        $r->refresh();
        $this->assertSame('done', $r->status);
        $this->assertNotNull($r->finished_at);
        $this->assertTrue($r->warranty_claim);
        $this->assertEquals(1250000, $r->cost);
        $this->assertSame('deployed', $a->fresh()->status);                  // kembali ke status sebelumnya
        $this->assertSame($holder->id, $a->fresh()->employee_id);
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_two_open_repairs_keep_asset_in_repair_until_last_is_done(): void
    {
        $this->as($this->staff());
        $a = $this->freeAsset();
        $svc = app(\App\Services\AssetService::class);
        $r1 = $svc->reportDamage($a, null, 'Masalah 1');
        $r2 = $svc->reportDamage($a->fresh(), null, 'Masalah 2');
        $svc->finishRepair($r1, ['action' => 'ok']);
        $this->assertSame('repair', $a->fresh()->status);
        $svc->finishRepair($r2, ['action' => 'ok']);
        $this->assertSame('available', $a->fresh()->status);
    }

    public function test_employee_reports_damage_from_my_items_and_staff_is_notified(): void
    {
        $andi = $this->emp('Andi Saputra');
        $mine = Asset::where('employee_id', $andi->id)->where('status', 'deployed')->firstOrFail();
        $this->as($this->plain());
        $before = $this->staff()->notifications()->count();
        Livewire::test(MyItems::class)->assertCanSeeTableRecords(Asset::where('employee_id', $andi->id)->get())
            ->callAction(TestAction::make('report')->table($mine), ['issue' => 'Keyboard error'])->assertHasNoActionErrors();
        $this->assertSame('repair', $mine->fresh()->status);
        $rep = Repair::where('asset_id', $mine->id)->latest('id')->first();
        $this->assertSame($andi->id, $rep->reported_by);
        $this->assertGreaterThan($before, $this->staff()->notifications()->count());

        // staff melihat laporan di menu Perbaikan lalu menindaklanjuti
        $this->as($this->staff());
        Livewire::test(ManageRepairs::class)->assertCanSeeTableRecords([$rep]);
        // karyawan tidak melihat barang orang lain
        $this->as($this->plain());
        Livewire::test(MyItems::class)->assertCanNotSeeTableRecords(Asset::where('employee_id', '!=', $andi->id)->limit(5)->get());
    }

    // ------------------------------------------------------------ disposal
    public function test_disposal_requires_wipe_and_staff_cannot_approve(): void
    {
        $this->as($this->staff());
        $a = $this->freeAsset();
        Livewire::test(ManageDisposals::class)->callAction('create', ['asset_id' => $a->id, 'reason' => 'Rusak', 'data_wiped' => false])->assertHasActionErrors(['data_wiped']);
        $this->assertSame(0, Disposal::where('asset_id', $a->id)->count());

        $notifBefore = $this->admin()->notifications()->count();
        Livewire::test(ManageDisposals::class)->callAction('create', ['asset_id' => $a->id, 'reason' => 'Rusak berat', 'data_wiped' => true])->assertHasNoActionErrors();
        $d = Disposal::where('asset_id', $a->id)->firstOrFail();
        $this->assertSame('pending', $d->status);
        $this->assertGreaterThan($notifBefore, $this->admin()->notifications()->count(), 'Admin diberi tahu');
        $this->assertSame('available', $a->fresh()->status);                 // belum berubah sebelum approval

        Livewire::test(ManageDisposals::class)->assertActionHidden(TestAction::make('approve')->table($d))->assertActionHidden(TestAction::make('reject')->table($d));

        // pengajuan ganda untuk aset yang sama ditolak
        $this->expectException(\InvalidArgumentException::class);
        app(\App\Services\DisposalService::class)->request($a->id, 'Dobel', true);
    }

    public function test_management_approval_marks_disposed_revokes_seats_and_keeps_data(): void
    {
        $this->as($this->staff());
        $a = Asset::where('model', 'ProDesk 400 G9')->firstOrFail();           // punya seat lisensi (Windows)
        $this->assertGreaterThan(0, LicenseSeat::where('asset_id', $a->id)->whereNull('revoked_at')->count());
        $d = app(\App\Services\DisposalService::class)->request($a->id, 'Diganti unit baru', true);
        $this->assertSame($d->id, Disposal::latest('id')->value('id'));

        $this->as($this->mgmt());
        Livewire::test(ManageDisposals::class)->callAction(TestAction::make('approve')->table($d), ['method' => 'donate'])->assertHasNoActionErrors();
        $d->refresh();
        $this->assertSame('approved', $d->status);
        $this->assertSame('donate', $d->method);
        $this->assertSame($this->mgmt()->id, $d->approved_by);
        $this->assertNotNull($d->approved_at);
        $a->refresh();
        $this->assertSame('disposed', $a->status);                           // status Disposed
        $this->assertNull($a->employee_id);
        $this->assertNull($a->deleted_at);                                    // data TIDAK dihapus
        $this->assertSame(0, LicenseSeat::where('asset_id', $a->id)->whereNull('revoked_at')->count());   // seat dilepas
        $this->get(route('disposal.ba', $d))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('Donasi', \Illuminate\Support\Facades\Blade::render('{{ \App\Models\Disposal::METHODS["donate"] }}'));

        // tidak bisa diproses dua kali
        $this->expectException(\InvalidArgumentException::class);
        app(\App\Services\DisposalService::class)->approve($d->fresh(), 'sell');
    }

    public function test_disposal_reject_keeps_asset_active(): void
    {
        $pending = Disposal::where('status', 'pending')->firstOrFail();        // dari data demo
        $this->as($this->admin());
        Livewire::test(ManageDisposals::class)->callAction(TestAction::make('reject')->table($pending))->assertHasNoActionErrors();
        $this->assertSame('rejected', $pending->fresh()->status);
        $this->assertNotSame('disposed', $pending->asset->fresh()->status);
    }

    public function test_asset_can_be_soft_deleted_only_by_admin_and_never_force_deleted(): void
    {
        $a = Asset::where('status', 'available')->firstOrFail();
        $this->as($this->staff());
        Livewire::test(EditAsset::class, ['record' => $a->getKey()])->assertActionHidden('delete');
        $this->as($this->admin());
        Livewire::test(EditAsset::class, ['record' => $a->getKey()])->callAction('delete')->assertHasNoActionErrors();
        $this->assertSoftDeleted($a);
        $this->assertFalse($this->admin()->can('forceDelete', $a));
    }

    // ------------------------------------------------------------ stock opname
    public function test_stock_opname_session_found_wrong_location_missing_and_report(): void
    {
        $this->as($this->staff());
        $loc = \App\Models\Location::where('name', 'Cabang Palu')->firstOrFail();
        Livewire::test(ManageStockOpnames::class)->callAction('create', ['location_id' => $loc->id])->assertHasNoActionErrors();
        $op = StockOpname::latest('id')->firstOrFail();
        $this->assertSame('running', $op->status);
        $this->assertSame($this->staff()->id, $op->officer_id);

        $inLoc = Asset::where('location_id', $loc->id)->active()->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(3, $inLoc->count());
        $stray = Asset::where('location_id', '!=', $loc->id)->active()->first();
        $page = Livewire::withQueryParams(['opname' => $op->id])->test(OpnameSession::class);
        $page->call('onScan', $inLoc[0]->asset_tag)->call('onScan', $inLoc[1]->serial_number)->call('onScan', $stray->asset_tag)
            ->call('onScan', $inLoc[0]->asset_tag)                                  // scan ganda tidak menggandakan item
            ->call('onScan', 'KODE-NGAWUR')->assertNotified();
        $this->assertSame(3, $op->items()->count());
        $page->call('finish');

        $res = $op->fresh()->items()->pluck('result', 'asset_id');
        $this->assertSame('found', $res[$inLoc[0]->id]);
        $this->assertSame('found', $res[$inLoc[1]->id]);
        $this->assertSame('wrong_location', $res[$stray->id]);
        foreach ($inLoc->skip(2) as $a) $this->assertSame('missing', $res[$a->id], "{$a->asset_tag} harus Tidak Ditemukan");
        $this->assertSame('finished', $op->fresh()->status);
        $this->assertNotNull($op->fresh()->finished_at);

        // sesi ditutup: scan baru tidak mengubah apa pun
        Livewire::withQueryParams(['opname' => $op->id])->test(OpnameSession::class)->call('onScan', $inLoc[2]->asset_tag)->assertNotified();
        $this->assertSame('missing', $op->fresh()->items()->where('asset_id', $inLoc[2]->id)->value('result'));

        $rows = ReportCatalog::build('opname', ['opname' => $op->id])[2];
        $this->assertCount($op->items()->count(), $rows);
        $this->get(route('reports.download', ['key' => 'opname', 'format' => 'xlsx', 'opname' => $op->id]))->assertOk();
        $this->get(route('reports.download', ['key' => 'opname', 'format' => 'pdf', 'opname' => $op->id]))->assertOk();
    }

    // ------------------------------------------------------------ offboarding
    public function test_offboarding_returns_assets_accessories_and_licenses_in_one_go(): void
    {
        $this->as($this->staff());
        $e = $this->emp('Siti Rahma');
        $assets = Asset::where('employee_id', $e->id)->get();
        $acc = $e->heldAccessories();
        $seats = $e->licenseSeats()->count();
        $this->assertGreaterThan(0, $assets->count()); $this->assertGreaterThan(0, $acc->count()); $this->assertGreaterThan(0, $seats);
        $bast = AssetAssignment::count();
        $accAvail = $acc->mapWithKeys(fn ($h) => [$h['accessory']->id => $h['accessory']->fresh()->qty_available]);
        $licUsed = License::all()->mapWithKeys(fn ($l) => [$l->id => $l->seats_used]);

        Livewire::withQueryParams(['employee' => $e->id])->test(Offboarding::class)->call('process');

        $e->refresh();
        $this->assertSame('resigned', $e->status);
        $this->assertFalse($e->holdsItems());
        $this->assertSame(0, Asset::where('employee_id', $e->id)->count());
        $this->assertSame($bast + $assets->count(), AssetAssignment::count());      // satu BAST pengembalian per aset
        $this->assertSame($assets->count(), AssetAssignment::where('type', 'checkin')->where('from_employee_id', $e->id)->where('bast_pdf_path', '!=', null)->count() - AssetAssignment::where('type', 'checkin')->where('from_employee_id', $e->id)->where('assigned_at', '<', now()->subMinute())->count());
        foreach ($assets as $a) $this->assertSame('available', $a->fresh()->status);
        foreach ($acc as $h) $this->assertSame($accAvail[$h['accessory']->id] + $h['qty'], $h['accessory']->fresh()->qty_available);
        foreach (License::all() as $l) $this->assertLessThanOrEqual($licUsed[$l->id], $l->seats_used);
        $this->assertSame(0, $e->licenseSeats()->count());

        // karyawan resign tidak bisa menerima lagi (terhubung ke fitur serah terima)
        $this->expectException(\InvalidArgumentException::class);
        app(\App\Services\AssetService::class)->checkout($this->freeAsset(), $e);
    }

    public function test_partial_offboarding_flags_unreturned_items_in_reports_and_dashboard(): void
    {
        $this->as($this->staff());
        $e = $this->emp('Wahyu Nugroho');
        $keep = Asset::where('employee_id', $e->id)->count();
        $this->assertGreaterThan(0, $keep);
        $page = Livewire::withQueryParams(['employee' => $e->id])->test(Offboarding::class);
        foreach (array_keys($page->get('returnAsset')) as $id) $page->set("returnAsset.$id", false);      // tidak ada aset yang dikembalikan
        $page->call('process');
        $e->refresh();
        $this->assertSame('resigned', $e->status);
        $this->assertTrue($e->holdsItems());
        $rows = collect(ReportCatalog::build('employee_items', [])[2])->where(1, 'Wahyu Nugroho');
        $this->assertNotEmpty($rows);
        foreach ($rows as $r) $this->assertSame('RESIGNED', $r[4]);                     // ditandai di laporan
        $d = Livewire::test(\App\Filament\Pages\Dashboard::class)->instance()->data();
        $this->assertTrue($d['resignedHolding']->contains('id', $e->id));              // dan di dashboard
        $this->artisan('inventory:notify', ['kind' => 'daily'])->assertSuccessful();
        $this->assertTrue($this->staff()->notifications()->where('data->title', 'Karyawan resign masih memegang barang')->exists());
    }

    // ------------------------------------------------------------ laporan
    public function test_all_reports_have_data_and_filters_work(): void
    {
        $this->as($this->admin());
        foreach (ReportCatalog::titles() as $key => $info) {
            [$title, $head, $rows] = ReportCatalog::build($key, []);
            $this->assertNotEmpty($head, $key);
            $this->assertNotEmpty($rows, "laporan {$key} kosong padahal data demo lengkap");
            foreach ($rows as $row) $this->assertCount(count($head), $row, "{$key}: jumlah kolom tidak sama dengan header");
            $this->get("/reports/{$key}.xlsx")->assertOk();
            $this->get("/reports/{$key}.pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        }
        $all = count(ReportCatalog::build('assets', [])[2]);
        $lpt = \App\Models\Category::where('code_prefix', 'NB')->first();
        $this->assertSame(Asset::where('category_id', $lpt->id)->count(), count(ReportCatalog::build('assets', ['category_id' => $lpt->id])[2]));
        $this->assertSame(Asset::where('status', 'repair')->count(), count(ReportCatalog::build('assets', ['status' => 'repair'])[2]));
        $this->assertSame($all, Asset::count());

        $low = ReportCatalog::build('stock', ['low' => 1])[2];
        $full = ReportCatalog::build('stock', [])[2];
        $this->assertLessThan(count($full), count($low));
        $this->assertGreaterThan(0, count($low));
        foreach ($low as $r) $this->assertSame('BELI', $r[5]);                          // kolom ke-6 tetap "Catatan"                          // daftar belanja

        $lic = collect(ReportCatalog::build('licenses', [])[2])->firstWhere(0, 'Zoom Pro');
        $this->assertSame([2, 3, 0], [$lic[2], $lic[3], $lic[4]]);                       // seat, terpakai, sisa
        $this->assertSame(Asset::where('purchase_price', '>', 0)->where('status', '!=', 'disposed')->sum('purchase_price') > 0, true);

        $usage = ReportCatalog::build('consumable_usage', ['from' => now()->subDays(30)->toDateString()])[2];
        $usageAll = ReportCatalog::build('consumable_usage', [])[2];
        $this->assertLessThan(count($usageAll), count($usage));
        $this->assertGreaterThan(0, count($usage));
    }

    public function test_reports_are_forbidden_for_employees_and_open_for_management(): void
    {
        $this->as($this->plain())->get('/reports/assets.xlsx')->assertForbidden();
        $this->as($this->mgmt())->get('/reports/assets.xlsx')->assertOk();
        $this->as($this->staff())->get('/reports/repairs.pdf')->assertOk();
    }

    // ------------------------------------------------------------ notifikasi terjadwal
    public function test_scheduled_notifications_reach_the_right_roles(): void
    {
        $this->artisan('inventory:notify', ['kind' => 'daily'])->assertSuccessful();
        $this->artisan('inventory:notify', ['kind' => 'weekly'])->assertSuccessful();
        $this->artisan('inventory:notify', ['kind' => 'monthly'])->assertSuccessful();
        $titles = fn (User $u) => $u->notifications()->pluck('data')->map(fn ($d) => $d['title'] ?? '')->all();
        $staff = $titles($this->staff());
        $admin = $titles($this->admin());

        $this->assertNotEmpty(preg_grep('/Rekap stok/', $staff), 'rekap stok ≤ minimum ke Staff IT');
        $this->assertNotEmpty(preg_grep('/Lisensi expired 7 hari/', $staff));
        $this->assertNotEmpty(preg_grep('/Lisensi expired 1 hari/', $staff));
        $this->assertNotEmpty(preg_grep('/Lisensi expired 7 hari/', $admin), 'lisensi juga ke Admin');
        $this->assertNotEmpty(preg_grep('/Garansi aset habis/', $staff), 'garansi mingguan ke Staff IT');
        $this->assertNotEmpty(preg_grep('/Perbaikan > 14 hari/', $staff));
        $this->assertNotEmpty(preg_grep('/Ringkasan bulanan/', $admin));
        $this->assertEmpty(preg_grep('/Rekap|Lisensi|Garansi|Perbaikan >|Ringkasan/', $titles($this->plain())), 'Karyawan tidak menerima notifikasi stok/lisensi/ringkasan');
    }

    public function test_schedule_is_registered_at_0800_wita(): void
    {
        $this->assertSame('Asia/Makassar', config('app.timezone'));
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->command . ' @ ' . $e->expression);
        $this->assertTrue($events->contains(fn ($s) => str_contains($s, 'inventory:notify daily') && str_contains($s, '0 8 * * *')));
        $this->assertTrue($events->contains(fn ($s) => str_contains($s, 'inventory:notify weekly') && str_contains($s, '0 8 * * 1')));
        $this->assertTrue($events->contains(fn ($s) => str_contains($s, 'inventory:notify monthly') && str_contains($s, '0 8 1 * *')));
    }
}
