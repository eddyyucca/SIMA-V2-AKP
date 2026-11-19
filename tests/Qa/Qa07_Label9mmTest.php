<?php

namespace Tests\Qa;

use App\Models\Accessory;
use App\Models\Component;
use App\Models\Consumable;
use App\Services\LabelService;
use Tests\QaTestCase;

class Qa07_Label9mmTest extends QaTestCase
{
    public static function kinds(): array
    {
        return [['accessory'], ['component']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('kinds')]
    public function test_default_label_for_stock_items_is_9mm_tape(string $kind): void
    {
        $this->as($this->staff());
        $this->assertSame(['tape' => 9, 'length' => 30], LabelService::defaults($kind));
        $ids = LabelService::model($kind)::orderBy('id')->limit(4)->pluck('id')->implode(',');
        $res = $this->get(route('labels.print', ['kind' => $kind, 'ids' => $ids]));       // tanpa tape/length → bawaan 9 mm
        $res->assertOk()->assertHeader('content-type', 'application/pdf');
        $pdf = $res->getContent();
        preg_match('#/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+([\d.]+)\s+([\d.]+)\s*\]#', $pdf, $m);
        $this->assertEqualsWithDelta(30, $m[1] / 2.834645669, 0.3);
        $this->assertEqualsWithDelta(9, $m[2] / 2.834645669, 0.3);
        $this->assertSame(4, preg_match_all('#/Type\s*/Page\b#', $pdf));
    }

    public function test_compact_geometry_for_9mm_is_symmetric_and_inside_the_tape(): void
    {
        $G = LabelService::compactGeometry(9, 45);
        $this->assertTrue($G['border']);
        $this->assertSame(2, $G['rows']);
        $this->assertEqualsWithDelta($G['e'], (9 - $G['qr']) / 2, 0.01);                                // QR di tengah vertikal
        $this->assertEqualsWithDelta(2 * $G['rowH'], $G['qr'], 0.02);                                  // dua baris = tinggi QR
        $this->assertGreaterThan(0, $G['textW']);
        $this->assertLessThanOrEqual(45, $G['textX'] + $G['textW'] + $G['e']);                          // tidak keluar label
        $this->assertGreaterThan($G['inset'] + $G['bw'], $G['e']);                                      // QR tidak menyentuh bingkai
        $this->assertGreaterThanOrEqual(6.0, $G['qr']);                                                 // QR cukup besar untuk dipindai
        $G6 = LabelService::compactGeometry(6, 30);
        $this->assertSame(1, $G6['rows']);
    }

    public function test_company_and_code_fit_on_two_rows_and_long_names_are_cut(): void
    {
        $G = LabelService::compactGeometry(9, 45);
        $maxPt = $G['rowH'] * 2.35;
        foreach ([Accessory::first(), Component::first()] as $m) {
            $code = strtoupper($m->code);
            $co = LabelService::shortCompany();
            $f = min(LabelService::fit($co, $G['textW'], $maxPt), LabelService::fit($code, $G['textW'], $maxPt));
            $this->assertLessThanOrEqual($G['textW'], LabelService::textWidthMm($co, $f) + 0.001);
            $this->assertLessThanOrEqual($G['textW'], LabelService::textWidthMm($code, $f) + 0.001);
        }
        $long = LabelService::truncateToFit('TONER HP LASERJET PRO M404DN KOMPATIBEL SEMUA SERI PANJANG SEKALI', $G['textW'], 5.0);
        $this->assertLessThanOrEqual($G['textW'], LabelService::textWidthMm($long, 5.0));
        // tinggi huruf kapital tidak melebihi tinggi baris
        $this->assertLessThan($G['rowH'], $maxPt * 0.3528 * 0.72);
    }

    public function test_print_page_switches_defaults_between_asset_and_stock_items(): void
    {
        $this->as($this->staff());
        $t = \Livewire\Livewire::test(\App\Filament\Pages\PrintLabels::class)->assertSet('tape', 24)->assertSet('length', 70);
        $t->set('kind', 'accessory')->assertSet('tape', 9)->assertSet('length', 30);
        $t->set('kind', 'asset')->assertSet('tape', 24)->assertSet('length', 70);
    }

    public function test_default_asset_label_is_wide_enough_for_a_large_company_name(): void
    {
        $this->assertSame(70, LabelService::defaults('asset')['length']);
        $G = LabelService::frame(24, 70);
        $co = strtoupper((string) config('labels.company'));
        $company = LabelService::fit($co, $G['textW'], $G['h1'] * 2.45);
        $this->assertGreaterThanOrEqual(8.0, $company);                           // 70 mm (sesuai driver Brother): nama PT tetap terbaca
        $this->assertLessThanOrEqual($G['textW'], LabelService::textWidthMm($co, $company));   // tetap muat, tidak terpotong
        // data yang benar-benar dikirim ke tampilan: kode & serial = 90% dari nama PT
        $seen = null;
        \Illuminate\Support\Facades\View::composer('pdf.label-framed', function ($v) use (&$seen) { $seen = $v->getData()['labels'][0]; });
        $asset = \App\Models\Asset::whereNotNull('serial_number')->firstOrFail();
        app(LabelService::class)->pdf('asset', collect([$asset]), 24, 70)->output();
        $this->assertEqualsWithDelta(min($seen['f1'] * 0.9, $G['h2'] * 2.45), $seen['f2'], 0.15);   // 90% nama PT, atau batas tinggi baris bila label panjang
        $this->assertGreaterThan($seen['f2'], $seen['f1']);
        $this->assertSame($seen['f2'], $seen['f3']);
    }

    private function pageSize(string $pdf): array
    {
        preg_match('/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+([\d.]+)\s*\]/', $pdf, $m);
        return [round((float) $m[1] / 2.834645669, 1), round((float) $m[2] / 2.834645669, 1)];   // mm
    }

    public function test_label_page_orientation_option_for_drivers_that_only_accept_upright_media(): void
    {
        $this->as($this->staff());
        $acc = \App\Models\Accessory::orderBy('id')->take(2)->pluck('id')->implode(',');
        $url = fn (array $q) => route('labels.print', ['kind' => 'accessory', 'ids' => $acc, 'tape' => 9, 'length' => 45] + $q);

        $this->assertSame([45.0, 9.0], $this->pageSize($this->get($url([]))->assertOk()->getContent()));                         // bawaan: mendatar
        $this->assertSame([45.0, 9.0], $this->pageSize($this->get($url(['orient' => 'landscape']))->getContent()));
        $this->assertSame([9.0, 45.0], $this->pageSize($this->get($url(['orient' => 'portrait']))->assertOk()->getContent()));     // tegak: lebar = pita
        $this->assertSame([9.0, 45.0], $this->pageSize($this->get($url(['orient' => 'portrait-ccw']))->assertOk()->getContent()));
        $this->assertSame([45.0, 9.0], $this->pageSize($this->get($url(['orient' => 'ngawur']))->assertOk()->getContent()));       // tidak sah -> mendatar

        // jumlah halaman tetap satu per label di semua orientasi (2 barang x 2 salinan = 4 halaman)
        foreach (['landscape', 'portrait', 'portrait-ccw'] as $o) {
            $pdf = $this->get($url(['orient' => $o, 'copies' => 2]))->getContent();
            $this->assertSame(4, preg_match_all('/\/Type\s*\/Page[^s]/', $pdf), $o);
        }
        // label aset 24 mm juga bisa tegak
        $a = \App\Models\Asset::whereNotNull('serial_number')->firstOrFail();
        $r = $this->get(route('labels.print', ['kind' => 'asset', 'ids' => $a->id, 'tape' => 24, 'length' => 85, 'orient' => 'portrait']))->assertOk()->getContent();
        $this->assertSame([24.0, 85.0], $this->pageSize($r));

        // halaman Cetak Label: pilihan orientasi ikut ke tautan PDF
        $t = \Livewire\Livewire::test(\App\Filament\Pages\PrintLabels::class)->set('kind', 'accessory')->set('selected', [1])->set('orient', 'portrait');
        $this->assertStringContainsString('orient=portrait', $t->instance()->url());
        $t->assertSee('Orientasi halaman');
    }
}
