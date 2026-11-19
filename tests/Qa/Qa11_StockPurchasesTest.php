<?php

namespace Tests\Qa;

use App\Filament\Resources\Accessories\Pages\ManageAccessories;
use App\Filament\Resources\Components\Pages\ManageComponents;
use App\Filament\Resources\Consumables\Pages\ManageConsumables;
use App\Filament\Resources\StockReceipts\Pages\ManageStockReceipts;
use App\Models\Accessory;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\StockMovement;
use App\Models\StockReceipt;
use App\Models\Vendor;
use App\Reports\ReportCatalog;
use App\Services\StockService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\QaTestCase;

class Qa11_StockPurchasesTest extends QaTestCase
{
    private function vendorA(): Vendor { return Vendor::where('name', 'PT Sumber Teknologi')->firstOrFail(); }
    private function vendorB(): Vendor { return Vendor::where('name', 'Toko Aksesori Prima')->firstOrFail(); }

    private function buy($page, $item, array $d)
    {
        return Livewire::test($page)->callAction(TestAction::make('stock_in')->table($item), $d + ['received_at' => now()->toDateString()]);
    }

    /** Skenario permintaan: 10 unit dari vendor A, seminggu kemudian 15 unit barang yang sama dari vendor B dengan harga berbeda. */
    public function test_same_accessory_bought_from_two_vendors_at_different_prices_keeps_full_history(): void
    {
        $this->as($this->staff());
        Livewire::test(ManageAccessories::class)->callAction('create', ['name' => 'Mouse Wireless Logitech QA', 'qty_total' => 0, 'min_qty' => 3])->assertHasNoActionErrors();
        $mouse = Accessory::where('name', 'Mouse Wireless Logitech QA')->firstOrFail();
        $this->assertSame(0, $mouse->receipts()->count());                            // belum ada pembelian, belum ada stok

        // pembelian 1: 10 unit, vendor A, Rp 165.000, seminggu lalu
        $this->buy(ManageAccessories::class, $mouse, ['vendor_id' => $this->vendorA()->id, 'qty' => 10, 'unit_price' => 165000, 'reference_no' => 'PO-A-001', 'received_at' => now()->subDays(7)->toDateString()])->assertHasNoActionErrors();
        $mouse->refresh();
        $this->assertSame([10, 10], [$mouse->qty_total, $mouse->qty_available]);
        $this->assertSame($this->vendorA()->id, $mouse->vendor_id);
        $this->assertEquals(165000, (float) $mouse->unit_price);

        // pembelian 2: 15 unit, vendor B, Rp 152.000, hari ini
        $this->buy(ManageAccessories::class, $mouse, ['vendor_id' => $this->vendorB()->id, 'qty' => 15, 'unit_price' => 152000, 'reference_no' => 'PO-B-007'])->assertHasNoActionErrors();
        $mouse->refresh();

        // stok bertambah (bukan tertimpa), harga & vendor terakhir ikut pembelian terbaru
        $this->assertSame([25, 25], [$mouse->qty_total, $mouse->qty_available]);
        $this->assertSame($this->vendorB()->id, $mouse->vendor_id);
        $this->assertEquals(152000, (float) $mouse->unit_price);
        // harga rata-rata tertimbang: (10×165.000 + 15×152.000) / 25 = 157.200
        $this->assertEqualsWithDelta(157200.0, $mouse->average_price, 0.01);
        $this->assertEqualsWithDelta(25 * 157200.0, $mouse->stock_value, 0.01);

        // riwayat lengkap: dua pembelian terpisah, masing-masing dengan vendor, harga, tanggal, no. PO
        $r = $mouse->receipts()->orderBy('received_at')->get();
        $this->assertCount(2, $r);
        $this->assertSame([$this->vendorA()->id, $this->vendorB()->id], $r->pluck('vendor_id')->all());
        $this->assertSame([10.0, 15.0], $r->map(fn ($x) => (float) $x->qty)->all());
        $this->assertSame([165000.0, 152000.0], $r->map(fn ($x) => (float) $x->unit_price)->all());
        $this->assertSame(['PO-A-001', 'PO-B-007'], $r->pluck('reference_no')->all());
        $this->assertSame(now()->subDays(7)->toDateString(), $r[0]->received_at->toDateString());
        $this->assertEquals(1650000, $r[0]->total);
        $this->assertEquals(2280000, $r[1]->total);

        // setiap pembelian tertaut ke pergerakan stok "masuk" (riwayat stok ikut ter-update)
        $moves = $mouse->movements()->where('type', 'in')->orderBy('moved_at')->get();
        $this->assertCount(2, $moves);
        foreach ($moves as $i => $m) {
            $this->assertSame($r[$i]->id, $m->receipt->id);
            $this->assertEquals($r[$i]->qty, $m->qty);
        }
        $this->assertSame(now()->subDays(7)->toDateString(), $moves[0]->moved_at->toDateString());     // tanggal pergerakan = tanggal terima

        // halaman riwayat per barang menampilkan keduanya
        $html = view('filament.receipts', ['item' => $mouse])->render();
        foreach (['PT Sumber Teknologi', 'Toko Aksesori Prima', 'PO-A-001', 'PO-B-007', 'Rp 165.000', 'Rp 152.000', '2 pembelian', 'Rp 3.930.000'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        Livewire::test(ManageAccessories::class)->mountAction(TestAction::make('receipts')->table($mouse))->assertHasNoErrors();
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_checkout_and_return_do_not_touch_purchase_history_or_prices(): void
    {
        $this->as($this->staff());
        $acc = Accessory::where('name', 'Mouse Wireless Logitech M331')->firstOrFail();      // data demo: 3 pembelian
        $this->assertGreaterThanOrEqual(3, $acc->receipts()->count());
        $before = [$acc->receipts()->count(), $acc->unit_price, $acc->vendor_id, $acc->average_price];
        $emp = $this->emp('Rina Wulandari');
        app(StockService::class)->move($acc, 'out', 2, ['employee_id' => $emp->id]);
        app(StockService::class)->move($acc, 'return', 1, ['employee_id' => $emp->id]);
        $acc->refresh();
        $this->assertSame($before, [$acc->receipts()->count(), $acc->unit_price, $acc->vendor_id, $acc->average_price]);
        $this->assertSame(0, StockReceipt::whereIn('stock_movement_id', $acc->movements()->whereIn('type', ['out', 'return'])->pluck('id'))->count());
    }

    public function test_demo_data_has_repeated_purchases_with_different_vendors_and_prices(): void
    {
        $mouse = Accessory::where('name', 'like', 'Mouse Wireless Logitech M331')->firstOrFail();
        $r = $mouse->receipts()->with('vendor')->orderBy('received_at')->get();
        $this->assertGreaterThanOrEqual(3, $r->count());
        $this->assertGreaterThanOrEqual(3, $r->pluck('vendor_id')->unique()->count(), 'vendor berbeda per pembelian');
        $this->assertGreaterThanOrEqual(3, $r->pluck('unit_price')->map(fn ($p) => (float) $p)->unique()->count(), 'harga berbeda per pembelian');
        $this->assertSame((int) $r->sum('qty'), $mouse->qty_total);                  // total stok = jumlah semua pembelian
        $this->assertEquals((float) $r->last()->unit_price, (float) $mouse->unit_price);
        foreach ([Accessory::class, Consumable::class, Component::class] as $cls) {
            foreach ($cls::all() as $i) $this->assertGreaterThan(0, $i->receipts()->count(), "{$i->name} tanpa riwayat pembelian");
        }
    }

    public function test_purchase_form_validation(): void
    {
        $this->as($this->staff());
        $acc = Accessory::first();
        $ok = ['vendor_id' => $this->vendorA()->id, 'qty' => 2, 'unit_price' => 1000, 'received_at' => now()->toDateString()];
        $start = [$acc->qty_total, $acc->receipts()->count()];
        foreach ([
            ['vendor_id' => null], ['qty' => null], ['qty' => 0], ['qty' => -3], ['qty' => 1.5], ['unit_price' => null], ['unit_price' => -1],
            ['received_at' => null], ['received_at' => now()->addDays(3)->toDateString()],
        ] as $bad) {
            Livewire::test(ManageAccessories::class)->callAction(TestAction::make('stock_in')->table($acc), $bad + $ok)->assertHasActionErrors();
        }
        $this->assertSame($start, [$acc->fresh()->qty_total, $acc->receipts()->count()], 'input salah tidak mengubah stok maupun riwayat');
        // harga 0 sah (barang gratis/hibah); consumable boleh pecahan
        Livewire::test(ManageAccessories::class)->callAction(TestAction::make('stock_in')->table($acc), ['unit_price' => 0] + $ok)->assertHasNoActionErrors();
        $c = Consumable::first();
        Livewire::test(ManageConsumables::class)->callAction(TestAction::make('stock_in')->table($c), ['qty' => 2.5, 'vendor_id' => $this->vendorB()->id, 'unit_price' => 5000, 'received_at' => now()->toDateString()])->assertHasNoActionErrors();
        $this->assertEquals(2.5, (float) $c->receipts()->latest('id')->value('qty'));
    }

    public function test_consumables_and_components_record_purchases_too(): void
    {
        $this->as($this->staff());
        $c = Consumable::where('name', 'like', 'Toner HP 85A%')->firstOrFail();
        $qty = (float) $c->qty;
        $n = $c->receipts()->count();
        $this->buy(ManageConsumables::class, $c, ['vendor_id' => $this->vendorB()->id, 'qty' => 5, 'unit_price' => 700000, 'reference_no' => 'PO-C-1'])->assertHasNoActionErrors();
        $c->refresh();
        $this->assertEquals($qty + 5, (float) $c->qty);
        $this->assertSame($n + 1, $c->receipts()->count());
        $this->assertEquals(700000, (float) $c->unit_price);
        $this->assertSame($this->vendorB()->id, $c->vendor_id);

        $p = Component::where('name', 'RAM DDR4 16GB')->firstOrFail();
        $this->buy(ManageComponents::class, $p, ['vendor_id' => $this->vendorA()->id, 'qty' => 3, 'unit_price' => 799000])->assertHasNoActionErrors();
        $p->refresh();
        $this->assertEquals(799000, (float) $p->price);                                  // komponen memakai kolom "price"
        $this->assertSame($this->vendorA()->id, $p->vendor_id);
        $this->assertGreaterThan(799000, $p->average_price, 'rata-rata tetap memperhitungkan pembelian lain yang lebih mahal');
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_initial_stock_from_create_form_becomes_the_first_purchase(): void
    {
        $this->as($this->staff());
        Livewire::test(ManageAccessories::class)->callAction('create', ['name' => 'Headset QA', 'qty_total' => 6, 'min_qty' => 2, 'unit_price' => 250000, 'vendor_id' => $this->vendorB()->id])->assertHasNoActionErrors();
        $a = Accessory::where('name', 'Headset QA')->firstOrFail();
        $this->assertSame(6, $a->qty_available);
        $r = $a->receipts()->get();
        $this->assertCount(1, $r);
        $this->assertSame([$this->vendorB()->id, 250000.0, 6.0], [$r[0]->vendor_id, (float) $r[0]->unit_price, (float) $r[0]->qty]);
        $this->assertSame('Stok awal', $a->movements()->where('type', 'in')->first()->reason);
        // harga & vendor pada barang tidak lagi bisa diubah manual (hanya lewat pembelian)
        Livewire::test(ManageAccessories::class)->callAction(TestAction::make('edit')->table($a), ['name' => 'Headset QA 2', 'unit_price' => 1, 'vendor_id' => $this->vendorA()->id])->assertHasNoActionErrors();
        $a->refresh();
        $this->assertEquals(250000, (float) $a->unit_price);
        $this->assertSame($this->vendorB()->id, $a->vendor_id);
    }

    public function test_correcting_a_purchase_updates_item_price_vendor_and_average(): void
    {
        $this->as($this->staff());
        $acc = Accessory::create(['name' => 'Kabel QA', 'qty_total' => 0, 'min_qty' => 1]);
        $svc = app(StockService::class);
        $svc->move($acc, 'in', 10, ['vendor_id' => $this->vendorA()->id, 'unit_price' => 100, 'received_at' => now()->subDays(5)->toDateString()]);
        $svc->move($acc, 'in', 10, ['vendor_id' => $this->vendorB()->id, 'unit_price' => 200, 'received_at' => now()->toDateString()]);
        $acc->refresh();
        $this->assertEqualsWithDelta(150.0, $acc->average_price, 0.001);
        [$old, $new] = [$acc->receipts()->orderBy('received_at')->first(), $acc->receipts()->orderByDesc('received_at')->first()];

        // koreksi pembelian terbaru (salah ketik harga) → harga terakhir barang ikut berubah
        Livewire::test(ManageStockReceipts::class)->callAction(TestAction::make('edit')->table($new), ['vendor_id' => $this->vendorA()->id, 'unit_price' => 180, 'received_at' => now()->toDateString(), 'reference_no' => 'PO-FIX'])->assertHasNoActionErrors();
        $acc->refresh();
        $this->assertEquals(180, (float) $acc->unit_price);
        $this->assertSame($this->vendorA()->id, $acc->vendor_id);
        $this->assertEqualsWithDelta(140.0, $acc->average_price, 0.001);
        $this->assertSame('PO-FIX', $new->fresh()->reference_no);
        // koreksi pembelian lama → rata-rata berubah, harga terakhir tetap
        Livewire::test(ManageStockReceipts::class)->callAction(TestAction::make('edit')->table($old), ['vendor_id' => $this->vendorB()->id, 'unit_price' => 120, 'received_at' => now()->subDays(5)->toDateString()])->assertHasNoActionErrors();
        $acc->refresh();
        $this->assertEquals(180, (float) $acc->unit_price);
        $this->assertEqualsWithDelta(150.0, $acc->average_price, 0.001);
        // jumlah tidak dapat diubah dari sini; stok tetap
        $this->assertSame(20, $acc->qty_total);
        $this->assertNotNull(\Spatie\Activitylog\Models\Activity::where('subject_type', StockReceipt::class)->where('subject_id', $new->id)->where('event', 'updated')->first(), 'koreksi tercatat di audit log');
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_purchase_history_page_filters_and_totals(): void
    {
        $this->as($this->staff());
        $all = StockReceipt::count();
        $this->assertGreaterThan(20, $all);
        $page = Livewire::test(ManageStockReceipts::class)->assertCanSeeTableRecords(StockReceipt::orderByDesc('received_at')->orderByDesc('id')->limit(5)->get(), inOrder: true);
        $b = $this->vendorB();
        $page->filterTable('vendor_id', $b->id)->assertCountTableRecords(StockReceipt::where('vendor_id', $b->id)->count());
        Livewire::test(ManageStockReceipts::class)->filterTable('stockable_type', Accessory::class)->assertCountTableRecords(StockReceipt::where('stockable_type', Accessory::class)->count());
        Livewire::test(ManageStockReceipts::class)->filterTable('period', ['from' => now()->subDays(10)->toDateString(), 'until' => now()->toDateString()])
            ->assertCountTableRecords(StockReceipt::whereDate('received_at', '>=', now()->subDays(10)->toDateString())->count());
        $mouse = Accessory::where('name', 'like', 'Mouse Wireless Logitech M331')->first();
        Livewire::test(ManageStockReceipts::class)->searchTable('Logitech M331')->assertCanSeeTableRecords($mouse->receipts()->get());
        $this->get('/admin/stock-receipts')->assertOk()->assertSee('Total belanja');
    }

    public function test_stock_movement_history_shows_vendor_and_price_for_incoming_stock(): void
    {
        $this->as($this->staff());
        $m = StockMovement::where('type', 'in')->whereHas('receipt')->orderByDesc('moved_at')->orderByDesc('id')->firstOrFail();
        $this->assertNotNull($m->receipt->vendor_id);
        $page = [\App\Models\Accessory::class => \App\Filament\Pages\StockHistory\AccessoryStockHistory::class, \App\Models\Consumable::class => \App\Filament\Pages\StockHistory\ConsumableStockHistory::class, \App\Models\Component::class => \App\Filament\Pages\StockHistory\ComponentStockHistory::class][$m->stockable_type];
        Livewire::test($page)->filterTable('type', 'in')->assertCanSeeTableRecords([$m])
            ->assertTableColumnStateSet('receipt.vendor.name', $m->receipt->vendor->name, $m)
            ->assertTableColumnStateSet('receipt.unit_price', $m->receipt->unit_price, $m);
        $this->get($page::getUrl())->assertOk();
    }

    public function test_purchase_reports_and_stock_valuation(): void
    {
        $this->as($this->admin());
        [$title, $head, $rows] = ReportCatalog::build('purchases', []);
        $this->assertSame('Riwayat Pembelian Stok', $title);
        $this->assertCount(StockReceipt::count() + 1, $rows);                           // + baris TOTAL
        foreach ($rows as $r) $this->assertCount(count($head), $r);
        $mouseRows = collect($rows)->filter(fn ($r) => str_contains((string) $r[2], 'Mouse Wireless Logitech M331'));
        $this->assertGreaterThanOrEqual(3, $mouseRows->count());
        $this->assertGreaterThanOrEqual(3, $mouseRows->pluck(3)->unique()->count());      // vendor berbeda
        $filtered = ReportCatalog::build('purchases', ['from' => now()->subDays(10)->toDateString()])[2];
        $this->assertLessThan(count($rows), count($filtered));
        $this->get('/reports/purchases.xlsx')->assertOk();
        $this->get('/reports/purchases.pdf')->assertOk();

        $stock = ReportCatalog::build('stock', []);
        $this->assertSame('Harga rata-rata (Rp)', $stock[1][6]);
        $mouse = collect($stock[2])->first(fn ($r) => str_contains($r[1], 'Mouse Wireless Logitech M331'));
        $acc = Accessory::where('name', 'like', 'Mouse Wireless Logitech M331')->first();
        $this->assertSame(number_format($acc->average_price, 0, ',', '.'), $mouse[6]);
        // biaya pemakaian consumable memakai harga rata-rata, bukan harga terakhir
        $usage = ReportCatalog::build('consumable_usage', [])[2];
        $this->assertNotEmpty($usage);
    }

    public function test_permissions_for_purchases(): void
    {
        $acc = Accessory::first();
        $this->as($this->mgmt());
        Livewire::test(ManageAccessories::class)->assertActionHidden(TestAction::make('stock_in')->table($acc))->assertActionVisible(TestAction::make('receipts')->table($acc));
        $this->get('/admin/stock-receipts')->assertOk();
        $rec = StockReceipt::first();
        Livewire::test(ManageStockReceipts::class)->assertActionHidden(TestAction::make('edit')->table($rec))->assertActionDoesNotExist('create');
        $this->as($this->plain())->get('/admin/stock-receipts')->assertForbidden();
        $this->as($this->staff());
        Livewire::test(ManageStockReceipts::class)->assertActionVisible(TestAction::make('edit')->table($rec));
    }
}
