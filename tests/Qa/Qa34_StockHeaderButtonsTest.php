<?php

namespace Tests\Qa;

use App\Filament\Resources\Accessories\Pages\ManageAccessories;
use App\Filament\Resources\AccessoryHandovers\Pages\CreateAccessoryHandover;
use App\Filament\Resources\Components\Pages\ManageComponents;
use App\Filament\Resources\Consumables\Pages\ManageConsumables;
use App\Models\Accessory;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\Employee;
use App\Models\StockMovement;
use App\Models\Vendor;
use Livewire\Livewire;
use Tests\QaTestCase;

/** Tombol Riwayat Stok / Stok Masuk / Stok Keluar di samping "Buat ..." pada Consumable, Accessories, dan Komponen. */
class Qa34_StockHeaderButtonsTest extends QaTestCase
{
    public function test_three_buttons_appear_on_all_three_pages_for_staff(): void
    {
        $this->as($this->staff());
        foreach ([ManageConsumables::class, ManageAccessories::class, ManageComponents::class] as $page) {
            Livewire::test($page)->assertActionVisible('stock_history')->assertActionVisible('stock_in_header')->assertActionVisible('stock_out_header')->assertActionVisible('create');
        }
    }

    public function test_stock_in_from_the_header_buys_stock_and_records_history(): void
    {
        $this->as($this->staff());
        $vendor = Vendor::first() ?? Vendor::create(['name' => 'Vendor QA']);
        $c = Consumable::create(['name' => 'Tinta QA34', 'unit' => 'pcs', 'qty' => 1, 'min_qty' => 0]);
        Livewire::test(ManageConsumables::class)->callAction('stock_in_header', ['item_id' => $c->id, 'vendor_id' => $vendor->id, 'qty' => 4, 'unit_price' => 15000, 'received_at' => now()->toDateString()])->assertHasNoActionErrors();
        $this->assertEquals(5, $c->fresh()->qty);
        $this->assertTrue(StockMovement::where('stockable_type', Consumable::class)->where('stockable_id', $c->id)->where('type', 'in')->where('qty', 4)->exists());

        $a = Accessory::create(['name' => 'Mouse QA34', 'qty_total' => 2, 'min_qty' => 0]);
        Livewire::test(ManageAccessories::class)->callAction('stock_in_header', ['item_id' => $a->id, 'vendor_id' => $vendor->id, 'qty' => 3, 'unit_price' => 50000, 'received_at' => now()->toDateString()])->assertHasNoActionErrors();
        $this->assertSame(5, $a->fresh()->qty_available);
        Livewire::test(ManageAccessories::class)->callAction('stock_in_header', ['item_id' => $a->id, 'vendor_id' => $vendor->id, 'qty' => 1.5, 'unit_price' => 1, 'received_at' => now()->toDateString()])->assertHasActionErrors();
    }

    public function test_consumable_stock_out_from_the_header_and_bast_prefill_link(): void
    {
        $this->as($this->staff());
        $emp = Employee::where('status', 'active')->firstOrFail();
        $c = Consumable::create(['name' => 'Kertas QA34', 'unit' => 'rim', 'qty' => 10, 'min_qty' => 0]);
        Livewire::test(ManageConsumables::class)->callAction('stock_out_header', ['item_id' => $c->id, 'qty' => 3, 'target' => 'employee', 'employee_id' => $emp->id])->assertHasNoActionErrors();
        $this->assertEquals(7, $c->fresh()->qty);
        Livewire::test(ManageConsumables::class)->callAction('stock_out_header', ['item_id' => $c->id, 'qty' => 99, 'target' => 'employee', 'employee_id' => $emp->id]);
        $this->assertEquals(7, $c->fresh()->qty);                                                // melebihi stok: ditolak, tidak berubah

        $cmp = Component::create(['name' => 'SSD QA34', 'qty_total' => 3, 'min_qty' => 0]);
        Livewire::withQueryParams(['item' => 'component:' . $cmp->id])->test(CreateAccessoryHandover::class)
            ->assertSet('data.lines', fn ($l) => collect($l)->contains(fn ($x) => ($x['item'] ?? null) === 'component:' . $cmp->id));
    }

    public function test_each_menu_history_button_opens_its_own_history_page(): void
    {
        $this->as($this->staff());
        foreach ([[ManageConsumables::class, Consumable::class], [ManageAccessories::class, Accessory::class], [ManageComponents::class, Component::class]] as [$page, $class]) {
            Livewire::test($page)->assertActionHasUrl('stock_history', \App\Filament\Support\StockHeaderActions::historyUrl($class));
        }
        $this->assertNotSame(\App\Filament\Support\StockHeaderActions::historyUrl(Consumable::class), \App\Filament\Support\StockHeaderActions::historyUrl(Accessory::class));
    }
}
