<?php

namespace Tests\Qa;

use App\Filament\Pages\ScanPage;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetTagAlias;
use App\Models\Category;
use App\Models\Employee;
use App\Services\LabelService;
use App\Support\AssetTagMigrator;
use Livewire\Livewire;
use Tests\QaTestCase;

/** Scan menampilkan pemegang aset; kode aset NB 4 digit; kode lama tetap bisa discan lewat riwayat kode. */
class Qa50_ScanHolderCardAndTagsTest extends QaTestCase
{
    private function asset(string $tag, int $catId, array $o = []): Asset
    {
        return Asset::create($o + ['asset_tag' => $tag, 'category_id' => $catId, 'status' => 'available', 'condition' => 'good']);
    }

    // ---------------------------------------------------------------- penomoran ulang (campur LPT / NB seperti data asli)
    public function test_renumber_pads_zeros_converts_old_codes_and_keeps_history(): void
    {
        $cat = Category::create(['name' => 'Kategori XQ', 'type' => 'asset', 'code_prefix' => 'XQ']);
        $a = $this->asset('AKP-IT-XQ-001', $cat->id);                 // sudah format benar, kurang nol
        $b = $this->asset('AKP-IT-XQ-002', $cat->id);
        $c = $this->asset('AKP-IT-OLD-001', $cat->id);   // kode kategori lama (seperti AKP-IT-LPT-001)
        $d = $this->asset('AKP-IT-OLD-002', $cat->id);
        $e = $this->asset('XQ-2026-009', $cat->id);                   // format paling lama

        $plan = AssetTagMigrator::plan('AKP-IT');
        $this->assertCount(5, $plan);
        $this->assertTrue(Asset::where('asset_tag', 'AKP-IT-XQ-001')->exists(), 'dry run tidak mengubah apa pun');

        $this->assertSame(5, AssetTagMigrator::renumber('AKP-IT'));
        $this->assertSame(['AKP-IT-XQ-0001', 'AKP-IT-XQ-0002', 'AKP-IT-XQ-0003', 'AKP-IT-XQ-0004', 'AKP-IT-XQ-0005'], Asset::whereIn('id', [$a->id, $b->id, $c->id, $d->id, $e->id])->orderBy('id')->pluck('asset_tag')->all());

        // riwayat: setiap kode sebelumnya tetap menemukan asetnya
        foreach ([['AKP-IT-XQ-001', $a], ['AKP-IT-OLD-001', $c], ['AKP-IT-OLD-002', $d], ['XQ-2026-009', $e]] as [$old, $asset]) {
            $this->assertSame($asset->id, Asset::findByCode($old)?->id, "{$old} harus menemukan aset yang benar");
            $this->assertSame($asset->id, LabelService::resolve($old)[1]->id);
        }
        $this->assertSame($e->id, Asset::findByCode('AKP-IT-XQ-0005')->id);
        $this->assertSame(0, AssetTagMigrator::renumber('AKP-IT'), 'dijalankan ulang: tidak ada perubahan');
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_renumber_command_dry_run_changes_nothing_and_real_run_applies(): void
    {
        $cat = Category::create(['name' => 'Kategori XR', 'type' => 'asset', 'code_prefix' => 'XR']);
        $a = $this->asset('AKP-IT-XR-007', $cat->id);
        $this->artisan('assets:renumber-tags', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('AKP-IT-XR-007', $a->fresh()->asset_tag);
        $this->artisan('assets:renumber-tags')->assertSuccessful();
        $this->assertSame('AKP-IT-XR-0007', $a->fresh()->asset_tag);                      // nomor sama, hanya ditambah nol
        $this->artisan('assets:renumber-tags')->expectsOutputToContain('Tidak ada yang diubah')->assertSuccessful();
    }

    public function test_editing_a_tag_keeps_the_old_one_scannable(): void
    {
        $cat = Category::where('code_prefix', 'NB')->firstOrFail();
        $a = $this->asset('AKP-IT-NB-7777', $cat->id);
        $a->update(['asset_tag' => 'AKP-IT-NB-7778']);
        $this->assertSame($a->id, Asset::findByCode('AKP-IT-NB-7777')?->id);
        $this->assertTrue(AssetTagAlias::where('asset_id', $a->id)->where('tag', 'AKP-IT-NB-7777')->exists());
        $this->assertNull(Asset::findByCode('AKP-IT-NB-7779'));
        $this->assertNull(Asset::findByCode('   '));
    }

    public function test_new_laptop_codes_are_nb_with_four_digits_for_every_category(): void
    {
        foreach (Category::where('type', 'asset')->get() as $c) {
            $this->assertMatchesRegularExpression('/^AKP-IT-' . preg_quote(strtoupper($c->code_prefix ?: 'AST'), '/') . '-\d{4,}$/', Asset::generateTag($c));
        }
        $nb = Category::where('code_prefix', 'NB')->firstOrFail();
        $this->assertTrue(Asset::isLaptopCategory($nb->id));
        $this->assertTrue(Asset::isComputerCategory($nb->id));
        $this->assertSame(4, Asset::tagDigits());
        $this->assertSame([], Category::where('code_prefix', 'LPT')->pluck('id')->all(), 'data demo memakai NB, bukan LPT');
    }

    // ---------------------------------------------------------------- kartu pemegang
    public function test_scan_shows_the_current_holder_with_nik_department_and_position(): void
    {
        $this->as($this->staff());
        $asset = Asset::whereNotNull('employee_id')->whereNull('deleted_at')->orderBy('id')->firstOrFail();
        $emp = $asset->employee;
        $t = Livewire::test(ScanPage::class)->call('onScan', $asset->asset_tag)->assertNoRedirect();
        $card = $t->get('card');
        $this->assertSame($asset->asset_tag, $card['tag']);
        $this->assertSame([$emp->name, $emp->nik ?: '-', $emp->department?->name ?: '-', $emp->position ?: '-'], [$card['holder']['name'], $card['holder']['nik'], $card['holder']['department'], $card['holder']['position']]);
        $t->assertSee($emp->name)->assertSee('PEMEGANG SAAT INI')->assertSee('Buka detail aset');
        if ($emp->nik) $t->assertSee($emp->nik);
        // hanya data yang disetujui: tanpa telepon / email
        $t->assertDontSee((string) $emp->email);
    }

    public function test_scan_of_an_unassigned_asset_says_so_and_unknown_codes_are_rejected(): void
    {
        $this->as($this->staff());
        $free = $this->freeAsset();
        $t = Livewire::test(ScanPage::class)->call('onScan', $free->asset_tag);
        $this->assertNull($t->get('card')['holder']);
        $t->assertSee('Belum ada pemegang');

        $t = Livewire::test(ScanPage::class)->call('onScan', 'TIDAK-ADA-0000');
        $this->assertNull($t->get('card'));
        $t->assertNotified();
    }

    public function test_scan_follows_a_handover_and_marks_resigned_holders(): void
    {
        $this->as($this->staff());
        $asset = $this->freeAsset();
        $emp = Employee::where('status', 'active')->whereNotNull('department_id')->firstOrFail();
        app(\App\Services\AssetService::class)->checkout($asset, $emp, ['condition' => 'good', 'notes' => 'QA50']);
        $card = Livewire::test(ScanPage::class)->call('onScan', $asset->asset_tag)->get('card');
        $this->assertSame($emp->name, $card['holder']['name']);
        $this->assertNotNull($card['holder']['since']);
        $this->assertNotNull($card['holder']['bast']);

        $emp->forceFill(['status' => 'resigned'])->saveQuietly();
        $card = Livewire::test(ScanPage::class)->call('onScan', $asset->asset_tag)->get('card');
        $this->assertTrue($card['holder']['resigned']);
        Livewire::test(ScanPage::class)->call('onScan', $asset->asset_tag)->assertSee('(resigned)');
    }

    public function test_stock_items_still_show_stock_notification(): void
    {
        $this->as($this->staff());
        $acc = \App\Models\Accessory::firstOrFail();
        $t = Livewire::test(ScanPage::class)->call('onScan', $acc->code)->assertNotified();
        $this->assertNull($t->get('card'));
    }

    // ---------------------------------------------------------------- alamat pada QR
    public function test_urls_from_qr_are_understood_and_short_route_redirects_to_scan_page(): void
    {
        $asset = Asset::firstOrFail();
        $tag = $asset->asset_tag;
        foreach (["http://192.168.1.5:8080/s/{$tag}", "https://aset.contoh.test/s/" . rawurlencode($tag), "http://x.test/admin/scan?kode={$tag}"] as $url) {
            $this->assertSame($tag, Asset::extractCode($url));
            $this->assertSame($asset->id, LabelService::resolve($url)[1]->id);
        }
        $this->assertSame('AKP-IT-NB-0001', Asset::extractCode('  AKP-IT-NB-0001 '));

        // tamu: dialihkan ke halaman scan (yang menuntut login), tanpa membuka data apa pun
        $res = $this->get(route('scan.short', ['code' => $tag]));
        $res->assertRedirect(ScanPage::getUrl(['kode' => $tag]));
        $this->assertStringNotContainsString($asset->employee?->name ?? '@@', (string) $res->getContent());
        $this->get(ScanPage::getUrl(['kode' => $tag]))->assertRedirect();                    // belum login -> ke halaman login

        $this->as($this->staff());
        $this->get(ScanPage::getUrl(['kode' => $tag]))->assertOk()->assertSee($tag);          // sudah login: kartu langsung tampil
        $this->as($this->plain())->get(ScanPage::getUrl(['kode' => $tag]))->assertForbidden(); // tanpa izin lihat inventaris: tidak boleh
    }

    public function test_qr_payload_is_the_code_by_default_and_a_short_url_when_enabled(): void
    {
        $this->assertSame('AKP-IT-NB-0001', LabelService::qrPayload('AKP-IT-NB-0001'));
        config(['labels.qr_mode' => 'url', 'app.url' => 'http://192.168.233.128:8080/']);
        $this->assertSame('http://192.168.233.128:8080/s/AKP-IT-NB-0001', LabelService::qrPayload('AKP-IT-NB-0001'));
        $this->as($this->staff());
        $a = Asset::firstOrFail();
        $pdf = app(LabelService::class)->pdf('asset', collect([$a]), 24, 70);
        $this->assertStringStartsWith('%PDF', $pdf->output());
    }
}
