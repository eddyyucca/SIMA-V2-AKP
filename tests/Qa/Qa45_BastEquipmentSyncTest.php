<?php

namespace Tests\Qa;

use App\Filament\Resources\Assets\AssetResource;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Category;
use App\Models\Component;
use App\Models\Employee;
use App\Models\StockMovement;
use App\Models\Vendor;
use App\Services\AccessoryHandoverService;
use App\Services\AssetService;
use App\Services\StockService;
use App\Support\BastDocument;
use Illuminate\Support\Facades\Storage;
use Tests\QaTestCase;

/** Kelengkapan pada BAST utama: bertambah / berkurang mengikuti pemasangan komponen, terhubung ke pembelian (PO, harga) dan dokumen pemasangan. */
class Qa45_BastEquipmentSyncTest extends QaTestCase
{
    private function laptop(): Asset
    {
        $cat = Category::where('code_prefix', 'NB')->firstOrFail();
        return Asset::create(['asset_tag' => Asset::generateTag($cat), 'category_id' => $cat->id, 'brand' => 'Lenovo', 'model' => 'T45', 'serial_number' => 'SN-T45-' . random_int(1000, 9999), 'status' => 'available', 'condition' => 'good']);
    }

    /** Komponen dengan pembelian (PO + harga) agar kelengkapan terhubung ke data pembelian. */
    private function part(string $name, int $qty = 5, string $po = 'PO-QA45-001', float $price = 650000, ?string $sn = null): Component
    {
        $c = Component::create(['name' => $name, 'qty_total' => 0, 'min_qty' => 0, 'serial_number' => $sn]);
        app(StockService::class)->move($c, 'in', $qty, ['vendor_id' => Vendor::firstOrFail()->id, 'unit_price' => $price, 'reference_no' => $po, 'received_at' => now()->toDateString()]);
        return $c->fresh();
    }

    private function holderBast(Asset $a): ?AssetAssignment { return app(AssetService::class)->currentHolderBast($a->fresh()); }

    private function doc(AssetAssignment $a): array { return BastDocument::build($a->fresh(['asset', 'from', 'to', 'creator', 'location'])); }

    private function svc(): AccessoryHandoverService { return app(AccessoryHandoverService::class); }

    public function test_equipment_grows_and_shrinks_on_the_holders_bast_and_links_to_purchase_and_install_document(): void
    {
        Storage::fake('local');
        $this->as($this->staff());
        $emp = Employee::where('status', 'active')->firstOrFail();
        $a = $this->laptop();
        $as = app(AssetService::class)->checkout($a, $emp, ['condition' => 'good']);
        $this->assertSame([], $this->doc($as)['extras']);                                     // belum ada kelengkapan
        $first = Storage::disk('local')->get($as->bast_pdf_path);
        $ram = $this->part('RAM DDR4 16GB QA45', 5, 'PO-QA45-777', 650000, 'RM-9988');

        $h = $this->svc()->installComponents($a->fresh(), [['component_id' => $ram->id, 'qty' => 2]], [], 'Upgrade');
        $as = $as->fresh();
        // BAST pemegang diperbarui: waktu pembaruan terisi, PDF dibuat ulang, berkas lama dicadangkan
        $this->assertNotNull($as->equipment_updated_at);
        $this->assertNotSame($first, Storage::disk('local')->get($as->bast_pdf_path));
        $this->assertCount(1, Storage::disk('local')->files('bast-backup'));
        $this->assertSame($first, Storage::disk('local')->get(Storage::disk('local')->files('bast-backup')[0]));   // cadangan = isi lama
        $d = $this->doc($as);
        $this->assertCount(1, $d['extras']);
        $x = $d['extras'][0];
        $this->assertSame('RAM DDR4 16GB QA45 × 2 · SN RM-9988', $x['name']);
        $this->assertSame('PO-QA45-777', $x['po']);                                           // terhubung ke pembelian
        $this->assertSame('Rp 1.300.000', $x['cost']);                                        // harga satuan × jumlah
        $this->assertSame('Terpasang ' . now()->format('d-m-Y'), $x['status']);
        $this->assertSame($h->bast_number, $x['ref']);                                        // terhubung ke dokumen pemasangan
        $this->assertStringContainsString('Kelengkapan diperbarui per ' . now()->format('d-m-Y'), (string) $d['extrasNote']);
        $html = view('pdf.bast', ['a' => $as->fresh(['asset.category', 'from.department', 'to.department', 'location', 'creator']), 'sigReceiver' => null, 'sigIt' => null])->render();
        foreach (['RAM DDR4 16GB QA45', 'PO-QA45-777', 'Rp 1.300.000', 'Terpasang', $h->bast_number, 'Kelengkapan diperbarui'] as $n) $this->assertStringContainsString($n, $html, $n);
        $this->assertStringNotContainsString('Tidak Ada Kelengkapan Asset', $html);

        // dilepas -> berkurang lagi
        $this->svc()->installComponents($a->fresh(), [], [['component_id' => $ram->id, 'qty' => 2, 'condition' => 'good']], 'Perbaikan');
        $this->assertSame([], $this->doc($as->fresh())['extras']);
        $this->assertCount(2, Storage::disk('local')->files('bast-backup'));
        $this->assertStringContainsString('Tidak Ada Kelengkapan Asset', view('pdf.bast', ['a' => $as->fresh(['asset.category', 'from.department', 'to.department', 'location', 'creator']), 'sigReceiver' => null, 'sigIt' => null])->render());
    }

