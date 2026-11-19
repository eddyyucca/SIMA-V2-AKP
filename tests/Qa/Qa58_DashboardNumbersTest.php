<?php

namespace Tests\Qa;

use App\Filament\Pages\Dashboard;
use App\Models\Asset;
use App\Models\Repair;
use Livewire\Livewire;
use Tests\QaTestCase;

/** Angka Dashboard harus cocok dengan data dan saling konsisten (jumlah per kategori/lokasi/department = total aset). */
class Qa58_DashboardNumbersTest extends QaTestCase
{
    public function test_dashboard_figures_match_the_data_and_are_internally_consistent(): void
    {
        $this->as($this->admin());
        $page = Livewire::test(Dashboard::class)->assertSuccessful()->instance();
        $d = $page->data();

        $live = Asset::active();
        $this->assertSame((clone $live)->count(), $d['total']);
        $this->assertSame((float) (clone $live)->sum('purchase_price'), $d['value']);
        $this->assertSame($d['total'], (int) $d['byCategory']->sum(), 'jumlah per kategori = total aset');
        $this->assertSame($d['total'], (int) $d['byLocation']->sum(), 'jumlah per lokasi = total aset');
        $this->assertSame(Asset::where('status', 'deployed')->count(), $d['deployed']);
        $this->assertSame(Asset::where('status', 'available')->count(), $d['available']);
        $this->assertSame(Asset::where('status', 'repair')->count(), $d['repair']);
        $this->assertSame(Repair::where('status', '!=', 'done')->count(), $d['openRepairs']);
        $this->assertLessThanOrEqual($d['total'], $d['deployed'] + $d['available'] + $d['repair'] + $d['borrowed'] + $d['lost'], 'status tidak melebihi total');
        $this->assertSame($d['total'] ? (int) round($d['deployed'] / $d['total'] * 100) : 0, (int) $d['util']);

        foreach (['purchase', 'book'] as $mode) {
            $page->deptValue = $mode; $page->deptCategory = '';
            $dp = $page->departmentData();
            $this->assertSame($d['total'], $dp['count'], "jumlah aset per department ({$mode}) = total aset");
            if ($mode === 'purchase') $this->assertEqualsWithDelta($d['value'], $dp['value'], 0.01, 'nilai per department = nilai perolehan total');
        }
        // filter kategori: jumlahnya sama dengan jumlah aset kategori itu
        $cat = Asset::active()->whereNotNull('category_id')->first()->category_id;
        $page->deptCategory = (string) $cat;
        $this->assertSame(Asset::active()->where('category_id', $cat)->count(), $page->departmentData()['count']);
    }

    public function test_dashboard_stock_alerts_match_real_stock(): void
    {
        $this->as($this->admin());
        $d = Livewire::test(Dashboard::class)->instance()->data();
        $expected = \App\Models\Accessory::whereColumn('qty_available', '<=', 'min_qty')->count()
            + \App\Models\Component::whereColumn('qty_available', '<=', 'min_qty')->count()
            + \App\Models\Consumable::whereColumn('qty', '<=', 'min_qty')->count();
        $this->assertSame($expected, $d['lowStock']->count());
    }
}
