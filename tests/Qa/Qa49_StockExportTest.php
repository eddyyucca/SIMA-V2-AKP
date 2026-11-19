<?php

namespace Tests\Qa;

use App\Filament\Resources\Accessories\Pages\ManageAccessories;
use App\Filament\Resources\Components\Pages\ManageComponents;
use App\Filament\Resources\Consumables\Pages\ManageConsumables;
use App\Models\Accessory;
use App\Models\Component;
use App\Models\Consumable;
use App\Reports\StockListReport;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\QaTestCase;

/** Export CSV / Excel / PDF / Cetak di menu Accessories, Komponen, dan Consumable. */
class Qa49_StockExportTest extends QaTestCase
{
    public static function kinds(): array
    {
        return [
            'accessory' => [ManageAccessories::class, Accessory::class, 'daftar-accessories'],
            'component' => [ManageComponents::class, Component::class, 'daftar-komponen'],
            'consumable' => [ManageConsumables::class, Consumable::class, 'daftar-consumable'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('kinds')]
    public function test_every_export_format_downloads(string $page, string $class, string $name): void
    {
        $this->as($this->staff());
        $d = now()->format('Ymd');
        Livewire::test($page)->callAction(TestAction::make('toolbar_csv')->table())->assertFileDownloaded("$name-$d.csv");
        Livewire::test($page)->callAction(TestAction::make('toolbar_xlsx')->table())->assertFileDownloaded("$name-$d.xlsx");
        Livewire::test($page)->callAction(TestAction::make('toolbar_pdf')->table())->assertFileDownloaded("$name-$d.pdf");
        Livewire::test($page)->callAction(TestAction::make('toolbar_print')->table())->assertNotified();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('kinds')]
    public function test_report_has_one_row_per_item_and_matching_columns(string $page, string $class, string $name): void
    {
        $items = $class::orderBy('id')->get();
        $this->assertNotEmpty($items);
        [$head, $rows] = StockListReport::build($class, $items);
        $this->assertCount($items->count(), $rows);
        foreach ($rows as $r) $this->assertCount(count($head), $r);
        $this->assertSame($items->first()->code, $rows[0][0]);
        $this->assertSame($items->first()->name, $rows[0][1]);
        $this->assertContains('Nilai stok (Rp)', $head);
        [, $pretty] = StockListReport::build($class, $items, pretty: true);
        $this->assertCount($items->count(), $pretty);
    }

    public function test_csv_follows_the_table_search_and_low_stock_status(): void
    {
        $this->as($this->staff());
        $low = Accessory::create(['name' => 'Kabel Habis T49', 'qty_total' => 1, 'min_qty' => 5]);
        $ok = Accessory::create(['name' => 'Kabel Aman T49', 'qty_total' => 9, 'min_qty' => 1]);
        [, $rows] = StockListReport::build(Accessory::class, Accessory::whereKey([$low->id, $ok->id])->orderBy("id")->get());
        $status = array_search('Menipis', $rows[0], true);
        $this->assertNotFalse($status);
        $this->assertSame('Aman', $rows[1][$status]);

        $t = Livewire::test(ManageAccessories::class)->searchTable('Kabel Habis T49');
        $this->assertSame([$low->id], $t->instance()->getFilteredSortedTableQuery()->pluck('id')->all());
        $t->callAction(TestAction::make('toolbar_csv')->table())->assertFileDownloaded('daftar-accessories-' . now()->format('Ymd') . '.csv');
    }
}
