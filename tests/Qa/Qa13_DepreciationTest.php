<?php

namespace Tests\Qa;

use App\Filament\Pages\Reports;
use App\Filament\Resources\Assets\Pages\CreateAsset;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Filament\Resources\Disposals\Pages\ManageDisposals;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Disposal;
use App\Reports\ReportCatalog;
use App\Services\DepreciationService;
use App\Services\DisposalService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\QaTestCase;

class Qa13_DepreciationTest extends QaTestCase
{
    private DepreciationService $dep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dep = app(DepreciationService::class);
    }

    private function laptop(array $attrs = []): Asset
    {
        static $n = 0;
        $cat = Category::where('code_prefix', 'NB')->firstOrFail();
        return Asset::create($attrs + [
            'asset_tag' => 'DEP-' . ++$n . '-' . substr(md5((string) microtime(true)), 0, 4), 'category_id' => $cat->id, 'purchase_date' => '2026-08-15', 'purchase_price' => 12500000,
            'status' => 'available', 'condition' => 'good',
        ]);
    }

    private function d(string $date): Carbon { return Carbon::parse($date); }

    // ---------------------------------------------------------------- rumus
    public function test_straight_line_monthly_with_prorated_first_year(): void
    {
        $a = $this->laptop();                                                     // Rp 12.500.000, 4 tahun, residu 0%, beli 15 Agustus 2026
        $p = $this->dep->profile($a);
        $this->assertTrue($p['depreciable']);
        $this->assertSame(4, $p['years']);                                        // umur dari kategori Laptop
        $this->assertEquals(0.0, $p['residual']);
        $this->assertEquals(260416.67, $p['per_month']);
        $this->assertEquals(3125000.00, $p['per_year']);

        $this->assertSame(5, $this->dep->months($a, $this->d('2026-12-31')));      // Agu–Des = 5 bulan (bulan beli dihitung penuh)
        $this->assertEquals(1302083.33, $this->dep->accumulated($a, $this->d('2026-12-31')));
        $this->assertEquals(11197916.67, $this->dep->bookValue($a, $this->d('2026-12-31')));

        $s = $this->dep->schedule($a);
        $this->assertSame([2026, 2027, 2028, 2029, 2030], array_column($s, 'year'));
        $this->assertSame([5, 12, 12, 12, 7], array_column($s, 'months'));
        $this->assertEquals([1302083.33, 3125000.00, 3125000.00, 3125000.00, 1822916.67], array_column($s, 'expense'));
        $this->assertEquals([11197916.67, 8072916.67, 4947916.67, 1822916.67, 0.0], array_column($s, 'book_value'));
        $this->assertEquals(12500000.00, round(array_sum(array_column($s, 'expense')), 2));     // total jadwal = harga perolehan, tanpa selisih pembulatan
    }

    public function test_residual_value_is_never_depreciated_below(): void
    {
        $a = $this->laptop(['residual_percent' => 10]);
        $p = $this->dep->profile($a);
        $this->assertEquals(1250000.00, $p['residual']);
        $this->assertEquals(11250000.00, $p['base']);
        $this->assertEquals(2812500.00, $p['per_year']);
        $far = $this->d('2100-12-31');
        $this->assertEquals(1250000.00, $this->dep->bookValue($a, $far));         // berhenti di nilai residu
        $this->assertEquals(11250000.00, round(array_sum(array_column($this->dep->schedule($a), 'expense')), 2));
    }

    public function test_fully_depreciated_asset_stops_and_is_flagged(): void
    {
        $a = $this->laptop(['purchase_date' => '2020-01-10']);
        $this->assertSame(48, $this->dep->months($a, $this->d('2026-06-30')));      // tidak melebihi umur
        $this->assertEquals(12500000.00, $this->dep->accumulated($a, $this->d('2026-06-30')));
        $this->assertEquals(0.0, $this->dep->bookValue($a, $this->d('2026-06-30')));
        $this->assertSame('Habis disusutkan', $this->dep->statusLabel($a, $this->d('2026-06-30')));
        $this->assertSame('Berjalan', $this->dep->statusLabel($a, $this->d('2022-06-30')));
        $this->assertSame('Belum diperoleh', $this->dep->statusLabel($a, $this->d('2019-12-31')));
    }

    public function test_before_purchase_nothing_is_depreciated_and_month_of_purchase_counts_fully(): void
    {
        $a = $this->laptop(['purchase_date' => '2026-01-31']);
        $this->assertSame(0, $this->dep->months($a, $this->d('2025-12-31')));
        $this->assertEquals(0.0, $this->dep->bookValue($a, $this->d('2025-12-31')));
        $this->assertSame(1, $this->dep->months($a, $this->d('2026-01-31')));         // dibeli 31 Jan → Januari sudah penuh 1 bulan
        $this->assertEquals(260416.67, $this->dep->accumulated($a, $this->d('2026-01-31')));
    }

    public function test_threshold_rental_and_missing_data_are_not_depreciated(): void
    {
        $below = $this->laptop(['purchase_price' => 999999]);
        $this->assertSame('expensed', $this->dep->profile($below)['reason']);
        $this->assertSame([], $this->dep->schedule($below));
        $this->assertEquals(0.0, $this->dep->bookValue($below, $this->d('2026-12-31')));
        $edge = $this->laptop(['purchase_price' => 1000000]);                       // tepat di ambang → disusutkan
        $this->assertTrue($this->dep->profile($edge)['depreciable']);
        $this->assertEquals(250000.00, $this->dep->profile($edge)['per_year']);

        config(['depreciation.threshold' => 5000000]);
        $this->assertSame('expensed', $this->dep->profile($edge)['reason']);
        config(['depreciation.threshold' => 1000000]);

        $rent = $this->laptop(['ownership' => 'rental', 'purchase_price' => 0, 'rental_start' => '2026-01-01', 'rental_end' => '2027-12-31', 'rental_monthly_cost' => 500000]);
        $this->assertSame('rental', $this->dep->profile($rent)['reason']);
        $this->assertSame('Sewa (tidak disusutkan)', $this->dep->statusLabel($rent, now()));
        $nodate = $this->laptop(['purchase_date' => null]);
        $this->assertSame('no_cost', $this->dep->profile($nodate)['reason']);
        $this->assertEquals(0.0, $this->dep->accumulated($nodate, now()));
    }

    public function test_overrides_and_category_defaults(): void
    {
        $a = $this->laptop(['useful_life_years' => 2]);
        $this->assertSame(2, $this->dep->profile($a)['years']);
        $this->assertEquals(6250000.00, $this->dep->profile($a)['per_year']);        // override per aset menang

        $b = $this->laptop();
        Category::find($b->category_id)->update(['useful_life_years' => 3, 'residual_percent' => 5]);
        $p = $this->dep->profile($b->fresh());
        $this->assertSame(3, $p['years']);
        $this->assertEquals(625000.00, $p['residual']);                              // 5% dari 12,5 jt
        $this->assertSame(2, $this->dep->profile($a->fresh())['years']);
        Category::find($b->category_id)->update(['useful_life_years' => null]);
        $this->assertSame((int) config('depreciation.default_life_years'), $this->dep->profile($b->fresh())['years']);   // tanpa isian → default
        $server = Asset::whereHas('category', fn ($q) => $q->where('name', 'Server'))->firstOrFail();
        $this->assertSame(5, $this->dep->profile($server)['years']);
    }

    public function test_depreciation_stops_when_asset_is_retired_and_gain_loss_is_computed(): void
    {
        $a = $this->laptop(['status' => 'disposed', 'retired_at' => '2027-03-10']);
        $this->assertSame(8, $this->dep->months($a, $this->d('2030-12-31')));         // Agu 2026 – Mar 2027
        $this->assertEquals(2083333.33, $this->dep->accumulated($a, $this->d('2030-12-31')));
        $this->assertEquals(10416666.67, $this->dep->bookValueAtRetirement($a));
        $this->assertEquals(10416666.67, $this->dep->bookValue($a, $this->d('2027-03-10')));
        $this->assertEquals(0.0, $this->dep->bookValue($a, $this->d('2027-12-31')));   // sudah dilepas → tidak ada nilai
        $this->assertEquals(-1416666.67, $this->dep->gainLoss($a, 9000000));          // rugi pelepasan
        $this->assertEquals(2583333.33, $this->dep->gainLoss($a, 13000000));          // laba pelepasan
        $s = $this->dep->schedule($a);
        $this->assertSame([2026, 2027], array_column($s, 'year'));
        $this->assertSame([5, 3], array_column($s, 'months'));
        $this->assertSame('Dilepas', $this->dep->statusLabel($a, $this->d('2028-12-31')));
        $this->assertFalse($this->dep->isHeld($a, $this->d('2028-12-31')));
    }

    // ---------------------------------------------------------------- disposal & hilang
    public function test_disposal_with_sale_price_records_retirement_date_and_gain_loss(): void
    {
        $this->as($this->staff());
        $a = $this->laptop(['purchase_date' => now()->subMonths(11)->toDateString(), 'purchase_price' => 12000000]);
        $d = app(DisposalService::class)->request($a->id, 'Dijual karena diganti', true);

        $this->as($this->mgmt());
        // metode jual: harga jual wajib
        Livewire::test(ManageDisposals::class)->callAction(TestAction::make('approve')->table($d), ['method' => 'sell'])->assertHasActionErrors(['sale_price']);
        $this->assertSame('pending', $d->fresh()->status);
        Livewire::test(ManageDisposals::class)->callAction(TestAction::make('approve')->table($d), ['method' => 'sell', 'sale_price' => 4000000])->assertHasNoActionErrors();
        $d->refresh();
        $a->refresh();
        $this->assertSame('approved', $d->status);
        $this->assertEquals(4000000, (float) $d->sale_price);
        $this->assertSame(now()->toDateString(), $a->retired_at->toDateString());      // penyusutan berhenti hari persetujuan
        $book = $this->dep->bookValueAtRetirement($a);
        $this->assertEquals(round(4000000 - $book, 2), $this->dep->gainLoss($a, 4000000));
        $html = view('pdf.disposal', ['d' => $d->load(['asset', 'requester', 'approver'])])->render();
        foreach (['Nilai buku saat dilepas', 'Harga jual / lelang', 'Laba / (rugi) pelepasan', 'Harga perolehan'] as $needle) $this->assertStringContainsString($needle, $html);
        Livewire::test(ManageDisposals::class)->assertTableColumnStateSet('gain_loss', $this->dep->gainLoss($a, 4000000), $d);
    }

    public function test_non_sale_disposal_ignores_price_and_negative_price_is_rejected(): void
    {
        $this->as($this->staff());
        $a = $this->laptop();
        $d = app(DisposalService::class)->request($a->id, 'Donasi', true);
        $this->as($this->mgmt());
        try {
            app(DisposalService::class)->approve($d, 'sell', -5);
            $this->fail('harga jual negatif harus ditolak');
        } catch (\InvalidArgumentException) {}
        app(DisposalService::class)->approve($d, 'donate', 123456);
        $this->assertNull($d->fresh()->sale_price);                                    // donasi: tanpa harga jual
        $this->assertSame('disposed', $a->fresh()->status);
    }

    public function test_marking_an_asset_lost_stops_its_depreciation(): void
    {
        $this->as($this->staff());
        $a = $this->laptop(['purchase_date' => now()->subMonths(5)->toDateString()]);
        Livewire::test(ViewAsset::class, ['record' => $a->getKey()])->callAction('lost')->assertHasNoActionErrors();
        $a->refresh();
        $this->assertSame('lost', $a->status);
        $this->assertSame(now()->toDateString(), $a->retired_at->toDateString());
        $this->assertSame('Hilang', $this->dep->statusLabel($a, now()->addYear()));
        $this->assertEquals(0.0, $this->dep->bookValue($a, now()->addYear()));
        $this->assertNotNull($this->dep->bookValueAtRetirement($a));
    }

    // ---------------------------------------------------------------- UI
    public function test_asset_detail_shows_depreciation_card_with_yearly_schedule(): void
    {
        $this->as($this->staff());
        $a = $this->laptop(['asset_tag' => 'DEP-UI-1']);
        $this->get(\App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $a]))->assertOk()
            ->assertSee('Penyusutan & Nilai Buku')->assertSee('Rp 12.500.000')->assertSee('4 tahun')->assertSee('Rp 260.417')
            ->assertSee('2026')->assertSee('2030')->assertSee('Rp 1.302.083')->assertSee('Rp 3.125.000');
        $small = $this->laptop(['purchase_price' => 500000]);
        $this->get(\App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $small]))->assertOk()->assertSee('langsung dibebankan');
        $rent = Asset::where('ownership', 'rental')->firstOrFail();
        $this->get(\App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $rent]))->assertOk()->assertSee('tidak disusutkan');
    }

    public function test_asset_form_validates_and_stores_life_and_residual_overrides(): void
    {
        $this->as($this->staff());
        $cat = Category::where('code_prefix', 'NB')->firstOrFail();
        $base = ['category_id' => $cat->id, 'condition' => 'good', 'purchase_price' => 8000000, 'purchase_date' => '2026-03-01'];
        foreach ([['useful_life_years' => 0], ['useful_life_years' => 51], ['useful_life_years' => 2.5], ['residual_percent' => -1], ['residual_percent' => 101]] as $bad) {
            Livewire::test(CreateAsset::class)->fillForm($base + ['asset_tag' => Asset::generateTag($cat)] + $bad)->call('create')->assertHasFormErrors();
        }
        $tag = Asset::generateTag($cat);
        Livewire::test(CreateAsset::class)->fillForm($base + ['asset_tag' => $tag, 'useful_life_years' => 5, 'residual_percent' => 10])->call('create')->assertHasNoFormErrors();
        $a = Asset::where('asset_tag', $tag)->firstOrFail();
        $this->assertSame([5, 10.0], [$a->useful_life_years, (float) $a->residual_percent]);
        $this->assertSame(5, $this->dep->profile($a)['years']);
        $tag2 = Asset::generateTag($cat);
        Livewire::test(CreateAsset::class)->fillForm($base + ['asset_tag' => $tag2, 'useful_life_years' => '', 'residual_percent' => ''])->call('create')->assertHasNoFormErrors();
        $b = Asset::where('asset_tag', $tag2)->firstOrFail();
        $this->assertNull($b->useful_life_years);                                      // kosong = ikut kategori
        $this->assertNull($b->residual_percent);
    }

    public function test_category_form_has_life_and_residual_only_for_asset_categories(): void
    {
        $this->as($this->admin());
        Livewire::test(ManageCategories::class)->callAction('create', ['name' => 'Aset QA', 'type' => 'asset', 'code_prefix' => 'QAS', 'useful_life_years' => 6, 'residual_percent' => 5])->assertHasNoActionErrors();
        $c = Category::where('name', 'Aset QA')->firstOrFail();
        $this->assertSame([6, 5.0], [$c->useful_life_years, (float) $c->residual_percent]);
        Livewire::test(ManageCategories::class)->callAction('create', ['name' => 'Aset QA 2', 'type' => 'asset', 'useful_life_years' => 99])->assertHasActionErrors(['useful_life_years']);
        Livewire::test(ManageCategories::class)->callAction('create', ['name' => 'Aksesori QA', 'type' => 'accessory', 'useful_life_years' => 7])->assertHasNoActionErrors();
        $this->assertNull(Category::where('name', 'Aksesori QA')->value('useful_life_years'));   // tidak berlaku untuk non-aset
    }

    public function test_asset_list_shows_book_value_column(): void
    {
        $this->as($this->staff());
        $a = Asset::where('ownership', 'owned')->where('purchase_price', '>=', 5000000)->orderByDesc('asset_tag')->firstOrFail();
        Livewire::test(\App\Filament\Resources\Assets\Pages\ListAssets::class)->searchTable($a->asset_tag)
            ->assertTableColumnStateSet('book_value', $this->dep->bookValue($a, now()), $a);
    }

    // ---------------------------------------------------------------- data demo & laporan
    public function test_demo_data_schedules_reconcile_for_every_owned_asset(): void
    {
        $far = $this->d('2200-12-31');
        $depreciable = 0;
        foreach (Asset::with('category')->where('ownership', 'owned')->get() as $a) {
            $p = $this->dep->profile($a);
            $this->assertGreaterThanOrEqual(3, $p['years'], $a->asset_tag);
            if (! $p['depreciable']) continue;
            $depreciable++;
            $sum = round(array_sum(array_column($this->dep->schedule($a), 'expense')), 2);
            $this->assertEquals($this->dep->accumulated($a, $far), $sum, "{$a->asset_tag}: jadwal tidak sama dengan akumulasi");
            $years = array_sum(array_map(fn ($r) => $this->dep->expenseInYear($a, $r['year']), $this->dep->schedule($a)));
            $this->assertEqualsWithDelta($sum, $years, 0.02);
            if (! $a->retired_at) $this->assertEqualsWithDelta($p['residual'], $this->dep->bookValue($a, $far), 0.02);
            $this->assertGreaterThanOrEqual(0, $this->dep->bookValue($a, now()));
            $this->assertLessThanOrEqual($p['cost'], $this->dep->bookValue($a, now()));
        }
        $this->assertGreaterThan(25, $depreciable);
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_yearly_report_reconciles_with_the_service_and_per_asset_rows(): void
    {
        $this->as($this->admin());
        foreach ([2025, 2026, 2027] as $year) {
            [$title, $head, $rows] = ReportCatalog::build('depreciation', ['year' => $year]);
            $this->assertStringContainsString("31 Desember {$year}", $title);
            foreach ($rows as $r) $this->assertCount(count($head), $r);
            $total = end($rows);
            $t = $this->dep->totals(Carbon::create($year, 12, 31));
            $rp = fn ($v) => number_format((float) $v, 0, ',', '.');                  // laporan memakai angka tanpa awalan "Rp"
            $this->assertSame($rp($t['cost']), $total[4], "{$year}: total harga perolehan");
            $this->assertSame($rp($t['accumulated']), $total[8], "{$year}: total akumulasi");
            $this->assertSame($rp($t['year_expense']), $total[9], "{$year}: total penyusutan tahun {$year}");
            $this->assertSame($rp($t['book']), $total[10], "{$year}: total nilai buku");
            $this->assertEqualsWithDelta($t['cost'] - $t['accumulated'], $t['book'], 0.05, 'harga perolehan − akumulasi = nilai buku');
        }
        // aset sewa tidak ada di laporan nilai aset
        $tags = array_column(ReportCatalog::build('depreciation', ['year' => 2026])[2], 0);
        foreach (Asset::where('ownership', 'rental')->pluck('asset_tag') as $tag) $this->assertNotContains($tag, $tags);
        // filter kategori
        $lpt = Category::where('code_prefix', 'NB')->first();
        $rows = ReportCatalog::build('depreciation', ['year' => 2026, 'category_id' => $lpt->id])[2];
        $this->assertCount(Asset::where('ownership', 'owned')->where('category_id', $lpt->id)->count() + 1, $rows);
        $this->get('/reports/depreciation.xlsx?year=2026')->assertOk();
        $this->get('/reports/depreciation.pdf?year=2026')->assertOk();
    }

    public function test_summary_report_has_one_column_per_year_and_totals_match(): void
    {
        $this->as($this->admin());
        [$title, $head, $rows] = ReportCatalog::build('depreciation_summary', []);
        $years = array_values(array_filter(array_map(fn ($h) => preg_match('/^Susut (\d{4})/', $h, $m) ? (int) $m[1] : null, $head)));
        $this->assertNotEmpty($years);
        $this->assertSame(range($years[0], end($years)), $years);                      // tahun berurutan tanpa celah
        foreach ($rows as $r) $this->assertCount(count($head), $r);
        $total = end($rows);
        $expected = 0.0;
        foreach (Asset::with('category')->where('ownership', 'owned')->get() as $a) $expected += array_sum(array_column($this->dep->schedule($a), 'expense'));
        $this->assertSame(number_format($expected, 0, ',', '.'), $total[count($total) - 1]);
        $this->get('/reports/depreciation_summary.xlsx')->assertOk();
        $this->get('/reports/depreciation_summary.pdf')->assertOk();
    }

    public function test_reports_page_forwards_the_selected_year_and_lists_the_new_reports(): void
    {
        $this->as($this->staff());
        $page = Livewire::test(Reports::class)->set('year', 2027);
        $this->assertSame(2027, $page->instance()->query()['year']);
        $this->assertArrayHasKey('depreciation', $page->instance()->catalog());
        $this->assertArrayHasKey('depreciation_summary', $page->instance()->catalog());
        $this->get('/admin/laporan')->assertOk()->assertSee('Nilai Aset per Tahun')->assertSee('Tahun (nilai aset)');
        $this->as($this->plain())->get('/reports/depreciation.xlsx')->assertForbidden();
        $this->as($this->mgmt())->get('/reports/depreciation.pdf')->assertOk();
    }

    public function test_dashboard_shows_book_value_instead_of_purchase_value(): void
    {
        $this->as($this->admin());
        $d = Livewire::test(\App\Filament\Pages\Dashboard::class)->instance()->data();
        $t = $this->dep->totals(now());
        $this->assertEqualsWithDelta($t['book'], $d['book'], 0.01);
        $this->assertEqualsWithDelta($t['year_expense'], $d['depYear'], 0.01);
        $this->assertGreaterThan(0, $d['book']);
        $this->assertLessThan($d['depCost'], $d['book']);                               // nilai buku < harga perolehan
        $this->get('/admin')->assertOk()->assertSee('Nilai buku aset')->assertSee('susut ' . now()->year);
    }
}
