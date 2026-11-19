<?php

namespace Tests\Qa;

use App\Filament\Resources\Accessories\Pages\ManageAccessories;
use App\Filament\Resources\AccessoryHandovers\Pages\CreateAccessoryHandover;
use App\Filament\Resources\AccessoryHandovers\Pages\ListAccessoryHandovers;
use App\Filament\Resources\Components\Pages\ManageComponents;
use App\Filament\Resources\Consumables\Pages\ManageConsumables;
use App\Filament\Pages\StockHistory\AccessoryStockHistory;
use App\Filament\Pages\StockHistory\ComponentStockHistory;
use App\Filament\Pages\StockHistory\ConsumableStockHistory;
use App\Models\Accessory;
use App\Models\AccessoryHandover;
use App\Models\Category;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\Employee;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\StockReceipt;
use App\Models\Vendor;
use App\Services\AccessoryHandoverService;
use App\Services\StockDocumentService;
use App\Support\AccessoryBastDocument;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\QaTestCase;

/**
 * Uji menyeluruh dengan data lengkap: Accessories, Consumable, Komponen dari pembuatan, stok masuk, stok keluar / BAST, pengembalian,
 * riwayat, sampai unggah & lihat dokumen (10 skenario file), plus semua halaman panel tidak boleh error 500.
 */
class Qa36_EndToEndSupportItemsTest extends QaTestCase
{
    private function putFile(string $dir, string $name, string $content): string
    {
        Storage::disk('local')->put($p = $dir . '/' . $name, $content);
        return $p;
    }

    public static function kinds(): array
    {
        return [
            'accessory' => ['accessory', ManageAccessories::class, Accessory::class],
            'consumable' => ['consumable', ManageConsumables::class, Consumable::class],
            'component' => ['component', ManageComponents::class, Component::class],
        ];
    }

