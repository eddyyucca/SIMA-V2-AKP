<?php

namespace Tests\Qa;

use App\Filament\Resources\StockReceipts\Pages\ManageStockReceipts;
use App\Models\Accessory;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\StockReceipt;
use Livewire\Livewire;
use Tests\QaTestCase;

class Qa47_StockReceiptTabsTest extends QaTestCase
{
    public function test_purchase_history_is_split_per_kind_by_tabs(): void
    {
        $this->as($this->admin());
        $cases = ['Accessory' => Accessory::class, 'Consumable' => Consumable::class, 'Komponen' => Component::class];
        foreach ($cases as $tab => $class) {
            $expected = StockReceipt::where('stockable_type', $class)->get();
            $other = StockReceipt::where('stockable_type', '!=', $class)->get();
            $t = Livewire::test(ManageStockReceipts::class)->set('activeTab', $tab);
            $t->assertCountTableRecords($expected->count());
            if ($other->isNotEmpty()) $t->assertCanNotSeeTableRecords($other);
        }
        Livewire::test(ManageStockReceipts::class)->set('activeTab', 'all')->assertCountTableRecords(StockReceipt::count());
    }
}
