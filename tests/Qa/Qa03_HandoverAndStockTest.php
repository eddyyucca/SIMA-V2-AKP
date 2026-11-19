<?php

namespace Tests\Qa;

use App\Filament\Resources\Accessories\Pages\ManageAccessories;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Filament\Resources\Components\Pages\ManageComponents;
use App\Filament\Resources\Consumables\Pages\ManageConsumables;
use App\Filament\Resources\Licenses\Pages\ManageLicenses;
use App\Mail\BastMail;
use App\Models\Accessory;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\Department;
use App\Models\Employee;
use App\Models\License;
use App\Models\LicenseSeat;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\QaTestCase;

class Qa03_HandoverAndStockTest extends QaTestCase
{
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private function viewAsset(Asset $a)
    {
        return Livewire::test(ViewAsset::class, ['record' => $a->getKey()]);
    }

    // ------------------------------------------------------------ serah terima
    public function test_checkout_with_signatures_creates_bast_email_notification_and_updates_everything(): void
    {
        Mail::fake();
        $this->as($this->staff());
        $a = $this->freeAsset();
        $andi = $this->emp('Andi Saputra');                     // punya akun login
        $before = AssetAssignment::count();

        $this->viewAsset($a)->assertActionVisible('checkout')->assertActionHidden('checkin')->assertActionHidden('transfer')
            ->callAction('checkout', ['employee_id' => $andi->id, 'condition' => 'good', 'notes' => 'QA', 'signature_receiver' => self::PNG, 'signature_it' => self::PNG])
            ->assertHasNoActionErrors();

        $a->refresh();
        $this->assertSame('deployed', $a->status);                                  // status → Dipakai
        $this->assertSame($andi->id, $a->employee_id);                              // pemegang terisi
        $row = $a->assignments()->first();
        $this->assertSame($before + 1, AssetAssignment::count());
        $this->assertSame('checkout', $row->type);
        $this->assertTrue(\Storage::disk('local')->exists($row->bast_pdf_path));    // BAST PDF
        $this->assertTrue(\Storage::disk('local')->exists($row->signature_receiver_path));
        $this->assertTrue(\Storage::disk('local')->exists($row->signature_it_path));
        Mail::assertQueued(BastMail::class, fn ($m) => $m->hasTo($andi->email));    // email ke penerima
        $this->assertTrue($this->plain()->notifications()->where('data->title', 'like', '%' . $row->bast_number . '%')->exists(), 'notifikasi in-app BAST ke karyawan');

        // setelah dipakai: Checkout hilang, Checkin/Mutasi muncul
        $this->viewAsset($a)->assertActionHidden('checkout')->assertActionVisible('checkin')->assertActionVisible('transfer');
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_borrow_transfer_and_checkin_update_status_location_and_holder(): void
    {
        $this->as($this->staff());
        $a = $this->freeAsset();
        [$x, $y] = [$this->emp('Fajar Pratama'), $this->emp('Maya Sari')];
        $palu = Location::where('name', 'Cabang Palu')->first();

        $this->viewAsset($a)->callAction('checkout', ['employee_id' => $x->id, 'condition' => 'good', 'borrowed' => true])->assertHasNoActionErrors();
        $this->assertSame('borrowed', $a->fresh()->status);                         // Dipinjam

        $this->viewAsset($a)->callAction('transfer', ['employee_id' => $y->id, 'condition' => 'good'])->assertHasNoActionErrors();
        $a->refresh();
        $this->assertSame($y->id, $a->employee_id);
        $t = $a->assignments()->first();
        $this->assertSame('transfer', $t->type);
        $this->assertSame($x->id, $t->from_employee_id);                            // dari → ke tercatat
        $this->assertSame($y->id, $t->to_employee_id);
        $this->assertNotNull($t->bast_pdf_path);                                    // setiap mutasi = BAST baru

        $this->viewAsset($a)->callAction('checkin', ['condition' => 'minor_damage', 'location_id' => $palu->id])->assertHasNoActionErrors();
        $a->refresh();
        $this->assertSame('available', $a->status);
        $this->assertNull($a->employee_id);
        $this->assertSame('minor_damage', $a->condition);
        $this->assertSame($palu->id, $a->location_id);                              // lokasi ikut berubah
        $this->assertSame(3, $a->assignments()->count());
        $this->assertCount(3, array_unique($a->assignments()->pluck('bast_number')->all()));
    }

    public function test_resigned_employee_cannot_receive_anything(): void
    {
        $this->as($this->staff());
        $dedi = $this->emp('Dedi Kurniawan');
        $this->assertSame('resigned', $dedi->status);
        $a = $this->freeAsset();
        $this->viewAsset($a)->callAction('checkout', ['employee_id' => $dedi->id, 'condition' => 'good']);
        $this->assertSame('available', $a->fresh()->status);

        $acc = Accessory::first();
        $avail = $acc->qty_available;
        Livewire::test(ManageAccessories::class)->callAction(TestAction::make('checkout')->table($acc), ['employee_id' => $dedi->id, 'qty' => 1]);
        $this->assertSame($avail, $acc->fresh()->qty_available);

        $lic = License::where('name', 'Windows 11 Pro (Volume)')->first();
        $seats = $lic->seats_used;
        Livewire::test(ManageLicenses::class)->callAction(TestAction::make('assign')->table($lic), ['employee_id' => $dedi->id]);
        $this->assertSame($seats, $lic->fresh()->seats_used);
        $this->expectException(\InvalidArgumentException::class);
        app(\App\Services\LicenseService::class)->assign($lic, $dedi);
    }

    public function test_disposed_lost_and_repair_assets_follow_the_rules(): void
    {
        $this->as($this->staff());
        $disposed = Asset::where('status', 'disposed')->firstOrFail();
        $this->viewAsset($disposed)->assertActionHidden('checkout')->assertActionHidden('checkin');
        $this->assertNull($disposed->fresh()->employee_id);
        try {
            app(\App\Services\AssetService::class)->checkout($disposed, $this->emp('Andi Saputra'));
            $this->fail('aset disposed tidak boleh di-checkout');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('disposed', $e->getMessage());
        }
        $repair = Asset::where('status', 'repair')->whereNotNull('employee_id')->firstOrFail();
        $this->expectException(\InvalidArgumentException::class);
        app(\App\Services\AssetService::class)->transfer($repair, $this->emp('Nur Aini'));
    }

    public function test_bast_download_permissions(): void
    {
        $andi = $this->emp('Andi Saputra');
        $own = AssetAssignment::where('to_employee_id', $andi->id)->orWhere('from_employee_id', $andi->id)->firstOrFail();
        $other = AssetAssignment::where('to_employee_id', '!=', $andi->id)->where(fn ($q) => $q->where('from_employee_id', '!=', $andi->id)->orWhereNull('from_employee_id'))->firstOrFail();

        $this->as($this->plain())->get(route('bast.download', $own))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->as($this->plain())->get(route('bast.download', $other))->assertForbidden();   // karyawan hanya BAST miliknya
        $this->as($this->staff())->get(route('bast.download', $other))->assertOk();
        $this->as($this->mgmt())->get(route('bast.download', $other))->assertOk();
    }

    // ------------------------------------------------------------ accessories
    public function test_accessory_stock_in_checkout_checkin_and_holders(): void
    {
        $this->as($this->staff());
        $acc = Accessory::where('name', 'like', 'Webcam%')->firstOrFail();
        $e = $this->emp('Rina Wulandari');
        [$total, $avail] = [$acc->qty_total, $acc->qty_available];

        Livewire::test(ManageAccessories::class)->callAction(TestAction::make('stock_in')->table($acc), ['qty' => 5, 'notes' => 'PO-QA', 'vendor_id' => \App\Models\Vendor::first()->id, 'unit_price' => 100000, 'received_at' => now()->toDateString()])->assertHasNoActionErrors();
        $acc->refresh();
        $this->assertSame([$total + 5, $avail + 5], [$acc->qty_total, $acc->qty_available]);

        Livewire::test(ManageAccessories::class)->callAction(TestAction::make('checkout')->table($acc), ['employee_id' => $e->id, 'qty' => 2])->assertHasNoActionErrors();
        $acc->refresh();
        $this->assertSame([$total + 5, $avail + 3], [$acc->qty_total, $acc->qty_available]);
        $this->assertSame(2, collect($e->heldAccessories())->firstWhere(fn ($h) => $h['accessory']->id === $acc->id)['qty']);

        // melebihi stok tersedia → ditolak
        Livewire::test(ManageAccessories::class)->callAction(TestAction::make('checkout')->table($acc), ['employee_id' => $e->id, 'qty' => $acc->qty_available + 1])->assertNotifiedFailure();
        $this->assertSame($avail + 3, $acc->fresh()->qty_available);

        // terima kembali lebih dari yang dipegang → ditolak
        Livewire::test(ManageAccessories::class)->callAction(TestAction::make('checkin')->table($acc), ['employee_id' => $e->id, 'qty' => 3, 'condition' => 'good'])->assertNotifiedFailure();
        Livewire::test(ManageAccessories::class)->callAction(TestAction::make('checkin')->table($acc), ['employee_id' => $e->id, 'qty' => 1, 'condition' => 'minor_damage'])->assertHasNoActionErrors();
        $this->assertSame($avail + 4, $acc->fresh()->qty_available);
        $ret = $acc->movements()->where('type', 'return')->latest('id')->first();
        $this->assertSame('minor_damage', $ret->condition);                           // kondisi saat kembali tercatat

        Livewire::test(ManageAccessories::class)->mountAction(TestAction::make('holders')->table($acc))->assertHasNoErrors();
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_accessory_low_stock_filter_and_notification(): void
    {
        $this->as($this->staff());
        $acc = Accessory::where('name', 'like', 'Charger Laptop%')->firstOrFail();      // stok tipis dari data demo
        $e = $this->emp('Rina Wulandari');
        $this->assertTrue($acc->isLow() || $acc->qty_available - $acc->min_qty <= 3);
        $before = $this->staff()->notifications()->count();
        $need = max(1, $acc->qty_available - $acc->min_qty);
        Livewire::test(ManageAccessories::class)->callAction(TestAction::make('checkout')->table($acc), ['employee_id' => $e->id, 'qty' => $need])->assertHasNoActionErrors();
        $this->assertTrue($acc->fresh()->isLow());
        $this->assertGreaterThan($before, $this->staff()->notifications()->count(), 'notifikasi stok minimum ke Staff IT');
        Livewire::test(ManageAccessories::class)->filterTable('low')->assertCanSeeTableRecords($acc->fresh()->newQuery()->whereKey($acc->id)->get());
    }

    // ------------------------------------------------------------ consumables
    public function test_consumable_usage_by_employee_department_and_device_and_overdraw(): void
    {
        $this->as($this->staff());
        $c = Consumable::where('name', 'like', 'Toner HP 58A%')->firstOrFail();
        $start = (float) $c->qty;
        $printer = Asset::where('model', 'LaserJet Pro M404dn')->firstOrFail();
        $dept = Department::where('name', 'Finance')->first();
        $emp = $this->emp('Siti Rahma');

        Livewire::test(ManageConsumables::class)->callAction(TestAction::make('stock_out')->table($c), ['qty' => 1, 'target' => 'asset', 'asset_id' => $printer->id, 'notes' => 'ganti toner'])->assertHasNoActionErrors();
        $this->assertSame($printer->id, $c->movements()->where('type', 'out')->latest('id')->first()->asset_id);
        Livewire::test(ManageConsumables::class)->callAction(TestAction::make('stock_in')->table($c), ['qty' => 4, 'vendor_id' => \App\Models\Vendor::first()->id, 'unit_price' => 800000, 'received_at' => now()->toDateString()])->assertHasNoActionErrors();
        Livewire::test(ManageConsumables::class)->callAction(TestAction::make('stock_out')->table($c), ['qty' => 1, 'target' => 'department', 'department_id' => $dept->id])->assertHasNoActionErrors();
        Livewire::test(ManageConsumables::class)->callAction(TestAction::make('stock_out')->table($c), ['qty' => 1, 'target' => 'employee', 'employee_id' => $emp->id])->assertHasNoActionErrors();
        $this->assertEquals($start - 1 + 4 - 1 - 1, (float) $c->fresh()->qty);

        Livewire::test(ManageConsumables::class)->callAction(TestAction::make('stock_out')->table($c), ['qty' => 999, 'target' => 'employee', 'employee_id' => $emp->id])->assertNotifiedFailure();
        $this->assertEquals($start + 1, (float) $c->fresh()->qty);                      // tidak berubah

        // pemakaian muncul di detail aset printer (terhubung antar fitur)
        $this->get(\App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $printer]))->assertOk()->assertSee('Toner HP 58A');
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    // ------------------------------------------------------------ komponen
    public function test_component_install_uninstall_flow_and_asset_detail(): void
    {
        $this->as($this->staff());
        $c = Component::where('name', 'SSD SATA 480GB')->firstOrFail();
        $a = Asset::where('model', 'ProDesk 400 G9')->firstOrFail();
        $avail = $c->qty_available;

        Livewire::test(ManageComponents::class)->callAction(TestAction::make('install')->table($c), ['asset_id' => $a->id, 'qty' => 1, 'reason' => 'Upgrade'])->assertHasNoActionErrors();
        $this->assertSame($avail - 1, $c->fresh()->qty_available);
        $this->assertStringContainsString('Upgrade: pasang SSD SATA 480GB', $a->fresh()->specs);
        $this->assertSame(1.0, $a->fresh()->installedComponents()->firstWhere(fn ($r) => $r['component']->id === $c->id)['qty']);
        $this->get(\App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $a]))->assertOk()->assertSee('SSD SATA 480GB');

