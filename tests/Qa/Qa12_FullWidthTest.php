<?php

namespace Tests\Qa;

use Tests\QaTestCase;

class Qa12_FullWidthTest extends QaTestCase
{
    public static function pages(): array
    {
        return array_map(fn ($p) => [$p], ['/admin', '/admin/assets', '/admin/assets/create', '/admin/assets/1', '/admin/assets/1/edit', '/admin/aset-sewa', '/admin/aset-sewa/create',
            '/admin/accessories', '/admin/consumables', '/admin/components', '/admin/licenses', '/admin/categories', '/admin/employees', '/admin/brands', '/admin/asset-models',
            '/admin/repairs', '/admin/disposals', '/admin/stock-opnames', '/admin/asset-assignments', '/admin/riwayat-stok-accessories', '/admin/riwayat-stok-consumable', '/admin/riwayat-stok-komponen', '/admin/stock-receipts', '/admin/scan',
            '/admin/offboarding', '/admin/barang-saya', '/admin/laporan', '/admin/import-aset', '/admin/cetak-label']);
    }

    public function test_panel_is_configured_for_full_width(): void
    {
        $panel = \Filament\Facades\Filament::getPanel('admin');
        $this->assertSame(\Filament\Support\Enums\Width::Full, $panel->getMaxContentWidth());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_every_page_uses_the_full_content_width(string $path): void
    {
        $html = $this->as($this->admin())->get($path)->assertOk()->getContent();
        // lebar konten halaman: kelas fi-width-* pada pembungkus utama (bukan modal/notifikasi)
        preg_match_all('/<(?:main|div)[^>]*class="[^"]*\bfi-width-([a-z0-9]+)\b[^"]*"[^>]*>/', $html, $m);
        $this->assertNotEmpty($m[1], "{$path}: kelas lebar tidak ditemukan");
        $this->assertContains('full', $m[1], "{$path}: lebar konten bukan penuh (" . implode(',', array_unique($m[1])) . ')');
        $this->assertNotContains('7xl', $m[1], "{$path}: masih dibatasi 7xl");
    }
}
