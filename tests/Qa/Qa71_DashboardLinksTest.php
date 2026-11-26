<?php

namespace Tests\Qa;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Models\Asset;
use Livewire\Livewire;
use Tests\QaTestCase;

/** Kartu Dashboard menjadi tautan ke daftar Aset dengan filter; jumlah baris di daftar harus sama dengan angka di kartu. */
class Qa71_DashboardLinksTest extends QaTestCase
{
    /** Jumlah baris daftar Aset bila dibuka dengan query string ?filters[...] hasil tautan Dashboard. */
    private function listCount(string $url): int
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return (int) Livewire::withQueryParams($q)->test(ListAssets::class)->instance()->getFilteredTableQuery()->count();
    }

    private function dashboard(): Dashboard
    {
        $this->as($this->admin());
        $page = new Dashboard();

        return $page;
    }

    public function test_dashboard_renders_links_with_filters_on_every_card(): void
    {
        $this->as($this->admin());
        $html = $this->get(Dashboard::getUrl())->assertOk()->getContent();
        foreach (['deployed', 'available', 'repair'] as $status) {
            $this->assertStringContainsString('filters%5Bstatus%5D%5Bvalue%5D=' . $status, $html, "kartu {$status} harus bertautan");
        }
        $this->assertStringContainsString('filters%5Baktif%5D%5BisActive%5D=1', $html, 'kartu Total aset');
        $this->assertStringContainsString('filters%5Blow%5D%5BisActive%5D=1', $html, 'kartu Stok ≤ minimum');
        $this->assertStringContainsString('filters%5Bcategory_id%5D%5Bvalue%5D=', $html, 'legenda kategori');
        $this->assertStringContainsString('filters%5Bdepartment%5D%5Bvalue%5D=', $html, 'baris department');
    }

    public function test_status_cards_open_a_list_with_the_same_count(): void
    {
        $page = $this->dashboard();
        foreach (['deployed', 'available', 'repair', 'lost', 'borrowed'] as $status) {
            $this->assertSame(Asset::where('ownership', 'owned')->where('status', $status)->count(), $this->listCount($page->assetsUrl(['status' => $status])), $status);
        }
        $this->assertSame(Asset::active()->where('ownership', 'owned')->count(), $this->listCount($page->assetsUrl(['aktif' => true])), 'total aktif');
    }

    public function test_category_location_and_department_rows_open_a_list_with_the_same_count(): void
    {
        $page = $this->dashboard();
        $active = fn () => Asset::active()->where('ownership', 'owned');

        $cat = $active()->whereNotNull('category_id')->value('category_id');
        $this->assertSame($active()->where('category_id', $cat)->count(), $this->listCount($page->assetsUrl(['aktif' => true, 'category_id' => $cat])));

        $loc = $active()->whereNotNull('location_id')->value('location_id');
        $this->assertSame($active()->where('location_id', $loc)->count(), $this->listCount($page->assetsUrl(['aktif' => true, 'location_id' => $loc])));

        $dep = \App\Models\Employee::whereNotNull('department_id')->whereHas('assets')->value('department_id');
        $this->assertNotNull($dep);
        $this->assertSame($active()->whereHas('employee', fn ($e) => $e->where('department_id', $dep))->count(), $this->listCount($page->assetsUrl(['aktif' => true, 'department' => $dep])));
        $this->assertSame($active()->whereNull('employee_id')->count(), $this->listCount($page->assetsUrl(['aktif' => true, 'department' => 'none'])), 'belum dipegang');
        $this->assertSame($active()->whereHas('employee', fn ($e) => $e->whereNull('department_id'))->count(), $this->listCount($page->assetsUrl(['aktif' => true, 'department' => 'nodept'])), 'pemegang tanpa department');
    }
}
