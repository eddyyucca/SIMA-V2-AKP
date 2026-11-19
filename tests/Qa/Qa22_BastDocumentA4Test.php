<?php

namespace Tests\Qa;

use App\Filament\Pages\Settings;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Setting;
use App\Services\AssetService;
use App\Support\BastDocument;
use Livewire\Livewire;
use Tests\QaTestCase;

class Qa22_BastDocumentA4Test extends QaTestCase
{
    private function pages(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page(?!s)#', $pdf);
    }

    private function html(AssetAssignment $a, bool $form = false): string
    {
        return view('pdf.bast', ['a' => $a->loadMissing(['asset.category', 'from.department', 'to.department', 'location', 'creator']), 'sigReceiver' => null, 'sigIt' => null, 'form' => $form])->render();
    }

    private function movement(string $type): AssetAssignment
    {
        $this->as($this->staff());
        $svc = app(AssetService::class);
        $andi = $this->emp('Andi Saputra');
        if ($type === 'checkout') return $svc->checkout($this->freeAsset(), $andi, ['condition' => 'good']);
        $asset = $this->freeAsset();
        $svc->checkout($asset, $andi, ['condition' => 'good']);
        return $type === 'checkin'
            ? $svc->checkin($asset->fresh(), ['condition' => 'good'])
            : $svc->transfer($asset->fresh(), $this->emp('Siti Rahma'), ['condition' => 'good']);
    }

    public function test_document_follows_the_company_form_sections_in_order(): void
    {
        $a = $this->movement('checkout');
        $html = $this->html($a->fresh());
        $order = ['PT. ADHI KARTIKO PRATAMA TBK', 'BERITA ACARA SERAH TERIMA ASSET IT', 'No. Dokumen', 'Revisi', 'Status', 'DATA PENYERAH', 'DATA PENERIMA', 'DETAIL ASSET',
            'Purchase Order', 'Manufacturer', 'Merk / Model', 'Purchase Cost', 'Kondisi', 'IDENTITAS ASSET', 'ASSET TAG:', 'SERIAL NUMBER:', 'KELENGKAPAN ASSET',
            'PERNYATAAN DAN TANGGUNG JAWAB', 'TANDA TANGAN', 'Menyerahkan', 'Menerima', 'Menyetujui', 'Mengetahui', 'Superintendent', 'Manager Ekspat', 'GM Ekspat'];
        $pos = 0;
        foreach ($order as $needle) {
            $found = strpos($html, $needle, $pos);
            $this->assertNotFalse($found, "bagian '{$needle}' hilang atau urutannya salah");
            $pos = $found;
        }
        $this->assertStringNotContainsString('MASA PENGGUNAAN', $html, 'masa penggunaan dihapus');
        $this->assertStringNotContainsString('Tanggal Pengembalian', $html);
        $this->assertStringContainsString($a->bast_number, $html);
        $this->assertStringContainsString($a->assigned_at->format('d-m-Y'), $html);
        foreach (BastDocument::STATEMENTS as $s) $this->assertStringContainsString($s, $html);
        $this->assertCount(7, BastDocument::STATEMENTS);
    }