    public function test_only_the_current_holders_bast_is_refreshed_and_asset_without_holder_waits_for_the_next_bast(): void
    {
        Storage::fake('local');
        $this->as($this->staff());
        $e1 = Employee::where('status', 'active')->orderBy('id')->firstOrFail();
        $e2 = Employee::where('status', 'active')->where('id', '!=', $e1->id)->orderBy('id')->firstOrFail();
        $a = $this->laptop();
        $ram = $this->part('RAM QA45b');

        // belum ada pemegang: tidak ada BAST aktif -> tidak ada yang diperbarui, tidak ada cadangan
        $this->assertNull($this->holderBast($a));
        $this->svc()->installComponents($a, [['component_id' => $ram->id, 'qty' => 1]], [], 'Upgrade');
        $this->assertSame([], Storage::disk('local')->files('bast-backup'));
        // BAST serah terima berikutnya sudah memuat komponennya
        $b1 = app(AssetService::class)->checkout($a->fresh(), $e1, ['condition' => 'good']);
        $this->assertCount(1, $this->doc($b1)['extras']);
        $this->assertNull($b1->fresh()->equipment_updated_at);                                 // dibuat sesudah pemasangan: tidak perlu "diperbarui"

        // mutasi ke pemegang baru: hanya BAST mutasi (aktif) yang diperbarui, BAST lama tetap
        $b2 = app(AssetService::class)->transfer($a->fresh(), $e2, ['condition' => 'good']);
        $old1 = Storage::disk('local')->get($b1->bast_pdf_path);
        $ram2 = $this->part('SSD QA45b');
        $this->svc()->installComponents($a->fresh(), [['component_id' => $ram2->id, 'qty' => 1]], [], 'Penambahan');
        $this->assertNotNull($b2->fresh()->equipment_updated_at);
        $this->assertNull($b1->fresh()->equipment_updated_at);
        $this->assertSame($old1, Storage::disk('local')->get($b1->bast_pdf_path));              // BAST lama tidak disentuh
        $this->assertCount(2, $this->doc($b2)['extras']);
        $this->assertSame($b2->id, $this->holderBast($a)->id);

        // dikembalikan ke gudang: BAST pengembalian adalah catatan saat itu, tidak diperbarui oleh pemasangan di gudang
        $c = app(AssetService::class)->checkin($a->fresh(), ['condition' => 'good']);
        $this->assertNull($this->holderBast($a));
        $ram3 = $this->part('Baterai QA45b');
        $before = Storage::disk('local')->get($c->bast_pdf_path);
        $this->svc()->installComponents($a->fresh(), [['component_id' => $ram3->id, 'qty' => 1]], [], 'Penambahan');
        $this->assertSame($before, Storage::disk('local')->get($c->bast_pdf_path));
        $this->assertNull($c->fresh()->equipment_updated_at);
    }

