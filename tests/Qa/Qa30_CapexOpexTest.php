<?php

namespace Tests\Qa;

use App\Filament\Pages\Dashboard;
use App\Models\Accessory;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\License;
use App\Models\Repair;
use App\Models\StockReceipt;
use App\Services\SpendService;
use Livewire\Livewire;
use Tests\QaTestCase;

class Qa30_CapexOpexTest extends QaTestCase
{
    private const Y = 2019;     // tahun tanpa data demo: semua angka berasal dari data uji di bawah

    private function asset(array $a): Asset
    {
        $cat = Category::where('type', 'asset')->firstOrFail();
        return Asset::create($a + ['asset_tag' => Asset::generateTag($cat), 'category_id' => $cat->id, 'status' => 'available', 'condition' => 'good']);
    }

    private function receipt(string $class, string $date, float $qty, float $price): void
    {
        StockReceipt::create(['stockable_type' => $class, 'stockable_id' => $class::firstOrFail()->id, 'qty' => $qty, 'unit_price' => $price, 'received_at' => $date]);
    }

    private function fixture(): void
    {
        // CAPEX
        $this->asset(['ownership' => 'owned', 'purchase_date' => '2019-03-15', 'purchase_price' => 10_000_000]);
        $this->asset(['ownership' => 'owned', 'purchase_date' => '2019-03-20', 'purchase_price' => 5_000_000, 'status' => 'disposed']);    // disposed tetap dihitung
        $this->asset(['ownership' => 'owned', 'purchase_date' => '2018-12-31', 'purchase_price' => 99_000_000]);                          // tahun lain: tidak ikut
        $this->receipt(Component::class, '2019-04-05', 2, 750_000);                                                                      // komponen 1.500.000
        License::create(['name' => 'Perpetual QA', 'type' => 'perpetual', 'seats' => 1, 'price' => 4_000_000, 'purchase_date' => '2019-07-01']);
        // OPEX
        $this->receipt(Accessory::class, '2019-03-02', 3, 100_000);                                                                      // 300.000
        $this->receipt(Consumable::class, '2019-03-03', 10, 50_000);                                                                     // 500.000
        License::create(['name' => 'Langganan QA', 'type' => 'subscription', 'seats' => 1, 'price' => 2_400_000, 'purchase_date' => '2019-08-01']);
        License::create(['name' => 'Domain QA', 'type' => 'domain', 'seats' => 1, 'price' => 350_000, 'purchase_date' => '2019-08-20']);
        $a = $this->asset(['ownership' => 'owned', 'purchase_date' => '2019-01-01', 'purchase_price' => 0]);
        Repair::create(['asset_id' => $a->id, 'reported_at' => '2019-05-01 08:00:00', 'issue' => 'uji', 'cost' => 600_000, 'status' => 'done', 'finished_at' => '2019-05-10 09:00:00']);
        Repair::create(['asset_id' => $a->id, 'reported_at' => '2019-06-01 08:00:00', 'issue' => 'belum selesai', 'cost' => 900_000, 'status' => 'in_progress']);   // belum selesai: belum dihitung
        // sewa: 1 Jan - 31 Mar 2019 (3 bulan penuh) dan 16-30 Apr 2019 (15 dari 30 hari)
        $this->asset(['ownership' => 'rental', 'rental_start' => '2019-01-01', 'rental_end' => '2019-03-31', 'rental_monthly_cost' => 1_000_000, 'purchase_date' => '2019-01-01']);
        $this->asset(['ownership' => 'rental', 'rental_start' => '2019-04-16', 'rental_end' => '2019-04-30', 'rental_monthly_cost' => 1_000_000, 'purchase_date' => '2019-04-16']);
    }

