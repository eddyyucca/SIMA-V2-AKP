<?php

namespace Tests\Qa;

use App\Filament\Pages\Dashboard;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Department;
use App\Services\DepreciationService;
use Livewire\Livewire;
use Tests\QaTestCase;

class Qa29_DashboardDepartmentCardTest extends QaTestCase
{
    public function test_card_counts_and_values_match_active_assets_by_holder_department(): void
    {
        $this->as($this->staff());
        $t = Livewire::test(Dashboard::class)->assertOk()->assertSee('Aset per Department');
        $d = $t->instance()->departmentData();

        $active = Asset::active();
        $this->assertSame((clone $active)->count(), $d['count']);                                          // semua aset aktif, tidak ada yang hilang
        $this->assertEqualsWithDelta((float) (clone $active)->sum('purchase_price'), $d['value'], 0.01);

        foreach (Department::all() as $dep) {
            $q = Asset::active()->whereHas('employee', fn ($e) => $e->where('department_id', $dep->id));
            $row = $d['rows']->firstWhere('key', (string) $dep->id);
            $this->assertSame($q->count(), $row['count'] ?? 0, $dep->name);
            if ($q->count()) $this->assertEqualsWithDelta((float) $q->sum('purchase_price'), $row['value'], 0.01);
        }
        $none = $d['rows']->firstWhere('key', 'none');
        $this->assertSame(Asset::active()->whereNull('employee_id')->count(), $none['count'] ?? 0);
        $this->assertSame('none', $d['rows']->last()['key']);                                               // stok gudang di paling bawah
    }

    public function test_category_choice_filters_the_card(): void
    {
        $this->as($this->staff());
        $cat = Category::where('type', 'asset')->whereIn('id', Asset::active()->pluck('category_id'))->firstOrFail();
        $t = Livewire::test(Dashboard::class)->set('deptCategory', (string) $cat->id);
        $d = $t->instance()->departmentData();
        $this->assertSame(Asset::active()->where('category_id', $cat->id)->count(), $d['count']);
        $this->assertEqualsWithDelta((float) Asset::active()->where('category_id', $cat->id)->sum('purchase_price'), $d['value'], 0.01);
        $this->assertArrayHasKey($cat->id, $d['categories']->all());

        $t->set('deptCategory', '999999')->assertSet('deptCategory', '');                                   // pilihan tidak sah dikembalikan ke semua
        $t->set('deptCategory', '')->assertSet('deptCategory', '');
    }

    public function test_book_value_mode_and_color_tiers(): void
    {
        $this->as($this->staff());
        $t = Livewire::test(Dashboard::class)->set('deptValue', 'book');
        $d = $t->instance()->departmentData();
        $expected = Asset::active()->get()->sum(fn ($a) => app(DepreciationService::class)->bookValue($a, now()));
        $this->assertEqualsWithDelta($expected, $d['value'], 0.5);
        $t->set('deptValue', 'ngawur')->assertSet('deptValue', 'purchase');

        $rows = $t->instance()->departmentData()['rows'];
        $max = $rows->max('value');
        foreach ($rows as $r) {
            $ratio = $max > 0 ? $r['value'] / $max : 0;
            $this->assertSame($ratio >= 0.25 ? 'red' : ($ratio >= 0.08 ? 'orange' : 'green'), $r['tone']);
        }
        $this->assertSame('red', $rows->sortByDesc('value')->first()['tone']);                              // terbesar selalu merah
    }

    public function test_empty_selection_shows_message_and_card_renders_selects(): void
    {
        $this->as($this->staff());
        $cat = Category::where('type', 'asset')->whereNotIn('id', Asset::active()->pluck('category_id'))->first();
        $html = $this->get('/admin')->assertOk()->getContent();
        $this->assertStringContainsString('Semua kategori aset', $html);
        $this->assertStringContainsString('Nilai perolehan', $html);
        if ($cat) Livewire::test(Dashboard::class)->set('deptCategory', (string) $cat->id)->assertSee('Tidak ada aset pada pilihan ini.');
    }

    public function test_card_is_shown_even_when_there_are_no_arrival_plans(): void
    {
        $this->as($this->staff());
        \App\Models\ArrivalPlanItem::query()->delete();
        \App\Models\ArrivalPlan::query()->delete();
        $this->assertSame(0, Livewire::test(Dashboard::class)->instance()->data()['arrivals']->count());
        $html = $this->get('/admin')->assertOk()->getContent();
        $this->assertStringContainsString('id="aset-per-department"', $html);
        $this->assertStringNotContainsString('Kedatangan karyawan onsite', $html);          // bagian kedatangan tetap hanya muncul bila ada
    }
}
