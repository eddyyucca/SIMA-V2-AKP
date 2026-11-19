<?php

namespace Tests\Qa;

use App\Filament\Resources\AssetAssignments\Pages\ManageAssetAssignments;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetBastFile;
use App\Services\AssetService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\QaTestCase;

class Qa21_BastArchiveAndPrintTest extends QaTestCase
{
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $p) Storage::disk('local')->delete($p);
        parent::tearDown();
    }

    private function pdf(string $name = 'ttd.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    private function page(Asset $a)
    {
        return Livewire::test(ViewAsset::class, ['record' => $a->getKey()]);
    }

    /** Aset bebas, diserahkan ke Andi, lalu dimutasi ke karyawan lain: dua BAST. */
    private function movedAsset(): array
    {
        $this->as($this->staff());
        $asset = $this->freeAsset();
        $svc = app(AssetService::class);
        $first = $svc->checkout($asset, $this->emp('Andi Saputra'), ['condition' => 'good']);
        $second = $svc->transfer($asset->fresh(), $this->emp('Siti Rahma'), ['condition' => 'good']);
        return [$asset->fresh(), $first->fresh(), $second->fresh()];
    }

    private function files(Asset $a)
    {
        return $a->bastFiles()->orderBy('id')->get();
    }

    // ---------- arsip banyak file ----------

    public function test_every_upload_is_kept_and_nothing_is_overwritten(): void
    {
        [$asset, $a1, $a2] = $this->movedAsset();
        $this->page($asset)->callAction('bast_upload', ['assignment_id' => $a1->id, 'files' => [$this->pdf('satu.pdf')], 'note' => 'Serah terima awal'])->assertHasNoActionErrors();
        $this->page($asset)->callAction('bast_upload', ['assignment_id' => $a2->id, 'files' => [$this->pdf('dua.pdf')], 'note' => 'Mutasi ke Siti'])->assertHasNoActionErrors();
        $this->page($asset)->callAction('bast_upload', ['assignment_id' => $a2->id, 'files' => [$this->pdf('tiga.pdf')]])->assertHasNoActionErrors();   // BAST yang sama, upload lagi
        $rows = $this->files($asset);
        foreach ($rows as $r) $this->cleanup[] = $r->path;

        $this->assertCount(3, $rows);
        $this->assertCount(3, $rows->pluck('path')->unique(), 'path berbeda, tidak saling menimpa');
        foreach ($rows as $r) {
            $this->assertTrue(Storage::disk('local')->exists($r->path));
            $this->assertStringStartsWith('bast-signed/', $r->path);
            $this->assertFalse(Storage::disk('public')->exists($r->path), 'file privat');
            $this->assertSame($this->staff()->id, $r->uploaded_by);
        }
        $this->assertSame(['Serah terima awal', 'Mutasi ke Siti', null], $rows->pluck('note')->all());
        $this->assertSame([$a1->id, $a2->id, $a2->id], $rows->pluck('asset_assignment_id')->all());
        $this->assertSame(2, $a2->bastFiles()->count());
        $this->assertNotNull($a1->fresh()->bast_pdf_path, 'BAST sistem tetap ada');
    }

    public function test_several_files_in_one_upload_and_upload_without_a_specific_bast(): void
    {
        [$asset] = $this->movedAsset();
        $this->page($asset)->callAction('bast_upload', ['assignment_id' => null, 'files' => [$this->pdf('a.pdf'), $this->pdf('b.pdf')]])->assertHasNoActionErrors();
        $rows = $this->files($asset);
        foreach ($rows as $r) $this->cleanup[] = $r->path;
        $this->assertCount(2, $rows);
        $this->assertSame([null, null], $rows->pluck('asset_assignment_id')->all());
    }

    public function test_file_bast_modal_lists_system_bast_and_all_uploads(): void
    {
        [$asset, $a1, $a2] = $this->movedAsset();
        $this->page($asset)->callAction('bast_upload', ['assignment_id' => $a1->id, 'files' => [$this->pdf()], 'note' => 'Catatan-unik-77'])->assertHasNoActionErrors();
        $f = $this->files($asset)->first();
        $this->cleanup[] = $f->path;

        $this->page($asset)->assertActionVisible('bast_file');
        $html = view('filament.asset-bast-files', ['asset' => $asset->fresh()])->render();      // isi jendela "File BAST"
        foreach ([$a1->bast_number, $a2->bast_number, 'Catatan-unik-77', route('bast.file', $f), route('bast.file.download', $f), route('bast.view', $a2), route('bast.download', $a1)] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringContainsString('(2)', $html);                                         // dua BAST sistem
        $this->assertStringContainsString('diunggah (1)', $html);
    }

    public function test_uploaded_file_views_inline_and_downloads_as_attachment(): void
    {
        [$asset, $a1] = $this->movedAsset();
        $this->page($asset)->callAction('bast_upload', ['assignment_id' => $a1->id, 'files' => [$this->pdf()]]);
        $f = $this->files($asset)->first();
        $this->cleanup[] = $f->path;

        $view = $this->get(route('bast.file', $f));
        $view->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('inline', $view->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $view->streamedContent());
        $dl = $this->get(route('bast.file.download', $f));
        $dl->assertOk();
        $this->assertStringContainsString('attachment', $dl->headers->get('content-disposition'));
        $this->assertStringContainsString('.pdf', $dl->headers->get('content-disposition'));
        $this->assertSame($view->streamedContent(), $dl->streamedContent());
    }

    public function test_access_rules_for_uploaded_files(): void
    {
        [$asset, $a1, $a2] = $this->movedAsset();                                 // a1: Andi -> aset, a2: aset -> Siti (pemegang sekarang)
        $this->page($asset)->callAction('bast_upload', ['assignment_id' => $a1->id, 'files' => [$this->pdf()]]);
        $f = $this->files($asset)->first();
        $this->cleanup[] = $f->path;

        $this->as($this->mgmt());
        $this->get(route('bast.file', $f))->assertOk();
        Livewire::test(ViewAsset::class, ['record' => $asset->getKey()])->assertActionHidden('bast_upload')->assertActionHidden('bast_print')->assertActionVisible('bast_file');
        $this->as($this->plain());                                                  // user biasa = Andi: pihak pada BAST a1
        $this->get(route('bast.file', $f))->assertOk();
        $this->get(route('bast.file.download', $f))->assertOk();
        // file milik aset lain yang tidak berhubungan dengan Andi
        $other = Asset::where('id', '!=', $asset->id)->whereDoesntHave('assignments', fn ($q) => $q->where('to_employee_id', $this->emp('Andi Saputra')->id)->orWhere('from_employee_id', $this->emp('Andi Saputra')->id))->where('employee_id', '!=', $this->emp('Andi Saputra')->id)->firstOrFail();
        $stored = $this->pdf()->storeAs('bast-signed', 'asing.pdf', 'local');
        $this->cleanup[] = $stored;
        $foreign = AssetBastFile::create(['asset_id' => $other->id, 'path' => $stored]);
        $this->get(route('bast.file', $foreign))->assertForbidden();
        auth()->logout();
        $this->get(route('bast.file', $f))->assertRedirect();
    }

    public function test_missing_file_is_404_and_audit_flags_it(): void
    {
        [$asset] = $this->movedAsset();
        $this->page($asset)->callAction('bast_upload', ['files' => [$this->pdf()]]);
        $f = $this->files($asset)->first();
        $this->artisan('inventory:audit')->assertSuccessful();
        Storage::disk('local')->delete($f->path);
        $this->get(route('bast.file', $f))->assertNotFound();
        $this->artisan('inventory:audit')->assertFailed();                          // arsip hilang terdeteksi
    }

    public function test_upload_validation_and_service_guards(): void
    {
        [$asset, $a1] = $this->movedAsset();
        $this->page($asset)->callAction('bast_upload', ['files' => [UploadedFile::fake()->image('foto.png')]])->assertHasActionErrors(['files']);
        $this->page($asset)->callAction('bast_upload', ['assignment_id' => $a1->id])->assertHasActionErrors(['files' => 'required']);
        $this->page($asset)->callAction('bast_upload', ['files' => [UploadedFile::fake()->createWithContent('palsu.pdf', 'bukan pdf')]])->assertNotified();
        $this->assertSame(0, $asset->bastFiles()->count());

        $other = AssetAssignment::where('asset_id', '!=', $asset->id)->firstOrFail();
        $stored = $this->pdf()->storeAs('bast-signed', 'uji.pdf', 'local');
        $this->cleanup[] = $stored;
        $svc = app(AssetService::class);
        try { $svc->attachSignedBast($asset, $other, $stored); $this->fail('BAST aset lain'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('bukan milik', $e->getMessage()); }
        foreach (['../.env', 'bast/x.pdf', 'bast-signed/../x.pdf', 'bast-signed/tidak-ada.pdf'] as $bad) {
            try { $svc->attachSignedBast($asset, null, $bad); $this->fail("path {$bad} harus ditolak"); } catch (\InvalidArgumentException $e) { $this->assertNotEmpty($e->getMessage()); }
        }
        $this->assertSame(0, AssetBastFile::where('asset_id', $asset->id)->count());
    }

    public function test_history_and_assignment_list_link_to_uploaded_files(): void
    {
        [$asset, $a1, $a2] = $this->movedAsset();
        $this->page($asset)->callAction('bast_upload', ['assignment_id' => $a2->id, 'files' => [$this->pdf('a.pdf'), $this->pdf('b.pdf')]]);
        $rows = $this->files($asset);
        foreach ($rows as $r) $this->cleanup[] = $r->path;
        $this->page($asset)->assertSee(route('bast.file', $rows[0]), false)->assertSee(route('bast.file', $rows[1]), false)->assertSee('Scan TTD 2');
        Livewire::test(ManageAssetAssignments::class)->assertTableActionVisible('bast_signed', $a2)->assertTableActionHidden('bast_signed', $a1);
    }

    // ---------- cetak BAST per aset ----------

    public function test_print_button_exists_on_every_asset_even_without_history(): void
    {
        $this->as($this->staff());
        $fresh = Asset::where('status', 'available')->whereDoesntHave('assignments')->first() ?? $this->freeAsset();
        $this->page($fresh)->assertActionVisible('bast_print');
        $this->page($this->held())->assertActionVisible('bast_print');
        $this->as($this->plain());
        $this->assertTrue(true);
    }

    public function test_print_downloads_a_pdf_form_without_changing_the_asset(): void
    {
        $this->as($this->staff());
        $asset = $this->freeAsset();
        $before = [$asset->status, $asset->employee_id, AssetAssignment::count(), AssetBastFile::count()];
        foreach (['checkout', 'transfer', 'checkin'] as $type) {
            $this->page($asset)->callAction('bast_print', ['type' => $type])->assertHasNoActionErrors()->assertFileDownloaded('Formulir-BAST-' . str_replace('/', '-', $asset->asset_tag) . '.pdf');
        }
        $this->page($asset)->callAction('bast_print', ['type' => 'checkout', 'employee_id' => $this->emp('Andi Saputra')->id])->assertHasNoActionErrors()->assertFileDownloaded();
        $this->page($asset)->callAction('bast_print', ['type' => null])->assertHasActionErrors(['type' => 'required']);
        $after = $asset->fresh();
        $this->assertSame($before, [$after->status, $after->employee_id, AssetAssignment::count(), AssetBastFile::count()], 'mencetak tidak mengubah apa pun');
    }

    public function test_print_form_content_blank_vs_filled(): void
    {
        $this->as($this->staff());
        $svc = app(AssetService::class);
        $asset = $this->freeAsset();
        $blank = $svc->bastFormPdf($asset, 'checkout', null);
        $this->assertStringStartsWith('%PDF', $blank);

        $render = function (string $type, $emp) use ($asset, $svc) {
            $holder = $asset->employee;
            $a = new AssetAssignment(['asset_id' => $asset->id, 'type' => $type, 'condition' => $asset->condition, 'assigned_at' => now(), 'bast_number' => '....']);
            $a->setRelation('asset', $asset->loadMissing('category'));
            $a->setRelation('from', $type === 'checkout' ? null : $holder);
            $a->setRelation('to', $type === 'checkin' ? null : $emp);
            $a->setRelation('location', null);
            $a->setRelation('creator', auth()->user());
            return view('pdf.bast', ['a' => $a, 'sigReceiver' => null, 'sigIt' => null, 'form' => true])->render();
        };
        $html = $render('checkout', null);
        $this->assertStringContainsString($asset->asset_tag, $html);
        $this->assertStringContainsString(str_repeat('.', 28), $html, 'kolom penerima dikosongkan untuk tulisan tangan');
        $this->assertStringContainsString('SERAH TERIMA', $html);

        $andi = $this->emp('Andi Saputra');
        $andi->update(['nik' => 'AKP-CETAK-1', 'position' => 'Jabatan Cetak']);
        $filled = $render('checkout', $andi->fresh());
        $this->assertStringContainsString('Andi Saputra', $filled);
        $this->assertStringContainsString('AKP-CETAK-1', $filled);
        $this->assertStringContainsString('Jabatan Cetak', $filled);
        $this->assertStringContainsString('BERITA ACARA PENGEMBALIAN', $render('checkin', $andi));
        $this->assertStringContainsString('BERITA ACARA MUTASI', $render('transfer', $andi));
        $this->assertStringStartsWith('%PDF', $svc->bastFormPdf($asset, 'checkout', $andi));
        try { $svc->bastFormPdf($asset, 'x'); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Jenis', $e->getMessage()); }
    }

    public function test_real_bast_pdf_is_unchanged_by_the_form_mode(): void
    {
        [$asset, $a1] = $this->movedAsset();
        $html = view('pdf.bast', ['a' => $a1->load(['asset.category', 'from.department', 'to.department', 'location', 'creator']), 'sigReceiver' => null, 'sigIt' => null])->render();
        $this->assertStringContainsString('Andi Saputra', $html);
        $this->assertStringNotContainsString(str_repeat('.', 28), $html, 'BAST asli tidak berisi titik-titik formulir');
    }

    public function test_migration_moved_nothing_away_and_old_columns_are_gone(): void
    {
        $this->assertFalse(\Schema::hasColumn('asset_assignments', 'bast_signed_path'));
        $this->assertTrue(\Schema::hasTable('asset_bast_files'));
    }
}
