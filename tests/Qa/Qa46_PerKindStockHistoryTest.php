<?php

namespace Tests\Qa;

use App\Filament\Pages\StockHistory\AccessoryStockHistory;
use App\Filament\Pages\StockHistory\ComponentStockHistory;
use App\Filament\Pages\StockHistory\ConsumableStockHistory;
use App\Models\Accessory;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\Employee;
use App\Models\StockMovement;
use App\Models\Vendor;
use App\Services\AccessoryHandoverService;
use Carbon\Carbon;
use Livewire\Livewire;
use Tests\QaTestCase;

/** Riwayat Stok per menu: Accessories, Consumable, dan Komponen masing-masing punya riwayat sendiri, tidak digabung. */
class Qa46_PerKindStockHistoryTest extends QaTestCase
{
    private const PAGES = [Accessory::class => AccessoryStockHistory::class, Consumable::class => ConsumableStockHistory::class, Component::class => ComponentStockHistory::class];

    private function seedOne(): array
    {
        return [
            Accessory::create(['name' => 'Mouse H46', 'qty_total' => 10, 'min_qty' => 0]),
            Consumable::create(['name' => 'Tinta H46', 'unit' => 'rim', 'qty' => 10, 'min_qty' => 0]),
            Component::create(['name' => 'RAM H46', 'qty_total' => 10, 'min_qty' => 0]),
        ];
    }

    public function test_each_page_shows_only_its_own_kind_and_never_links_to_the_others(): void
    {
        $this->as($this->staff());
        [$a, $c, $k] = $this->seedOne();
        foreach (self::PAGES as $class => $page) {
            $rows = Livewire::test($page)->instance()->getTableRecords();
            $this->assertGreaterThan(0, $rows->count(), $page);
            $this->assertTrue($rows->every(fn ($r) => $r->stockable_type === $class), "$page hanya boleh memuat riwayat jenisnya");
        }
        $mine = fn ($item) => StockMovement::where('stockable_type', $item::class)->where('stockable_id', $item->id)->get();
        Livewire::test(AccessoryStockHistory::class)->assertCanSeeTableRecords($mine($a))->assertCanNotSeeTableRecords($mine($c))->assertCanNotSeeTableRecords($mine($k));
        Livewire::test(ConsumableStockHistory::class)->assertCanSeeTableRecords($mine($c))->assertCanNotSeeTableRecords($mine($a))->assertCanNotSeeTableRecords($mine($k));
        Livewire::test(ComponentStockHistory::class)->assertCanSeeTableRecords($mine($k))->assertCanNotSeeTableRecords($mine($a))->assertCanNotSeeTableRecords($mine($c));

        // tidak ada tombol / tautan ke riwayat jenis lain, tidak ada kolom "Jenis"; ada tombol kembali ke menu sendiri
        $menu = [Accessory::class => '/admin/accessories', Consumable::class => '/admin/consumables', Component::class => '/admin/components'];
        foreach (self::PAGES as $class => $page) {
            $html = $this->get($page::getUrl())->assertOk()->getContent();
            foreach (self::PAGES as $other => $otherPage) {
                if ($other !== $class) $this->assertStringNotContainsString(parse_url($otherPage::getUrl(), PHP_URL_PATH), $html, "$page tidak boleh menautkan ke $otherPage");
            }
            $this->assertStringNotContainsString('Jenis barang', $html);
            $this->assertStringContainsString($menu[$class], $html, 'tombol kembali ke menu sendiri');
            Livewire::test($page)->assertActionVisible('back')->assertActionDoesNotExist('receipts')->assertActionDoesNotExist('consumables')->assertActionDoesNotExist('accessories')->assertActionDoesNotExist('components')->assertTableColumnDoesNotExist('stockable_type');
        }
    }

