<?php

namespace Tests\Qa;

use App\Models\Asset;
use App\Models\Consumable;
use App\Services\LabelService;
use Tests\QaTestCase;

class Qa06_LabelLayoutTest extends QaTestCase
{
    public function test_frame_geometry_is_exact_and_fits_inside_the_tape(): void
    {
        foreach ([[24, 73], [18, 60], [24, 50]] as [$tape, $len]) {
            $G = LabelService::frame($tape, $len);
            // tinggi tiga baris + garis pembatas = tinggi dalam kotak kiri
            $this->assertEqualsWithDelta($G['ih'], $G['h1'] + $G['h2'] + $G['h3'] + 2 * $G['bw'], 0.02, "{$tape}x{$len}: tinggi baris");
            // satu bingkai luar: bagian dalam = bingkai dikurangi dua garis luar; sel QR persegi setinggi bagian dalam
            $this->assertEqualsWithDelta($G['ch'] - 2 * $G['ob'], $G['ih'], 0.001);
            $this->assertEqualsWithDelta($G['ih'], $G['qb'], 0.001);
            $this->assertEqualsWithDelta($len, $G['iw'] + 2 * $G['ob'] + 2 * $G['c'], 0.02, "{$tape}x{$len}: lebar bingkai + margin = panjang label");
            $this->assertEqualsWithDelta($G['iw'], $G['leftW'] + $G['qb'], 0.02, 'kolom kiri + sel QR mengisi bagian dalam bingkai');
            // simetris: margin kanan = margin kiri, atas = bawah (garis luar dihitung)
            $this->assertEqualsWithDelta($G['c'], $len - ($G['qx'] + $G['qb']) - $G['ob'], 0.02, "{$tape}x{$len}: margin kanan = margin kiri");
            $this->assertEqualsWithDelta($G['c'], ($tape - $G['ch']) / 2, 0.01, "{$tape}x{$len}: margin atas = margin bawah");
            $this->assertGreaterThan($G['bw'], $G['ob'], 'garis luar tidak lebih tipis dari garis pemisah');
            $this->assertArrayNotHasKey('o2', $G, 'tidak ada lagi bingkai ganda');
            $this->assertGreaterThan(0, $G['textW']);
        }
    }

    public function test_text_never_overflows_its_row(): void
    {
        $G = LabelService::frame(24, 73);
        $texts = ['PT. ADHI KARTIKO PRATAMA', 'LPT-2026-001', 'SN-LNV-0001', 'WWWWWWWWWWWWWWWWWWWWWWWWWWWW', 'IIIIIIIIIIIIIIIIIIIIIIIII', 'NAMA CONSUMABLE YANG SANGAT PANJANG SEKALI UNTUK DIUJI…'];
        foreach ($texts as $t) {
            $t = LabelService::truncateToFit($t, $G['textW']);       // teks panjang dipotong dulu agar tetap terbaca
            $pt = LabelService::fit($t, $G['textW'], 40);
            $this->assertGreaterThanOrEqual(6.5, $pt, "'{$t}' terlalu kecil untuk dibaca");
            $w = LabelService::textWidthMm($t, $pt);
            $this->assertLessThanOrEqual($G['textW'], $w + 0.001, "'{$t}' melebihi lebar kotak ({$w} > {$G['textW']})");
            if ($pt < 40 && $pt > 4.0) $this->assertGreaterThan($G['textW'] * 0.9, $w, "'{$t}' seharusnya mengisi ≥ 90% lebar");
        }
        // tinggi huruf kapital (≈ 72% ukuran huruf) tidak melebihi tinggi baris
        $max = $G['h2'] * 2.45;
        $this->assertLessThan($G['h2'], $max * 0.3528 * 0.72);
        $this->assertGreaterThanOrEqual(4.0, LabelService::fit(str_repeat('W', 200), $G['textW'], $max));
    }

    public function test_asset_label_has_no_captions_and_correct_values(): void
    {
        $this->as($this->staff());
        $a = Asset::orderBy('id')->first();
        $pdf = app(LabelService::class)->pdf('asset', collect([$a]), 24, 73);
        $html = view('pdf.label-framed', $this->viewData($a))->render();
        $this->assertStringNotContainsString('NO. ASET', $html);
        $this->assertStringNotContainsString('S/N', $html);
        $this->assertStringContainsString($a->asset_tag, $html);
        $this->assertStringContainsString(strtoupper($a->serial_number), $html);
        $this->assertStringContainsString('PT. ADHI KARTIKO PRATAMA', $html);
        $this->assertStringStartsWith('%PDF', $pdf->output());
        // satu bingkai saja: tepat satu elemen berbingkai luar, tanpa kotak kiri / kotak QR terpisah
        $this->assertSame(1, substr_count($html, 'border:' . $this->viewData($a)['G']['ob'] . 'mm solid'));
        $this->assertSame(1, substr_count($html, 'border-left:'));
    }

    private function viewData(Asset $a): array
    {
        $G = LabelService::frame(24, 73);
        $company = strtoupper(config('labels.company'));
        $c3 = strtoupper($a->serial_number);
        $f = min(LabelService::fit($a->asset_tag, $G['textW'], 40), LabelService::fit($c3, $G['textW'], 40));
        return ['G' => $G, 'tape' => 24, 'length' => 73, 'labels' => [[
            'company' => $company, 'c2cap' => '', 'c2' => $a->asset_tag, 'c3cap' => '', 'c3' => $c3, 'f1' => 10, 'f2' => $f, 'f3' => $f, 'qrsvg' => 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=',
        ]]];
    }

    public function test_other_kinds_use_code_and_name_rows(): void
    {
        $this->as($this->staff());
        $c = \App\Models\Accessory::orderBy('id')->first();
        $res = $this->get(route('labels.print', ['kind' => 'accessory', 'ids' => (string) $c->id, 'tape' => 24, 'length' => 73]));
        $res->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }
}
