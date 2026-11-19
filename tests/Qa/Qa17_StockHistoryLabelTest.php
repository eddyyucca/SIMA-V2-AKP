<?php

namespace Tests\Qa;

use App\Filament\Pages\StockHistory\AccessoryStockHistory;
use App\Filament\Pages\StockHistory\ComponentStockHistory;
use App\Filament\Pages\StockHistory\ConsumableStockHistory;
use App\Filament\Resources\StockReceipts\StockReceiptResource;
use Tests\QaTestCase;

/** Riwayat Stok: tiap jenis barang punya halamannya sendiri (tidak digabung) dan tidak ada di side menu. */
class Qa17_StockHistoryLabelTest extends QaTestCase
{
    public function test_history_pages_are_named_per_kind_and_purchase_history_stays_separate(): void
    {
        $this->assertSame('Riwayat Stok Accessories', (string) (new \ReflectionClass(AccessoryStockHistory::class))->getStaticPropertyValue('title'));
        $this->assertSame('Riwayat Stok Consumable', (string) (new \ReflectionClass(ConsumableStockHistory::class))->getStaticPropertyValue('title'));
        $this->assertSame('Riwayat Stok Komponen', (string) (new \ReflectionClass(ComponentStockHistory::class))->getStaticPropertyValue('title'));
        $this->assertSame('Riwayat Pembelian Stok', StockReceiptResource::getPluralModelLabel());     // nama menu pembelian tetap berbeda
    }

    public function test_pages_open_for_it_and_management_but_not_for_plain_users_and_not_in_the_sidebar(): void
    {
        foreach ([AccessoryStockHistory::class => 'Accessories', ConsumableStockHistory::class => 'Consumable', ComponentStockHistory::class => 'Komponen'] as $page => $label) {
            foreach ([$this->staff(), $this->admin(), $this->mgmt()] as $u) {
                $html = $this->as($u)->get($page::getUrl())->assertOk()->getContent();
                $this->assertStringContainsString('Riwayat Stok ' . $label, $html);
                $this->assertStringNotContainsString('Pergerakan Stok', $html);
            }
            $this->as($this->plain())->get($page::getUrl())->assertForbidden();
        }
        foreach (['/admin', '/admin/assets', '/admin/laporan', '/admin/stock-receipts'] as $p) {
            $html = $this->as($this->staff())->get($p)->assertOk()->getContent();
            foreach (['riwayat-stok-accessories', 'riwayat-stok-consumable', 'riwayat-stok-komponen'] as $slug) $this->assertStringNotContainsString($slug, $html, "$p tidak boleh memuat $slug di menu samping");
        }
    }

    public function test_menu_buttons_point_to_their_own_history(): void
    {
        foreach (['/admin/accessories' => AccessoryStockHistory::class, '/admin/consumables' => ConsumableStockHistory::class, '/admin/components' => ComponentStockHistory::class] as $menu => $page) {
            $this->as($this->staff())->get($menu)->assertOk()->assertSee('Riwayat Stok')->assertSee(parse_url($page::getUrl(), PHP_URL_PATH));
        }
    }
}