        // melepas komponen dari halaman aset → stok kembali
        $this->viewAsset($a)->callAction('uninstall', ['component_id' => $c->id, 'qty' => 1, 'reason' => 'Perbaikan'])->assertHasNoActionErrors();
        $this->assertSame($avail, $c->fresh()->qty_available);
        $this->assertEmpty($a->fresh()->installedComponents()->filter(fn ($r) => $r['component']->id === $c->id));

        // melepas lebih dari yang terpasang, atau memasang melebihi stok → ditolak
        $this->viewAsset($a)->assertActionHidden('uninstall');      // tidak ada komponen terpasang → aksi disembunyikan
        try {
            app(\App\Services\ComponentService::class)->uninstall($c, $a->fresh(), 1, 'Perbaikan');
            $this->fail('melepas komponen yang tidak terpasang harus ditolak');
        } catch (\InvalidArgumentException) {}
        Livewire::test(ManageComponents::class)->callAction(TestAction::make('install')->table($c), ['asset_id' => $a->id, 'qty' => 999, 'reason' => 'Upgrade'])->assertNotifiedFailure();
        $this->assertSame($avail, $c->fresh()->qty_available);

        // ganti komponen lama dengan yang baru dalam satu langkah
        $old = Component::where('name', 'RAM DDR4 8GB')->first();
        $new = Component::where('name', 'RAM DDR4 16GB')->first();
        $laptop = Asset::where('model', 'ThinkPad E14 Gen 5')->orderByDesc('id')->first();       // sudah memakai RAM 16GB dari data demo
        $this->assertNotEmpty($laptop->installedComponents()->filter(fn ($r) => $r['component']->id === $new->id));
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    // ------------------------------------------------------------ lisensi
    public function test_license_seat_allocation_limits_revoke_and_holders(): void
    {
        $this->as($this->staff());
        $lic = License::where('name', 'Adobe Acrobat Pro')->firstOrFail();                // 3 seat, 2 terpakai
        $this->assertSame(1, $lic->seats_free);
        $e1 = $this->emp('Rina Wulandari'); $e2 = $this->emp('Yusuf Maulana');

        Livewire::test(ManageLicenses::class)->callAction(TestAction::make('assign')->table($lic), ['employee_id' => $e1->id])->assertHasNoActionErrors();
        $this->assertSame([3, 0], [$lic->fresh()->seats_used, $lic->fresh()->seats_free]);

        // seat penuh → ditolak
        Livewire::test(ManageLicenses::class)->callAction(TestAction::make('assign')->table($lic), ['employee_id' => $e2->id])->assertNotifiedFailure();
        $this->assertSame(3, $lic->fresh()->seats_used);

        // cabut seat → sisa bertambah → bisa dialokasikan ulang (ke aset)
        $seat = $lic->activeSeats()->where('employee_id', $e1->id)->first();
        Livewire::test(ManageLicenses::class)->callAction(TestAction::make('revoke')->table($lic), ['seat_id' => $seat->id])->assertHasNoActionErrors();
        $this->assertNotNull($seat->fresh()->revoked_at);
        $this->assertSame(1, $lic->fresh()->seats_free);
        $asset = Asset::where('model', 'ProDesk 400 G9')->first();
        Livewire::test(ManageLicenses::class)->callAction(TestAction::make('assign')->table($lic), ['asset_id' => $asset->id])->assertHasNoActionErrors();
        $this->get(\App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $asset]))->assertSee('Adobe Acrobat Pro');   // terlihat di detail aset

        Livewire::test(ManageLicenses::class)->mountAction(TestAction::make('holders')->table($lic))->assertHasNoErrors();
        // seat melebihi (Zoom) tetap terdeteksi peringatan
        $zoom = License::where('name', 'Zoom Pro')->first();
        $this->assertTrue(app(\App\Services\LicenseService::class)->overAllocated($zoom));
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_license_key_is_encrypted_and_expiry_filter_works(): void
    {
        $this->as($this->staff());
        $l = License::where('name', 'Kaspersky Endpoint Security')->first();
        $this->assertSame('KES-DEMO-0001', $l->license_key);
        $this->assertNotSame('KES-DEMO-0001', \DB::table('licenses')->where('id', $l->id)->value('license_key'));
        $soon = License::whereBetween('expires_at', [now()->toDateString(), now()->addDays(30)->toDateString()])->get();
        $this->assertGreaterThanOrEqual(4, $soon->count());       // M365, Kaspersky, domain, VPS
        Livewire::test(ManageLicenses::class)->filterTable('expiring')->assertCanSeeTableRecords($soon)->assertCountTableRecords($soon->count());
    }
}