    public function test_columns_fit_each_kind(): void
    {
        $this->as($this->staff());
        Livewire::test(AccessoryStockHistory::class)->assertTableColumnDoesNotExist('asset.asset_tag')->assertTableColumnExists('location.name')->assertTableColumnExists('documents_count');
        Livewire::test(ConsumableStockHistory::class)->assertTableColumnExists('asset.asset_tag')->assertTableColumnExists('receipt.vendor.name');
        Livewire::test(ComponentStockHistory::class)->assertTableColumnExists('asset.asset_tag')->assertTableColumnExists('department.name');
        // consumable: jumlah desimal + satuan
        [, $c] = $this->seedOne();
        app(AccessoryHandoverService::class)->checkout(Employee::where('status', 'active')->firstOrFail(), [['item' => 'consumable:' . $c->id, 'qty' => 1.5]]);
        $m = StockMovement::where('stockable_type', Consumable::class)->where('stockable_id', $c->id)->where('type', 'out')->firstOrFail();
        Livewire::test(ConsumableStockHistory::class)->assertTableColumnFormattedStateSet('qty', '1,5 rim', $m);
    }

    public function test_tabs_filters_and_search_work_per_page(): void
    {
        $this->as($this->staff());
        [$a, $c, $k] = $this->seedOne();
        $emp = Employee::where('status', 'active')->firstOrFail();
        $svc = app(AccessoryHandoverService::class);
        $svc->checkout($emp, [['item' => 'accessory:' . $a->id, 'qty' => 2]]);
        $svc->receiveBack($emp, [['item' => 'accessory:' . $a->id, 'qty' => 1, 'condition' => 'good']]);
        $other = Accessory::create(['name' => 'Keyboard H46', 'qty_total' => 4, 'min_qty' => 0]);

        // tab
        $types = fn ($tab) => Livewire::test(AccessoryStockHistory::class)->set('activeTab', $tab)->instance()->getTableRecords()->pluck('type')->unique()->sort()->values()->all();
        $this->assertSame(['in'], $types('in'));
        $this->assertSame(['out'], $types('out'));
        $this->assertSame(['return'], $types('other'));
        $this->assertSame(['in', 'out', 'return'], $types('all'));
        Livewire::test(AccessoryStockHistory::class)->set('activeTab', 'tidak-ada')->assertSet('activeTab', 'all');         // tab tak dikenal -> semua
        Livewire::withQueryParams(['tab' => 'out'])->test(AccessoryStockHistory::class)->assertSet('activeTab', 'out');
        Livewire::withQueryParams([]);                                                                                       // parameter query Livewire menempel: kembalikan ke kosong
        $this->get(AccessoryStockHistory::getUrl() . '?tab=in')->assertOk();

        // filter tipe, barang (hanya barang jenisnya), dan pencarian nama
        $this->assertSame(['out'], Livewire::test(AccessoryStockHistory::class)->filterTable('type', 'out')->instance()->getTableRecords()->pluck('type')->unique()->values()->all());
        $itemOptions = collect(Livewire::test(AccessoryStockHistory::class)->instance()->getTable()->getFilter('stockable_id')->getOptions());
        $this->assertTrue($itemOptions->contains('Mouse H46'));
        $this->assertFalse($itemOptions->contains('Tinta H46'));                                                              // consumable tidak muncul di filter accessories
        $this->assertFalse($itemOptions->contains('RAM H46'));
        $r = Livewire::test(AccessoryStockHistory::class)->filterTable('stockable_id', $a->id)->instance()->getTableRecords();
        $this->assertTrue($r->every(fn ($x) => (int) $x->stockable_id === $a->id));
        $this->assertGreaterThan(0, $r->count());
        Livewire::test(AccessoryStockHistory::class)->searchTable('Keyboard H46')->assertCanSeeTableRecords(StockMovement::where('stockable_id', $other->id)->where('stockable_type', Accessory::class)->get())
            ->assertCanNotSeeTableRecords(StockMovement::where('stockable_id', $a->id)->where('stockable_type', Accessory::class)->get());
        Livewire::test(AccessoryStockHistory::class)->searchTable('Tinta H46')->assertCountTableRecords(0);                  // pencarian tidak menembus jenis lain

        // filter tanggal
        $old = StockMovement::where('stockable_type', Accessory::class)->where('stockable_id', $other->id)->firstOrFail();
        $old->update(['moved_at' => Carbon::parse('2025-03-10 10:00')]);
        Livewire::test(AccessoryStockHistory::class)->filterTable('moved', ['from' => '2025-03-01', 'until' => '2025-03-31'])->assertCanSeeTableRecords([$old])
            ->assertCountTableRecords(1);
        Livewire::test(AccessoryStockHistory::class)->filterTable('moved', ['from' => '2026-01-01', 'until' => null])->assertCanNotSeeTableRecords([$old]);

        // komponen: tab Dipasang / Dilepas
        $asset = Asset::whereHas('category', fn ($q) => $q->whereIn('code_prefix', ['NB', 'PC']))->where('status', '!=', 'disposed')->firstOrFail();
        $svc->installComponents($asset, [['component_id' => $k->id, 'qty' => 2]], [], 'Penambahan');
        $svc->installComponents($asset->fresh(), [], [['component_id' => $k->id, 'qty' => 1, 'condition' => 'good']], 'Perbaikan');
        $kt = Livewire::test(ComponentStockHistory::class)->set('activeTab', 'install')->instance()->getTableRecords()->pluck('type')->unique()->sort()->values()->all();
        $this->assertSame(['install', 'uninstall'], $kt);
        $this->assertArrayHasKey('install', Livewire::test(ComponentStockHistory::class)->instance()->tabs());
        $this->assertArrayNotHasKey('install', Livewire::test(AccessoryStockHistory::class)->instance()->tabs());
    }

