<?php

namespace Tests\Qa;

use App\Models\AssetAssignment;
use App\Models\Component;
use App\Models\StockMovement;
use App\Support\BastDocument;
use Illuminate\Support\Facades\Storage;
use Tests\QaTestCase;

/** PDF BAST asli tidak boleh tertimpa tes; BAST yang rusak bisa dibuat ulang tanpa kehilangan berkas lama; kelengkapan mengikuti tanggal BAST. */
class Qa44_BastFilesSafetyTest extends QaTestCase
{
    public function test_tests_never_write_to_the_real_storage(): void
    {
        foreach (['local' => storage_path('app/private'), 'public' => storage_path('app/public')] as $disk => $real) {
            $root = rtrim(Storage::disk($disk)->path(''), '/');
            $this->assertStringContainsString('framework/testing', $root, "disk $disk harus terisolasi");
            $this->assertStringStartsNotWith(rtrim($real, '/') . '/', $root . '/');
        }
        // menulis lewat disk tidak menyentuh folder asli
        $name = 'bast/BAST-2026-11-ISOLASI-' . uniqid() . '.pdf';
        Storage::disk('local')->put($name, 'x');
        $this->assertFileDoesNotExist(storage_path('app/private/' . $name));
        $this->assertFileExists(Storage::disk('local')->path($name));
    }

    public function test_regenerate_command_backs_up_the_old_file_and_rebuilds_from_the_assignment_data(): void
    {
        $this->as($this->staff());
        $a = AssetAssignment::with(['asset', 'to'])->whereNotNull('bast_pdf_path')->where('type', 'checkout')->orderBy('id')->firstOrFail();
        $disk = Storage::disk('local');
        $disk->put($a->bast_pdf_path, "%PDF-1.4\nRUSAK / TERTIMPA");                         // simulasi berkas tertimpa
        $this->artisan('bast:regenerate', ['numbers' => [$a->bast_number], '--dry-run' => true])->expectsOutputToContain($a->bast_number)->assertSuccessful();
        $this->assertStringContainsString('RUSAK', (string) $disk->get($a->bast_pdf_path));       // dry-run tidak mengubah

        $this->artisan('bast:regenerate', ['numbers' => [$a->bast_number]])->expectsOutputToContain('dibuat ulang')->assertSuccessful();
        $new = (string) $disk->get($a->bast_pdf_path);
        $this->assertStringStartsWith('%PDF', $new);
        $this->assertStringNotContainsString('RUSAK', $new);
        $backups = collect($disk->files('bast-backup'))->filter(fn ($f) => str_contains($f, pathinfo($a->bast_pdf_path, PATHINFO_FILENAME)));
        $this->assertCount(1, $backups);
        $this->assertStringContainsString('RUSAK', (string) $disk->get($backups->first()));      // berkas lama tidak hilang
        $this->artisan('bast:regenerate', ['numbers' => ['BAST/9999/99/9999']])->assertFailed();
    }

    public function test_bast_equipment_list_reflects_the_date_of_the_bast_not_today(): void
    {
        $this->as($this->staff());
        $a = AssetAssignment::with('asset')->where('type', 'checkout')->orderBy('id')->firstOrFail();
        $asset = $a->asset;
        $c = Component::create(['name' => 'RAM Setelah BAST QA44', 'qty_total' => 3, 'min_qty' => 0]);
        // dipasang SETELAH BAST dibuat -> tidak boleh muncul di BAST itu, tetapi muncul pada keadaan sekarang
        StockMovement::create(['stockable_type' => Component::class, 'stockable_id' => $c->id, 'type' => 'install', 'qty' => 1, 'asset_id' => $asset->id,
            'reason' => 'Upgrade', 'moved_at' => $a->assigned_at->copy()->addDays(3), 'created_by' => 1]);
        $this->assertTrue($asset->installedComponents()->contains(fn ($r) => $r['component']->id === $c->id));
        $this->assertFalse($asset->installedComponents($a->assigned_at)->contains(fn ($r) => $r['component']->id === $c->id));
        $this->assertStringNotContainsString('RAM Setelah BAST QA44', json_encode(BastDocument::build($a->fresh(['asset', 'from', 'to', 'creator', 'location']))));
        // dipasang SEBELUM BAST -> muncul
        StockMovement::create(['stockable_type' => Component::class, 'stockable_id' => $c->id, 'type' => 'install', 'qty' => 1, 'asset_id' => $asset->id,
            'reason' => 'Upgrade', 'moved_at' => $a->assigned_at->copy()->subDay(), 'created_by' => 1]);
        $this->assertStringContainsString('RAM Setelah BAST QA44', json_encode(BastDocument::build($a->fresh(['asset', 'from', 'to', 'creator', 'location']))));
    }

    public function test_new_bast_pdf_uses_narrow_no_columns(): void
    {
        $this->as($this->staff());
        $html = view('pdf.bast', ['a' => AssetAssignment::with(['asset.category', 'from.department', 'to.department', 'location', 'creator'])->orderBy('id')->firstOrFail(), 'sigReceiver' => null, 'sigIt' => null])->render();
        $this->assertStringNotContainsString('colspan="8" style="text-align:left;font-size:7.4pt;padding:3.4px 6px;background:#d9d9d9;border-bottom:1.6px solid #000">DETAIL ASSET', $html);   // judul dipisah agar lebar kolom berlaku
        $this->assertMatchesRegularExpression('#DETAIL ASSET</th></tr></table>\s*<table class="grid">\s*<tr>\s*<th style="width:5%">No</th>#', $html);
        $this->assertMatchesRegularExpression('#KELENGKAPAN ASSET</th></tr></table>\s*<table class="grid( eq)?">\s*<tr><th style="width:6%">No</th>#', $html);
    }
}