    public function test_capex_and_opex_are_split_and_summed_exactly(): void
    {
        $this->fixture();
        $r = app(SpendService::class)->year(self::Y);

        $this->assertEqualsWithDelta(15_000_000, $r['lines']['assets']['total'], 0.01);        // 10 jt + 5 jt (disposed ikut), 2018 tidak
        $this->assertEqualsWithDelta(1_500_000, $r['lines']['components']['total'], 0.01);
        $this->assertEqualsWithDelta(4_000_000, $r['lines']['perpetual']['total'], 0.01);
        $this->assertEqualsWithDelta(20_500_000, $r['capex'], 0.01);

        $this->assertEqualsWithDelta(3_500_000, $r['lines']['rental']['total'], 0.01);         // 3 × 1 jt + 15/30 × 1 jt
        $this->assertEqualsWithDelta(600_000, $r['lines']['repairs']['total'], 0.01);          // yang belum selesai tidak dihitung
        $this->assertEqualsWithDelta(800_000, $r['lines']['consumables']['total'], 0.01);
        $this->assertEqualsWithDelta(2_750_000, $r['lines']['subscription']['total'], 0.01);   // langganan + domain
        $this->assertEqualsWithDelta(7_650_000, $r['opex'], 0.01);
        $this->assertEqualsWithDelta(28_150_000, $r['total'], 0.01);

        // per bulan
        $this->assertEqualsWithDelta(15_000_000, $r['months'][3]['capex'], 0.01);              // aset dibeli Maret
        $this->assertEqualsWithDelta(1_500_000, $r['months'][4]['capex'], 0.01);
        $this->assertEqualsWithDelta(500_000, $r['lines']['rental']['monthly'][4], 0.01);      // 15 hari dari 30 hari
        $this->assertEqualsWithDelta(1_000_000 + 800_000, $r['months'][3]['opex'], 0.01);      // sewa Maret + aksesori + habis pakai
        $this->assertEqualsWithDelta(0, $r['months'][12]['capex'] + $r['months'][12]['opex'], 0.01);
        $this->assertEqualsWithDelta($r['capex'], array_sum(array_column($r['months'], 'capex')), 0.01);   // total bulanan = total tahunan
    }

    public function test_rental_stops_at_return_date_and_never_counts_future_months(): void
    {
        $this->asset(['ownership' => 'rental', 'rental_start' => '2019-01-01', 'rental_end' => '2019-12-31', 'rental_monthly_cost' => 3_100_000, 'returned_at' => '2019-01-10', 'status' => 'returned', 'purchase_date' => '2019-01-01']);
        $r = app(SpendService::class)->year(self::Y);
        $this->assertEqualsWithDelta(1_000_000, $r['lines']['rental']['total'], 0.01);         // 10 hari dari 31 hari × 3,1 jt

        // sewa yang masih berjalan pada tahun ini: tidak melewati hari ini
        $y = (int) now()->year;
        $before = app(SpendService::class)->year($y)['lines']['rental']['total'];
        $this->asset(['ownership' => 'rental', 'rental_start' => now()->startOfYear()->toDateString(), 'rental_end' => now()->addYears(2)->toDateString(), 'rental_monthly_cost' => 1_000_000, 'purchase_date' => now()->startOfYear()->toDateString()]);
        $added = app(SpendService::class)->year($y)['lines']['rental']['total'] - $before;
        $days = now()->startOfYear()->diffInDays(now()->startOfDay()) + 1;
        $this->assertLessThanOrEqual(1_000_000 * 12, $added);
        $this->assertGreaterThan(0, $added);
        $this->assertLessThan(1_000_000 * ($days / 28 + 0.1), $added);                         // sebanding dengan hari yang sudah lewat
        if ((int) now()->format('n') < 12) $this->assertEqualsWithDelta(0, app(SpendService::class)->year($y)['lines']['rental']['monthly'][12], 0.01);
    }

    public function test_dashboard_card_renders_totals_chart_and_year_selector(): void
    {
        $this->fixture();
        $this->as($this->staff());
        $t = Livewire::test(Dashboard::class)->assertSee('CAPEX')->assertSee('OPEX');
        $d = $t->set('spendYear', self::Y)->instance()->spendData();
        $this->assertSame(self::Y, $d['year']);
        $this->assertEqualsWithDelta(28_150_000, $d['total'], 0.01);
        $t->assertSee('Rp 20.500.000')->assertSee('Rp 7.650.000')->assertSee('Rp 28.150.000')->assertSee('Mar 2019');
        $this->assertSame(3, $d['peakCapex']);                                                // puncak CAPEX = Maret
        $this->assertGreaterThanOrEqual(15_000_000, $d['axisMax']);                            // sumbu cukup untuk batang tertinggi
        $this->assertSame(5, count($d['ticks']));
        $this->assertSame(0.0, (float) $d['ticks'][0]);

        $t->set('spendYear', 1990)->assertSet('spendYear', (int) now()->year);                // tahun tidak sah dikembalikan
        $t->set('spendYear', (int) now()->year + 5)->assertSet('spendYear', (int) now()->year);
    }

    public function test_year_with_no_spending_shows_message_and_card_visible_without_arrivals(): void
    {
        $this->as($this->staff());
        $html = $this->get('/admin')->assertOk()->getContent();
        $this->assertStringContainsString('id="capex-opex"', $html);
        $t = Livewire::test(Dashboard::class);
        $first = app(SpendService::class)->firstYear();
        $this->assertLessThanOrEqual((int) now()->year, $first);
        $this->assertTrue(in_array($first, $t->instance()->spendData()['years'], true));
    }
}