    public function test_equipment_rows_are_limited_summarised_and_the_bast_always_stays_on_one_page(): void
    {
        Storage::fake('local');
        $this->as($this->staff());
        $emp = Employee::where('status', 'active')->firstOrFail();
        $a = $this->laptop();
        $as = app(AssetService::class)->checkout($a, $emp, ['condition' => 'good']);
        $pages = fn () => preg_match_all('#/Type\s*/Page[^s]#', \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.bast', ['a' => $as->fresh(['asset.category', 'from.department', 'to.department', 'location', 'creator']), 'sigReceiver' => null, 'sigIt' => null])->setPaper('a4')->output());
        $this->assertSame(1, $pages(), 'tanpa kelengkapan: satu halaman');
        foreach (range(1, 8) as $i) {
            $this->svc()->installComponents($a->fresh(), [['component_id' => $this->part("Komponen Dengan Nama Cukup Panjang Nomor $i QA45", 3, "PO-QA45-$i", 100000 * $i, "SERIAL$i")->id, 'qty' => 1]], [], 'Penambahan');
            $d = $this->doc($as);
            $this->assertCount(min($i, BastDocument::MAX_EQUIPMENT_ROWS), $d['extras'], "baris untuk $i komponen");    // meluap: baris terakhir = ringkasan
            $this->assertSame(1, $pages(), "BAST harus tetap satu halaman dengan $i komponen");
        }
        $d = $this->doc($as);
        $this->assertStringContainsString('+ 5 komponen lainnya', end($d['extras'])['name']);                 // 8 komponen: 3 tampil + ringkasan 5
        $this->assertTrue($d['compact']);
        // jumlah dokumen pemasangan yang dirujuk diringkas di keterangan
        $this->assertStringContainsString('Dokumen pemasangan:', (string) $d['extrasNote']);
        $this->assertStringContainsString('lainnya', (string) $d['extrasNote']);
    }

    public function test_a_pdf_failure_never_cancels_the_installation(): void
    {
        Storage::fake('local');
        $this->as($this->staff());
        $emp = Employee::where('status', 'active')->firstOrFail();
        $a = $this->laptop();
        app(AssetService::class)->checkout($a, $emp, ['condition' => 'good']);
        $ram = $this->part('RAM QA45f');
        app()->instance(AssetService::class, new class extends AssetService {
            public function __construct() {}
            public function refreshEquipment(Asset $asset): ?AssetAssignment { throw new \RuntimeException('PDF gagal dibuat'); }
        });
        $h = app(AccessoryHandoverService::class)->installComponents($a->fresh(), [['component_id' => $ram->id, 'qty' => 1]], [], 'Upgrade');
        $this->assertSame(1, $a->fresh()->installedComponents()->count());                    // pemasangan tetap tercatat
        $this->assertSame($h->id, \App\Models\AccessoryHandover::latest('id')->first()->id);
        $this->assertSame(4, (int) Component::find($ram->id)->qty_available);
    }

    public function test_command_finds_outdated_bast_and_is_idempotent_and_asset_page_shows_details(): void
    {
        Storage::fake('local');
        $this->as($this->staff());
        $emp = Employee::where('status', 'active')->firstOrFail();
        $a = $this->laptop();
        $as = app(AssetService::class)->checkout($a, $emp, ['condition' => 'good']);
        $as->update(['assigned_at' => now()->subDays(2)]);                                      // BAST dibuat 2 hari lalu
        $ram = $this->part('RAM Lama QA45c', 3, 'PO-QA45-C', 500000);
        // pemasangan sesudah BAST dibuat yang tidak lewat alur baru (tidak memperbarui BAST)
        StockMovement::create(['stockable_type' => Component::class, 'stockable_id' => $ram->id, 'type' => 'install', 'qty' => 1, 'asset_id' => $a->id, 'reason' => 'Upgrade', 'moved_at' => now()->subDay(), 'created_by' => 1]);
        $svc = app(AssetService::class);
        $this->assertTrue($svc->equipmentOutdated($a->fresh()));

        // halaman aset menandai BAST yang tertinggal dan menampilkan data terhubung
        $page = $this->get(AssetResource::getUrl('view', ['record' => $a]))->assertOk()->getContent();
        foreach (['RAM Lama QA45c', 'PO PO-QA45-C', 'Rp 500.000', $as->bast_number, 'belum memuat komponen terbaru'] as $n) $this->assertStringContainsString($n, $page, $n);

        $this->assertContains($as->id, collect(range(1, 1))->map(fn () => $svc->currentHolderBast($a->fresh())->id)->all());
        $this->artisan('bast:refresh-equipment --dry-run')->expectsOutputToContain($as->bast_number)->expectsOutputToContain('perlu diperbarui')->assertSuccessful();
        $this->assertNull($as->fresh()->equipment_updated_at);                                 // dry-run tidak mengubah
        $this->artisan('bast:refresh-equipment')->expectsOutputToContain('diperbarui')->assertSuccessful();
        $this->assertNotNull($as->fresh()->equipment_updated_at);
        $this->assertFalse($svc->equipmentOutdated($a->fresh()));
        $this->assertCount(1, $this->doc($as)['extras']);
        $this->artisan('bast:refresh-equipment')->expectsOutputToContain('0 BAST diperbarui')->assertSuccessful();   // diulang: tidak ada lagi
        $this->assertStringNotContainsString('belum memuat komponen terbaru', $this->get(AssetResource::getUrl('view', ['record' => $a]))->getContent());
    }

    public function test_old_bast_still_shows_the_equipment_of_its_own_date(): void
    {
        $this->as($this->staff());
        $a = AssetAssignment::with('asset')->where('type', 'checkout')->orderBy('id')->firstOrFail();
        $c = $this->part('RAM Sesudah BAST QA45d');
        StockMovement::create(['stockable_type' => Component::class, 'stockable_id' => $c->id, 'type' => 'install', 'qty' => 1, 'asset_id' => $a->asset_id, 'reason' => 'Upgrade', 'moved_at' => $a->assigned_at->copy()->addDays(2), 'created_by' => 1]);
        $this->assertStringNotContainsString('RAM Sesudah BAST QA45d', json_encode($this->doc($a)));        // belum diperbarui: tetap keadaan saat BAST dibuat
        $a->update(['equipment_updated_at' => $a->assigned_at->copy()->addDays(3)]);                       // setelah diperbarui: ikut
        $this->assertStringContainsString('RAM Sesudah BAST QA45d', json_encode($this->doc($a)));
    }
}
