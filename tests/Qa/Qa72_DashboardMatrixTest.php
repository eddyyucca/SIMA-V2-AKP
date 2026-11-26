<?php

namespace Tests\Qa;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Models\Asset;
use Livewire\Livewire;
use Tests\QaTestCase;

/** Kartu "Utilitas": matriks kategori/lokasi x (Dipakai, Perbaikan, Tersedia, Dipakai tapi tanpa nama) dengan sel yang menaut ke daftar Aset. */
class Qa72_DashboardMatrixTest extends QaTestCase
{
    private function listCount(string $url): int
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return (int) Livewire::withQueryParams($q)->test(ListAssets::class)->instance()->getFilteredTableQuery()->count();
    }

    public function test_matrix_numbers_match_the_database_for_category_and_location(): void
    {
        $this->as($this->admin());
        $page = new Dashboard();
        foreach (['category', 'location'] as $by) {
            $m = $page->statusMatrix($by);
            $owned = fn () => Asset::active()->where('ownership', 'owned');
            $this->assertSame($owned()->count(), $m['total']['total'], "{$by}: total (aset milik; sewa dihitung terpisah)");
            foreach (['deployed', 'repair', 'available'] as $st) {
                $this->assertSame($owned()->where('status', $st)->count(), $m['total'][$st], "{$by}: {$st}");
                $this->assertSame($m['total'][$st], array_sum(array_column($m['rows'], $st)), "{$by}: jumlah baris {$st}");
            }
            $this->assertSame($owned()->where('status', 'deployed')->whereNull('employee_id')->count(), $m['total']['deployed_nh']);
        }
    }

    public function test_deployed_without_holder_is_detected_and_its_cell_opens_exactly_those_assets(): void
    {
        $this->as($this->admin());
        $asset = Asset::where('status', 'available')->whereNull('employee_id')->firstOrFail();
        $asset->update(['status' => 'deployed']);                                 // status Dipakai tetapi tanpa pemegang = anomali

        $page = new Dashboard();
        $m = $page->statusMatrix('category');
        $row = collect($m['rows'])->firstWhere('id', $asset->category_id);
        $this->assertGreaterThanOrEqual(1, $row['deployed_nh']);
        $this->assertSame($row['deployed_nh'], $this->listCount($page->assetsUrl(['aktif' => true, 'category_id' => $asset->category_id, 'status' => 'deployed', 'tanpa_pemegang' => true])));

        $html = $this->get(Dashboard::getUrl())->assertOk()->getContent();
        foreach (['Utilitas aset per kategori', 'Utilitas aset per lokasi', 'Dipakai tapi tanpa nama', 'filters%5Btanpa_pemegang%5D%5BisActive%5D=1'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
    }

    public function test_every_matrix_cell_opens_a_list_with_the_same_count(): void
    {
        $this->as($this->admin());
        $page = new Dashboard();
        $m = $page->statusMatrix('category');
        $row = collect($m['rows'])->first(fn ($r) => $r['id'] && $r['deployed'] > 0 && $r['available'] > 0) ?? collect($m['rows'])->firstWhere('id', '!=', null);
        $f = ['aktif' => true, 'category_id' => $row['id']];
        $this->assertSame($row['total'], $this->listCount($page->assetsUrl($f)));
        foreach (['deployed', 'repair', 'available'] as $st) {
            $this->assertSame($row[$st], $this->listCount($page->assetsUrl($f + ['status' => $st])), $st);
        }
    }

    public function test_detail_cards_for_stock_repairs_and_department_breakdown_are_rendered(): void
    {
        $this->as($this->admin());
        $html = $this->get(Dashboard::getUrl())->assertOk()->getContent();
        foreach (['Stok perlu dibeli', 'Perbaikan terbuka', 'Aset per Department'] as $needle) $this->assertStringContainsString($needle, $html);
        $dp = (new Dashboard())->departmentData();
        $this->assertLessThanOrEqual(3, count($dp['topCats']));
        $this->assertNotEmpty($dp['topCats']);
    }
}