    public function test_asset_details_identity_and_label_are_filled(): void
    {
        $a = $this->movement('checkout');
        $asset = $a->asset;
        $asset->update(['po_pr_no' => 'PO-2026-77', 'purchase_price' => 19600000, 'serial_number' => 'SN-TEST-XYZ']);
        $html = $this->html($a->fresh());
        foreach (['PO-2026-77', 'Rp 19.600.000', 'SN-TEST-XYZ', $asset->asset_tag, (string) $asset->brand, 'data:image/svg+xml;base64', 'Tidak Ada Kelengkapan Asset'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringContainsString(strtoupper((string) config('labels.company')), $html, 'label meniru label cetak (perusahaan, kode, serial, QR)');
        $this->assertStringContainsString('data:image/png;base64', $html, 'logo AKP tertanam di kop');
    }

    public function test_parties_follow_the_transaction_type(): void
    {
        $andi = $this->emp('Andi Saputra');
        $andi->update(['nik' => 'AKP-ANDI-1', 'position' => 'Surveyor']);

        $out = BastDocument::build($this->movement('checkout')->fresh()->load(['asset.category', 'to.department', 'creator']));
        $this->assertSame('IT', $out['sig']['giver_role']);
        $this->assertSame('Pengguna', $out['sig']['taker_role']);
        $this->assertSame($this->staff()->name, $out['giver']['name']);
        $this->assertSame('Andi Saputra', $out['taker']['name']);
        $this->assertSame('AKP-ANDI-1', $out['taker']['nik']);

        $in = BastDocument::build($this->movement('checkin')->fresh()->load(['asset.category', 'from.department', 'creator']));
        $this->assertSame(['Pengguna', 'IT'], [$in['sig']['giver_role'], $in['sig']['taker_role']]);
        $this->assertSame('Andi Saputra', $in['giver']['name']);
        $this->assertSame($this->staff()->name, $in['taker']['name']);
        $this->assertStringContainsString('PENGEMBALIAN', $in['title']);

        $tr = BastDocument::build($this->movement('transfer')->fresh()->load(['asset.category', 'from.department', 'to.department', 'creator']));
        $this->assertSame('Andi Saputra', $tr['giver']['name']);
        $this->assertSame('Siti Rahma', $tr['taker']['name']);
        $this->assertStringContainsString('MUTASI', $tr['title']);
    }

    public function test_real_pdf_is_a_single_a4_page_for_every_type_and_for_the_form(): void
    {
        $svc = app(AssetService::class);
        foreach (['checkout', 'checkin', 'transfer'] as $type) {
            $a = $this->movement($type)->fresh();
            $svc->makeBast($a);
            $pdf = \Storage::disk('local')->get($a->fresh()->bast_pdf_path);
            $this->assertStringStartsWith('%PDF', $pdf);
            $this->assertSame(1, $this->pages($pdf), "BAST {$type} harus 1 halaman");
            $this->assertMatchesRegularExpression('#/MediaBox\s*\[\s*0(\.0+)?\s+0(\.0+)?\s+595\.2\d*\s+841\.8\d*\s*\]#', $pdf, 'ukuran A4 portrait');
        }
        $this->as($this->staff());
        $asset = $this->freeAsset();
        foreach (['checkout', 'transfer', 'checkin'] as $type) {
            $pdf = $svc->bastFormPdf($asset, $type, null);
            $this->assertSame(1, $this->pages($pdf), "formulir {$type} harus 1 halaman");
        }
    }

    public function test_long_values_still_fit_on_one_page(): void
    {
        $this->as($this->staff());
        $asset = $this->freeAsset();
        $asset->update(['brand' => str_repeat('Merek', 8), 'model' => str_repeat('Model ', 12), 'po_pr_no' => 'PO/' . str_repeat('1234567890', 5), 'serial_number' => str_repeat('SN', 20)]);
        $andi = $this->emp('Andi Saputra');
        $andi->update(['name' => 'Andi Saputra Dengan Nama Sangat Panjang Sekali Untuk Pengujian Tata Letak', 'position' => str_repeat('Jabatan Panjang ', 5)]);
        $a = app(AssetService::class)->checkout($asset->fresh(), $andi->fresh(), ['condition' => 'good']);
        $pdf = \Storage::disk('local')->get($a->fresh()->bast_pdf_path);
        $this->assertSame(1, $this->pages($pdf));
    }

    public function test_form_mode_blanks_only_the_missing_party(): void
    {
        $this->as($this->staff());
        $asset = $this->freeAsset();
        $dots = str_repeat('.', 28);
        $mk = function (string $type, $to) use ($asset) {
            $a = new AssetAssignment(['asset_id' => $asset->id, 'type' => $type, 'condition' => $asset->condition, 'assigned_at' => now(), 'bast_number' => '....']);
            $a->setRelation('asset', $asset->loadMissing('category'));
            $a->setRelation('from', null); $a->setRelation('to', $to); $a->setRelation('location', null); $a->setRelation('creator', auth()->user());
            return $a;
        };
        $blank = BastDocument::build($mk('checkout', null), true);
        $this->assertSame($dots, $blank['taker']['name']);
        $this->assertSame($dots, $blank['taker']['nik']);
        $this->assertSame($this->staff()->name, $blank['giver']['name'], 'IT pencetak terisi otomatis');
        $filled = BastDocument::build($mk('checkout', $this->emp('Andi Saputra')), true);
        $this->assertSame('Andi Saputra', $filled['taker']['name']);
    }

    public function test_approver_names_are_configurable_in_settings_and_appear_in_pdf(): void
    {
        $this->as($this->admin());
        $this->assertSame('M. RASYID RIDHO', BastDocument::setting('bast_superintendent'));            // bawaan mengikuti contoh formulir
        $page = Livewire::test(Settings::class)->assertSet('bast.bast_manager', 'TOTOK SUPRAPTO');
        $page->set('bast.bast_company', 'PT. CONTOH UJI')->set('bast.bast_manager', 'Budi Manager Baru')->set('bast.bast_revision', 'Rev.03')
            ->call('saveBast')->assertHasNoErrors();
        $this->assertSame('Budi Manager Baru', Setting::get('bast_manager'));
        $a = $this->movement('checkout')->fresh();
        $html = $this->html($a);
        foreach (['PT. CONTOH UJI', 'Budi Manager Baru', 'Rev.03'] as $needle) $this->assertStringContainsString($needle, $html);
        $this->assertStringNotContainsString('TOTOK SUPRAPTO', $html);

        $this->as($this->admin());
        Livewire::test(Settings::class)->set('bast.bast_company', '')->call('saveBast')->assertHasErrors(['bast.bast_company' => 'required']);
        Livewire::test(Settings::class)->set('bast.bast_gm', str_repeat('x', 101))->call('saveBast')->assertHasErrors(['bast.bast_gm']);
        $this->as($this->staff());
        $this->assertFalse(Settings::canAccess());
    }
}
