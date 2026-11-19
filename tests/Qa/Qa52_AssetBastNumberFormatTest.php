<?php

namespace Tests\Qa;

use App\Models\AssetAssignment;
use App\Models\Employee;
use App\Services\AssetService;
use App\Support\BastDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\QaTestCase;

/** Nomor BAST aset berformat IT/BAST/2026/11/0053: pembuatan, urutan, dan konversi nomor lama beserta berkas PDF-nya. */
class Qa52_AssetBastNumberFormatTest extends QaTestCase
{
    private function legacy(AssetAssignment $a): AssetAssignment
    {
        $old = substr($a->bast_number, 3);                                              // IT/BAST/2026/11/0053 -> BAST/2026/11/0053
        $oldPath = 'bast/' . str_replace('/', '-', $old) . '.pdf';
        Storage::disk('local')->put($oldPath, '%PDF-1.4 LAMA ' . $old);
        DB::table('asset_assignments')->where('id', $a->id)->update(['bast_number' => $old, 'bast_pdf_path' => $oldPath]);
        return $a->fresh();
    }

    public function test_every_number_uses_the_it_prefix_and_the_pdf_is_named_after_it(): void
    {
        $this->assertGreaterThan(0, AssetAssignment::count());
        foreach (AssetAssignment::all() as $a) {
            $this->assertMatchesRegularExpression('#^IT/BAST/\d{4}/\d{2}/\d{4}$#', $a->bast_number);
            $this->assertSame('bast/' . str_replace('/', '-', $a->bast_number) . '.pdf', $a->bast_pdf_path);
            $this->assertStringStartsWith('IT-BAST-', basename($a->bast_pdf_path));
        }
        // dokumen: kotak "No. Dokumen" memakai nomor yang sama
        $a = AssetAssignment::with(['asset', 'from', 'to', 'creator', 'location'])->orderBy('id')->firstOrFail();
        $this->assertSame($a->bast_number, BastDocument::build($a)['number']);
    }

    public function test_new_numbers_continue_the_month_sequence_even_after_old_format_numbers(): void
    {
        $this->as($this->staff());
        $ym = now()->format('Y/m');
        $emp = Employee::where('status', 'active')->firstOrFail();
        $svc = app(AssetService::class);
        $first = $svc->checkout($this->freeAsset(), $emp, ['condition' => 'good']);
        $this->assertMatchesRegularExpression('#^IT/BAST/' . $ym . '/\d{4}$#', $first->bast_number);

        DB::table('asset_assignments')->where('id', $first->id)->update(['bast_number' => "BAST/{$ym}/0500"]);       // nomor lama yang belum dikonversi
        $second = $svc->checkout($this->freeAsset(), $emp, ['condition' => 'good']);
        $this->assertSame("IT/BAST/{$ym}/0501", $second->bast_number, 'nomor lama tetap dihitung, tidak ada nomor ganda');
        $this->assertSame("IT/BAST/{$ym}/0502", $svc->checkout($this->freeAsset(), $emp, ['condition' => 'good'])->bast_number);
        $this->assertSame(AssetAssignment::count(), AssetAssignment::distinct('bast_number')->count('bast_number'));
    }

    public function test_renumber_command_converts_number_file_and_pdf_keeping_a_backup(): void
    {
        $this->as($this->staff());
        $disk = Storage::disk('local');
        $a = $this->legacy(AssetAssignment::where('type', 'checkout')->orderBy('id')->firstOrFail());
        $legacyNo = $a->bast_number; $legacyPath = $a->bast_pdf_path;
        $newNo = 'IT/' . $legacyNo;                                                     // IT/BAST/2026/11/0053, bukan IT/BAST/BAST/…
        $this->assertMatchesRegularExpression('#^IT/BAST/\d{4}/\d{2}/\d{4}$#', $newNo);

        $this->artisan('bast:renumber-assets', ['--dry-run' => true])->expectsOutputToContain('dry-run')->assertSuccessful();
        $this->assertSame($legacyNo, $a->fresh()->bast_number, 'dry-run tidak mengubah');
        $this->assertTrue($disk->exists($legacyPath));

        $this->artisan('bast:renumber-assets')->expectsOutputToContain('diubah ke format IT/BAST')->assertSuccessful();
        $b = $a->fresh();
        $this->assertSame($newNo, $b->bast_number);
        $this->assertStringNotContainsString('BAST/BAST', $b->bast_number);
        $this->assertSame('bast/' . str_replace('/', '-', $newNo) . '.pdf', $b->bast_pdf_path);
        $this->assertTrue($disk->exists($b->bast_pdf_path));
        $this->assertStringStartsWith('%PDF', (string) $disk->get($b->bast_pdf_path));
        $this->assertStringNotContainsString('LAMA', (string) $disk->get($b->bast_pdf_path), 'PDF dibuat ulang, bukan disalin');
        $this->assertFalse($disk->exists($legacyPath), 'berkas lama tidak tersisa sebagai yatim');
        $backup = collect($disk->files('bast-backup'))->first(fn ($f) => str_contains($f, pathinfo($legacyPath, PATHINFO_FILENAME)));
        $this->assertNotNull($backup);
        $this->assertStringContainsString('LAMA', (string) $disk->get($backup), 'berkas lama tersimpan di cadangan');
        $this->assertSame($newNo, BastDocument::build($b->load(['asset', 'from', 'to', 'creator', 'location']))['number']);

        $this->artisan('bast:renumber-assets')->expectsOutputToContain('Tidak ada yang diubah')->assertSuccessful();     // idempoten
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_renumber_command_refuses_when_a_new_number_is_already_taken(): void
    {
        $this->as($this->staff());
        $rows = AssetAssignment::orderBy('id')->take(2)->get();
        DB::table('asset_assignments')->where('id', $rows[1]->id)->update(['bast_number' => substr($rows[0]->bast_number, 3)]);   // nomor lama milik baris 2 = nomor baru baris 1? (bentrok setelah konversi)
        $this->artisan('bast:renumber-assets')->expectsOutputToContain('sudah dipakai')->assertFailed();
        $this->assertSame(substr($rows[0]->bast_number, 3), $rows[1]->fresh()->bast_number, 'tidak ada yang diubah saat ada bentrok');
    }
}
