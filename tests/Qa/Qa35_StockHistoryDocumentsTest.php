<?php

namespace Tests\Qa;

use App\Filament\Pages\StockHistory\ConsumableStockHistory;
use App\Models\Accessory;
use App\Models\Consumable;
use App\Models\Employee;
use App\Models\StockMovement;
use App\Services\AccessoryHandoverService;
use App\Services\StockDocumentService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\QaTestCase;

/** Riwayat Stok: kolom Alasan & Oleh dihapus; tiap baris bisa diunggah dokumennya dan dokumen yang ada bisa dilihat. */
class Qa35_StockHistoryDocumentsTest extends QaTestCase
{
    private function putFile(string $name, string $content): string
    {
        Storage::disk('local')->put($p = StockDocumentService::DIR . '/' . $name, $content);
        return $p;
    }

    public function test_reason_and_by_columns_are_gone_and_document_column_exists(): void
    {
        Storage::fake('local');
        $this->as($this->staff());
        Livewire::test(ConsumableStockHistory::class)->assertTableColumnDoesNotExist('reason')->assertTableColumnDoesNotExist('creator.name')->assertTableColumnExists('documents_count')
            ->assertSee('Dokumen')->assertDontSee('Alasan');
    }

    public function test_upload_and_view_documents_on_a_purchase_movement(): void
    {
        Storage::fake('local');
        $this->as($this->staff());
        $c = Consumable::create(['name' => 'Tinta Doc35', 'unit' => 'pcs', 'qty' => 1, 'min_qty' => 0]);
        $m = StockMovement::where('stockable_type', Consumable::class)->where('stockable_id', $c->id)->firstOrFail();
        $svc = app(StockDocumentService::class);

        $this->assertTrue($m->documents()->isEmpty());
        $svc->attach($m, $this->putFile('faktur.pdf', "%PDF-1.4\n"), 'Faktur Tinta.pdf', 'lunas');
        $svc->attach($m, $this->putFile('sj.png', "\x89PNG\r\n\x1a\n"), null);
        $docs = $m->fresh()->documents();
        $this->assertCount(2, $docs);
        $this->assertSame('Faktur Tinta.pdf', $docs->firstWhere('note', 'lunas')['name']);

        foreach ([['x.txt', 'hanya teks', 'bukan PDF'], ['y.pdf', 'MZ bukan pdf', 'bukan PDF']] as [$n, $body, $msg]) {
            try { $svc->attach($m, $this->putFile($n, $body), $n); $this->fail('harus ditolak'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString($msg, $e->getMessage()); }
        }
        try { $svc->attach($m, 'stock-movement-docs/tidak-ada.pdf'); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('tidak ditemukan', $e->getMessage()); }
        try { $svc->attach($m, 'secret/../.env'); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('tidak ditemukan', $e->getMessage()); }
        $this->assertCount(2, $m->fresh()->documents());                                         // gagal tidak menambah

        // file bisa dibuka (lihat & unduh) oleh staf, ditolak untuk orang tanpa izin lihat inventaris
        $f = $m->files()->first();
        $this->get(route('stock-movement-file.view', $f))->assertOk();
        $this->get(route('stock-movement-file.download', $f))->assertOk();
        $this->as($this->plain())->get(route('stock-movement-file.view', $f))->assertForbidden();
    }

    public function test_table_actions_upload_view_and_permissions(): void
    {
        Storage::fake('local');
        $this->as($this->staff());
        $c = Consumable::create(['name' => 'Kertas Doc35', 'unit' => 'rim', 'qty' => 2, 'min_qty' => 0]);
        $m = StockMovement::where('stockable_type', Consumable::class)->where('stockable_id', $c->id)->firstOrFail();
        $l = Livewire::test(ConsumableStockHistory::class)->searchTable(null);
        $l->assertTableActionVisible('upload', $m)->assertTableActionHidden('documents', $m);

        app(StockDocumentService::class)->attach($m, $this->putFile('a.pdf', "%PDF-1.4\n"), 'a.pdf');
        Livewire::test(ConsumableStockHistory::class)->assertTableActionVisible('documents', $m->fresh());

        // manajemen hanya melihat, tidak bisa mengunggah
        $this->as($this->mgmt());
        Livewire::test(ConsumableStockHistory::class)->assertTableActionHidden('upload', $m);
    }

    public function test_movement_from_a_bast_shares_the_bast_signed_archive(): void
    {
        Storage::fake('local');
        $this->as($this->staff());
        $emp = Employee::where('status', 'active')->firstOrFail();
        $mouse = Accessory::create(['name' => 'Mouse Doc35', 'qty_total' => 5, 'min_qty' => 0]);
        $h = app(AccessoryHandoverService::class)->checkout($emp, [['item' => 'accessory:' . $mouse->id, 'qty' => 1]]);
        $m = StockMovement::findOrFail($h->items->first()->stock_movement_id);

        app(StockDocumentService::class)->attach($m, $this->putFile('ttd.pdf', "%PDF-1.4\n"), 'TTD Mouse.pdf', 'sudah ttd');
        $this->assertSame(1, $h->files()->count());                                               // masuk arsip BAST, tidak ganda
        $this->assertSame(0, $m->files()->count());
        $this->assertStringStartsWith('accessory-bast-signed/', $h->files()->first()->path);
        $docs = $m->fresh()->documents();
        $this->assertCount(1, $docs);
        $this->assertStringContainsString('BAST ' . $h->bast_number, $docs->first()['source']);
        $this->get($docs->first()['view'])->assertOk();

        // dan sebaliknya: dokumen yang diunggah di halaman BAST juga terlihat dari Riwayat Stok
        Storage::disk('local')->put('accessory-bast-signed/b.pdf', "%PDF-1.4\n");
        app(AccessoryHandoverService::class)->attachSigned($h, 'accessory-bast-signed/b.pdf', 'Scan kedua.pdf');
        $this->assertCount(2, $m->fresh()->documents());
        $html = view('filament.stock-movement-docs', ['documents' => $m->fresh()->documents()])->render();
        foreach (['TTD Mouse.pdf', 'Scan kedua.pdf', $h->bast_number, 'Lihat', 'Unduh'] as $n) $this->assertStringContainsString($n, $html);
    }

    public function test_upload_modal_stores_the_file_end_to_end(): void
    {
        Storage::fake('local');
        $this->as($this->staff());
        $c = Consumable::create(['name' => 'Modal Doc35', 'unit' => 'pcs', 'qty' => 1, 'min_qty' => 0]);
        $m = StockMovement::where('stockable_type', Consumable::class)->where('stockable_id', $c->id)->firstOrFail();
        $pdf = \Illuminate\Http\UploadedFile::fake()->createWithContent('Faktur Modal.pdf', "%PDF-1.4\nisi");
        Livewire::test(ConsumableStockHistory::class)
            ->callAction(TestAction::make('upload')->table($m), ['files' => [$pdf], 'note' => 'via modal'])->assertHasNoActionErrors()->assertNotified('Dokumen diunggah dan diarsipkan');
        $f = $m->files()->first();
        $this->assertNotNull($f);
        $this->assertSame('via modal', $f->note);
        $this->assertTrue(Storage::disk('local')->exists($f->path));
        $this->assertStringStartsWith(StockDocumentService::DIR . '/', $f->path);
        $this->assertSame('Faktur Modal.pdf', $f->downloadName());
    }
}