    public function test_stock_in_and_out_from_inside_the_history_page_update_its_table(): void
    {
        $this->as($this->staff());
        [$a, $c, $k] = $this->seedOne();
        $emp = Employee::where('status', 'active')->firstOrFail();
        $vendor = Vendor::firstOrFail();
        $before = fn ($page) => Livewire::test($page)->instance()->getTableRecords()->total();          // total semua halaman (tabel dipaginasi 10)

        $l = Livewire::test(AccessoryStockHistory::class)->assertActionVisible('stock_in_header')->assertActionVisible('stock_out_header');
        $n = $before(AccessoryStockHistory::class);
        $l->callAction('stock_in_header', ['item_id' => $a->id, 'vendor_id' => $vendor->id, 'qty' => 3, 'unit_price' => 50000, 'received_at' => now()->toDateString(), 'reference_no' => 'PO-H46'])->assertHasNoActionErrors();
        $this->assertSame($n + 1, $before(AccessoryStockHistory::class));
        $this->assertSame(13, $a->fresh()->qty_available);
        $n = $before(AccessoryStockHistory::class);
        Livewire::test(AccessoryStockHistory::class)->callAction('stock_out_header', ['item_id' => $a->id, 'qty' => 1, 'target' => 'employee', 'employee_id' => $emp->id])->assertHasNoActionErrors()->assertNotified();
        $this->assertSame($n + 1, $before(AccessoryStockHistory::class));

        // consumable & komponen sama; komponen punya tombol Pasang ke Aset
        Livewire::test(ConsumableStockHistory::class)->callAction('stock_out_header', ['item_id' => $c->id, 'qty' => 2.5, 'target' => 'employee', 'employee_id' => $emp->id])->assertHasNoActionErrors();
        $this->assertEquals(7.5, $c->fresh()->qty);
        Livewire::test(ComponentStockHistory::class)->assertActionVisible('install_header');
        Livewire::test(AccessoryStockHistory::class)->assertActionDoesNotExist('install_header');
        // mengikuti hak akses: manajemen hanya melihat
        $this->as($this->mgmt());
        Livewire::test(AccessoryStockHistory::class)->assertActionHidden('stock_in_header')->assertActionHidden('stock_out_header');
    }

    public function test_legacy_combined_url_redirects_to_the_matching_page_and_the_combined_page_is_gone(): void
    {
        $this->as($this->staff());
        $this->get('/admin/stock-movements')->assertRedirect(AccessoryStockHistory::getUrl());
        $this->get('/admin/stock-movements?filters[stockable_type][value]=' . urlencode(Consumable::class))->assertRedirect(ConsumableStockHistory::getUrl());
        $this->get('/admin/stock-movements?filters[stockable_type][value]=' . urlencode(Component::class))->assertRedirect(ComponentStockHistory::getUrl());
        $this->assertFileDoesNotExist(app_path('Filament/Resources/StockMovements/StockMovementResource.php'));          // halaman gabungan sudah dihapus
    }
}