    private function createItem(string $kind, string $page): void
    {
        $v = Vendor::firstOrFail()->id; $loc = Location::firstOrFail()->id;
        $cat = Category::where('type', $kind)->first()?->id;
        $common = ['name' => "Lengkap $kind QA36", 'category_id' => $cat, 'min_qty' => 2, 'location_id' => $loc, 'vendor_id' => $v];
        $data = match ($kind) {
            'accessory' => $common + ['brand_model' => 'Logitech M331', 'qty_total' => 6, 'unit_price' => 85000],
            'consumable' => $common + ['unit' => 'rim', 'compatible_with' => 'HP LaserJet P1102', 'qty' => 6, 'unit_price' => 45000],
            'component' => $common + ['specs' => 'DDR4 3200 8GB', 'serial_number' => 'SN-QA36-' . random_int(1000, 9999), 'qty_total' => 6, 'price' => 650000, 'warranty_until' => now()->addYear()->toDateString()],
        };
        Livewire::test($page)->callAction('create', $data)->assertHasNoActionErrors();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('kinds')]
    public function test_full_flow_for_each_kind(string $kind, string $page, string $class): void
    {
        Storage::fake('local');
        $this->as($this->staff());
        $emp = Employee::where('status', 'active')->firstOrFail();
        $v = Vendor::firstOrFail();

        // 1. buat barang dengan semua isian -> stok awal + pembelian awal tercatat
        $this->createItem($kind, $page);
        $item = $class::where('name', "Lengkap $kind QA36")->firstOrFail();
        $qty = fn () => (float) ($kind === 'consumable' ? $item->fresh()->qty : $item->fresh()->qty_available);
        $this->assertEquals(6, $qty());
        $first = StockMovement::where('stockable_type', $class)->where('stockable_id', $item->id)->where('type', 'in')->firstOrFail();
        $this->assertNotNull(StockReceipt::where('stock_movement_id', $first->id)->first());

        // 2. stok masuk dari tombol di samping "Buat" (+4) -> pembelian baru
        Livewire::test($page)->callAction('stock_in_header', ['item_id' => $item->id, 'vendor_id' => $v->id, 'qty' => 4, 'unit_price' => 50000, 'received_at' => now()->toDateString(), 'reference_no' => 'PO-QA36', 'notes' => 'uji'])->assertHasNoActionErrors();
        $this->assertEquals(10, $qty());
        $this->assertSame(2, StockMovement::where('stockable_type', $class)->where('stockable_id', $item->id)->where('type', 'in')->count());

        // 3. stok keluar: consumable lewat modal; lainnya lewat BAST -> nomor sesuai jenis
        if ($kind === 'consumable') {
            Livewire::test($page)->callAction('stock_out_header', ['item_id' => $item->id, 'qty' => 3, 'target' => 'employee', 'employee_id' => $emp->id, 'notes' => 'dipakai'])->assertHasNoActionErrors();
            $out = StockMovement::where('stockable_type', $class)->where('stockable_id', $item->id)->where('type', 'out')->firstOrFail();
            $this->assertEquals(7, $qty());
            $h = $this->svc()->checkout($emp, [['item' => "consumable:{$item->id}", 'qty' => 1]]);          // juga bisa lewat BAST
        } else {
            Livewire::test(CreateAccessoryHandover::class)->fillForm(['type' => 'checkout', 'employee_id' => $emp->id, 'lines' => [['item' => "$kind:{$item->id}", 'qty' => 2]], 'notes' => 'uji lengkap'])->call('create')->assertHasNoFormErrors();
            $h = AccessoryHandover::latest('id')->firstOrFail();
            $out = StockMovement::findOrFail($h->items->first()->stock_movement_id);
        }
        $this->assertSame(['consumable' => 'BPB', 'accessory' => 'BSA', 'component' => 'BSK'][$kind], explode('/', $h->bast_number)[2]);
        $this->assertSame('out', $out->type);

        // 4. BAST: PDF tersusun, riwayat karyawan menampilkannya
        $d = AccessoryBastDocument::build($h);
        $this->assertSame(1, count($d['rows']));
        $this->assertSame($item->name, $d['rows'][0]['name']);
        $this->assertSame(['consumable' => 'Consumable', 'accessory' => 'Accessories', 'component' => 'Komponen'][$kind], $d['rows'][0]['kind']);
        $this->get(route('accessory-bast.view', $h))->assertOk();
        $this->assertStringContainsString($h->bast_number, view('filament.employee-items', ['employee' => $emp->fresh()])->render());

        // 5. pengembalian: hanya accessories
        if ($kind === 'accessory') {
            Livewire::test(CreateAccessoryHandover::class)->fillForm(['type' => 'return', 'employee_id' => $emp->id, 'lines' => [['item' => "accessory:{$item->id}", 'qty' => 1, 'condition' => 'good']]])->call('create')->assertHasNoFormErrors();
            $this->assertSame(9, $item->fresh()->qty_available);
            $this->assertSame('BPA', explode('/', AccessoryHandover::latest('id')->first()->bast_number)[2]);
        } else {
            try { $this->svc()->receiveBack($emp, [['item' => "$kind:{$item->id}", 'qty' => 1]]); $this->fail('harus ditolak'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('hanya untuk accessories', $e->getMessage()); }
        }

        // 6. riwayat stok terfilter jenis, dokumen diunggah lewat modal & bisa dilihat
        $hist = [Accessory::class => AccessoryStockHistory::class, Consumable::class => ConsumableStockHistory::class, Component::class => ComponentStockHistory::class][$class];
        $l = Livewire::test($hist);
        $this->assertTrue($l->instance()->getTableRecords()->every(fn ($r) => $r->stockable_type === $class));
        $pdf = UploadedFile::fake()->createWithContent("Dokumen $kind.pdf", "%PDF-1.4\nisi");
        Livewire::test($hist)->callAction(TestAction::make('upload')->table($out), ['files' => [$pdf], 'note' => "ttd $kind"])->assertHasNoActionErrors();
        $docs = $out->fresh()->documents();
        $this->assertCount(1, $docs);
        $this->get($docs->first()['view'])->assertOk();
        $this->get($docs->first()['download'])->assertOk();
        Livewire::test($hist)->assertTableActionVisible('documents', $out->fresh());

        // 7. hapus tidak membuang riwayat; stok konsisten dengan jumlah pergerakan
        $net = StockMovement::where('stockable_type', $class)->where('stockable_id', $item->id)->get()->sum(function ($m) use ($kind) {
            return match ($m->type) { 'in' => $m->qty, 'out' => -$m->qty, 'return' => $m->qty, default => 0 };
        });
        $this->assertEquals($net, $qty());
    }

    private function svc(): AccessoryHandoverService { return app(AccessoryHandoverService::class); }

    /** 10 skenario file untuk dokumen Riwayat Stok (service) -> yang valid tersimpan, yang tidak valid ditolak dan tidak meninggalkan jejak. */
    public function test_ten_file_inputs_on_stock_history(): void
    {
        Storage::fake('local');
        $this->as($this->staff());
        $c = Consumable::create(['name' => 'File Uji QA36', 'unit' => 'pcs', 'qty' => 1, 'min_qty' => 0]);
        $m = StockMovement::where('stockable_type', Consumable::class)->where('stockable_id', $c->id)->firstOrFail();
        $svc = app(StockDocumentService::class);
        $D = StockDocumentService::DIR;

        $cases = [
            ['01 pdf valid', 'faktur-01.pdf', "%PDF-1.7\n%isi", 'Faktur Januari.pdf', true],
            ['02 jpg valid', 'foto-02.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF", 'Foto surat jalan.jpg', true],
            ['03 png valid', 'scan-03.png', "\x89PNG\r\n\x1a\n....", 'scan.png', true],
            ['04 nama unik/spasi/unicode', 'x-04.pdf', "%PDF-1.4\n", 'Surat Jalan – Tinta (No. 12) é.pdf', true],
            ['05 teks diberi ekstensi pdf', 'fake-05.pdf', 'ini hanya teks biasa', 'fake.pdf', false],
            ['06 exe diberi ekstensi png', 'mz-06.png', "MZ\x90\x00\x03\x00\x00\x00", 'gambar.png', false],
            ['07 file kosong', 'kosong-07.pdf', '', 'kosong.pdf', false],
            ['08 html/script diberi ekstensi pdf', 'xss-08.pdf', '<html><script>alert(1)</script></html>', 'xss.pdf', false],
            ['09 nama berisi path traversal', 'trav-09.pdf', "%PDF-1.4\n", '../../etc/passwd.pdf', true],
            ['10 php diberi ekstensi jpg', 'shell-10.jpg', '<?php system($_GET["c"]); ?>', 'shell.jpg', false],
        ];
        $saved = 0;
        foreach ($cases as [$label, $stored, $content, $original, $ok]) {
            $path = $this->putFile($D, $stored, $content);
            if ($ok) {
                $f = $svc->attach($m, $path, $original, $label);
                $saved++;
                $this->assertTrue(Storage::disk('local')->exists($path), $label);
                $this->assertStringNotContainsString('..', $f->original_name, "$label: nama harus dibersihkan");
                $this->assertStringNotContainsString('/', $f->original_name, $label);
            } else {
                try { $svc->attach($m, $path, $original, $label); $this->fail("$label harus ditolak"); }
                catch (\InvalidArgumentException $e) { $this->assertStringContainsString('bukan PDF', $e->getMessage(), $label); }
                $this->assertFalse(Storage::disk('local')->exists($path), "$label: file ditolak harus dihapus dari disk");
            }
        }
        $this->assertSame(5, $saved);
        $this->assertCount(5, $m->fresh()->documents());
        foreach ($m->files as $f) { $this->get(route('stock-movement-file.view', $f))->assertOk(); }
    }

    /** Lewat modal (validasi tipe & ukuran dari formulir): jenis & ukuran yang salah ditolak sebelum disimpan. */
    public function test_upload_modal_rejects_wrong_type_and_size_and_accepts_good_files(): void
    {
        Storage::fake('local');
        $this->as($this->staff());
        $c = Consumable::create(['name' => 'Modal QA36', 'unit' => 'pcs', 'qty' => 1, 'min_qty' => 0]);
        $m = StockMovement::where('stockable_type', Consumable::class)->where('stockable_id', $c->id)->firstOrFail();
        $call = fn (array $files) => Livewire::test(ConsumableStockHistory::class)->callAction(TestAction::make('upload')->table($m), ['files' => $files]);

        $call([UploadedFile::fake()->create('catatan.txt', 5, 'text/plain')])->assertHasActionErrors();
        $call([UploadedFile::fake()->create('app.exe', 5, 'application/x-msdownload')])->assertHasActionErrors();
        $call([UploadedFile::fake()->create('besar.pdf', 10241, 'application/pdf')])->assertHasActionErrors();          // > 10 MB
        $this->assertSame(0, $m->files()->count());
        $call([UploadedFile::fake()->createWithContent('ok1.pdf', "%PDF-1.4\n"), UploadedFile::fake()->createWithContent('ok2.png', "\x89PNG\r\n\x1a\n"), UploadedFile::fake()->createWithContent('ok3.jpg', "\xFF\xD8\xFF\xE0")])->assertHasNoActionErrors();
        $this->assertSame(3, $m->files()->count());
        $six = array_map(fn ($i) => UploadedFile::fake()->createWithContent("m$i.pdf", "%PDF-1.4\n"), range(1, 6));
        $call($six)->assertHasActionErrors();                                                                           // maks 5 file
        $this->assertSame(3, $m->files()->count());
    }

    /** Arsip TTD pada halaman BAST (modal Upload TTD) -> tampil di Arsip TTD dan di Riwayat Stok. */
    public function test_bast_signed_upload_modal_and_archive(): void
    {
        Storage::fake('local');
        $this->as($this->staff());
        $emp = Employee::where('status', 'active')->firstOrFail();
        $a = Accessory::create(['name' => 'Mouse Arsip QA36', 'qty_total' => 5, 'min_qty' => 0]);
        $h = $this->svc()->checkout($emp, [['item' => "accessory:{$a->id}", 'qty' => 2]]);
        $pdf = UploadedFile::fake()->createWithContent('BAST TTD.pdf', "%PDF-1.4\nttd");
        Livewire::test(ListAccessoryHandovers::class)->callAction(TestAction::make('upload')->table($h), ['files' => [$pdf], 'note' => 'ttd lengkap'])->assertHasNoActionErrors();
        $this->assertSame(1, $h->files()->count());
        $this->get(route('accessory-bast.file', $h->files()->first()))->assertOk();
        Livewire::test(ListAccessoryHandovers::class)->assertTableActionVisible('files', $h->fresh());
        $m = StockMovement::findOrFail($h->items->first()->stock_movement_id);
        $this->assertStringContainsString('BAST ' . $h->bast_number, $m->documents()->first()['source']);
    }

    /** Tidak boleh ada halaman panel yang 500, untuk admin, staf, dan manajemen (akses ditolak = 403, bukan error). */
    public function test_every_panel_page_loads_without_server_error(): void
    {
        $panel = Filament::getPanel('admin');
        $urls = [];
        foreach ($panel->getResources() as $res) {
            if (isset($res::getPages()['index'])) $urls[] = $res::getUrl('index');
            if (isset($res::getPages()['create'])) $urls[] = $res::getUrl('create');
        }
        foreach ($panel->getPages() as $page) $urls[] = $page::getUrl();
        $urls = array_values(array_unique($urls));
        $this->assertGreaterThan(30, count($urls));
        $bad = [];
        foreach ([$this->admin(), $this->staff(), $this->mgmt(), $this->plain()] as $u) {
            $this->as($u);
            foreach ($urls as $url) {
                $status = $this->get($url)->getStatusCode();
                if ($status >= 500) $bad[] = "{$u->email} {$url} => $status";
            }
        }
        $this->assertSame([], $bad, implode("\n", $bad));
    }
}
