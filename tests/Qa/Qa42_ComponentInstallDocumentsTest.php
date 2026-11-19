<?php

namespace Tests\Qa;

use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Filament\Resources\Components\Pages\ManageComponents;
use App\Models\Accessory;
use App\Models\AccessoryHandover;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Component;
use App\Models\Employee;
use App\Models\ITRequest;
use App\Models\StockMovement;
use App\Services\AccessoryHandoverService;
use App\Support\AccessoryBastDocument;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\QaTestCase;

/** Pasang / lepas komponen (mis. upgrade RAM): tercatat lengkap, ada dokumen, dan jelas untuk laptop dengan atau tanpa pemegang. */
class Qa42_ComponentInstallDocumentsTest extends QaTestCase
{
    private function svc(): AccessoryHandoverService { return app(AccessoryHandoverService::class); }

    private function laptop(array $over = []): Asset
    {
        $cat = Category::where('code_prefix', 'NB')->firstOrFail();
        return Asset::create($over + ['asset_tag' => Asset::generateTag($cat), 'category_id' => $cat->id, 'brand' => 'Lenovo', 'model' => 'ThinkPad T42',
            'serial_number' => 'SN-T42-' . random_int(1000, 9999), 'status' => 'available', 'condition' => 'good', 'spec_data' => ['ram' => '8 GB DDR4', 'cpu' => 'Intel Core i5-1235U']]);
    }

    private function ram(string $name, int $qty = 5): Component { return Component::create(['name' => $name, 'qty_total' => $qty, 'min_qty' => 0, 'serial_number' => null]); }

    private function stock(Component $c): array { $c = $c->fresh(); return [$c->qty_total, $c->qty_available]; }

    public function test_upgrade_ram_for_a_user_with_a_laptop_records_everything(): void
    {
        $this->as($this->staff());
        $emp = Employee::where('status', 'active')->firstOrFail();
        $pic = Employee::where('status', 'active')->where('id', '!=', $emp->id)->firstOrFail();
        $a = $this->laptop();
        app(\App\Services\AssetService::class)->checkout($a, $emp, ['condition' => 'good']);       // laptop dipegang user lewat BAST aset
        $a->refresh();
        $this->assertSame($emp->id, $a->employee_id);
        $old = $this->ram('RAM DDR4 8GB QA42'); $new = $this->ram('RAM DDR4 16GB QA42');
        // RAM 8 GB lama sudah terpasang di laptop itu
        $this->svc()->installComponents($a, [['component_id' => $old->id, 'qty' => 1]], [], 'Penambahan');
        $this->assertSame([5, 4], $this->stock($old));

        $h = $this->svc()->installComponents($a->fresh(), [['component_id' => $new->id, 'qty' => 1]], [['component_id' => $old->id, 'qty' => 1, 'condition' => 'good', 'note' => 'masih bagus']],
            'Upgrade', 'Permintaan user', null, $pic->id, ['key' => 'ram', 'value' => '16 GB DDR4']);

        // dokumen
        $this->assertSame(['install', 'asset', $a->id, $emp->id, $pic->id, 'Upgrade'], [$h->type, $h->target_type, $h->asset_id, $h->employee_id, $h->pic_id, $h->reason]);
        $this->assertMatchesRegularExpression('#^\d{3}/IT/BPK/AKP/[IVX]+/\d{4}$#', $h->bast_number);
        $this->assertSame(['remove', 'install'], $h->items->pluck('action')->all());
        $this->assertSame('BUKTI PEMASANGAN KOMPONEN ASET', $h->title());
        // stok: baru berkurang tersedia (total tetap), lama kembali tersedia
        $this->assertSame([5, 4], $this->stock($new));
        $this->assertSame([5, 5], $this->stock($old));
        // komponen terpasang & spesifikasi terbarui (sebelum -> sesudah tercatat)
        $inst = $a->fresh()->installedComponents();
        $this->assertSame([$new->id], $inst->pluck('component.id')->all());
        $a->refresh();
        $this->assertSame('16 GB DDR4', $a->spec_data['ram']);
        $this->assertSame('Intel Core i5-1235U', $a->spec_data['cpu']);                            // kolom lain utuh
        $this->assertSame([['key' => 'ram', 'label' => 'RAM', 'from' => '8 GB DDR4', 'to' => '16 GB DDR4']], $h->meta['spec_changes']);
        $this->assertStringContainsString($h->bast_number, $a->specs);
        $this->assertStringContainsString('pasang RAM DDR4 16GB QA42', $a->specs);
        // pergerakan stok terhubung ke dokumen (dokumen bisa diunggah dari Riwayat Stok)
        $m = StockMovement::findOrFail($h->items->firstWhere('action', 'install')->stock_movement_id);
        $this->assertSame([$a->id, 'install'], [$m->asset_id, $m->type]);
        $this->assertSame($h->id, $m->handoverItem->handover->id);

        // isi dokumen
        $d = AccessoryBastDocument::build($h->fresh());
        $html = view('pdf.accessory-bast', ['d' => $d])->render();
        foreach (['BUKTI PEMASANGAN KOMPONEN ASET', 'DATA PELAKSANA (IT)', 'DATA ASET', $a->asset_tag, 'ThinkPad T42', 'DATA PEMEGANG / PEMINTA', $emp->name, 'Peminta', 'Alasan',
            'DETAIL KOMPONEN', 'Dipasang', 'Dilepas', 'kembali ke stok', 'PERUBAHAN SPESIFIKASI ASET', '8 GB DDR4', '16 GB DDR4', 'TOTAL DIPASANG', 'Dikerjakan (IT)', 'Diketahui Pemegang Aset', 'Mengetahui'] as $n) {
            $this->assertStringContainsString(htmlspecialchars($n, ENT_QUOTES), $html, $n);
        }
        $this->assertSame($emp->name, $d['receiver']['name']);
        $this->get(route('accessory-bast.view', $h))->assertOk();
        $this->assertNotEmpty(AccessoryBastDocument::pdf($h->fresh())->output());

        // muncul di riwayat karyawan, halaman aset, dan daftar BAST
        $this->assertStringContainsString($h->bast_number, view('filament.employee-items', ['employee' => $emp->fresh()])->render());
        $page = $this->get(AssetResource::getUrl('view', ['record' => $a]))->assertOk()->getContent();
        foreach (['Riwayat pemasangan komponen', $h->bast_number, 'Upgrade', 'RAM: 8 GB DDR4', '16 GB DDR4', 'Peminta: ' . $pic->name, 'Belum TTD'] as $n) $this->assertStringContainsString(htmlspecialchars($n, ENT_QUOTES), $page, $n);
        $sum = $h->fresh()->itemsSummary();
        $this->assertStringContainsString('Pasang RAM DDR4 16GB QA42 × 1', $sum);
        $this->assertStringContainsString('Lepas RAM DDR4 8GB QA42 × 1', $sum);
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_laptop_without_holder_can_be_upgraded_and_the_components_follow_the_laptop_to_the_next_user(): void
    {
        $this->as($this->staff());
        $a = $this->laptop();                                                                  // tersedia, belum ada pemegang
        $this->assertNull($a->employee_id);
        $ram = $this->ram('RAM DDR4 16GB QA42b');
        $h = $this->svc()->installComponents($a, [['component_id' => $ram->id, 'qty' => 1]], [], 'Upgrade', null, null, null, ['key' => 'ram', 'value' => '16 GB DDR4']);
        $this->assertNull($h->employee_id);
        $this->assertSame([5, 4], $this->stock($ram));
        $d = AccessoryBastDocument::build($h);
        $html = view('pdf.accessory-bast', ['d' => $d])->render();
        $this->assertStringContainsString('Belum ada pemegang (stok gudang IT)', $html);
        $this->assertStringContainsString('DATA ASET (Tersedia)', $html);
        $this->assertSame('', $d['receiver']['name']);                                          // tanda tangan pemegang ditulis tangan nanti
        $this->get(route('accessory-bast.view', $h))->assertOk();
        $this->assertSame('16 GB DDR4', $a->fresh()->spec_data['ram']);

        // laptop lalu diserahkan ke karyawan: BAST aset memuat komponen yang terpasang
        $emp = Employee::where('status', 'active')->firstOrFail();
        $as = app(\App\Services\AssetService::class)->checkout($a->fresh(), $emp, ['condition' => 'good']);
        $bast = \App\Support\BastDocument::build($as->fresh());
        $this->assertStringContainsString('RAM DDR4 16GB QA42b', json_encode($bast));
        // dan komponen yang sama terlihat di halaman aset; riwayat pemasangan tetap menyimpan keadaan "belum ada pemegang"
        $this->assertNull($h->fresh()->employee_id);
        $this->get(AssetResource::getUrl('view', ['record' => $a]))->assertOk()->assertSee('Belum ada pemegang');
    }

    public function test_broken_old_component_does_not_return_to_stock(): void
    {
        $this->as($this->staff());
        $a = $this->laptop();
        $old = $this->ram('RAM Rusak QA42', 3); $new = $this->ram('RAM Baru QA42', 3);
        $this->svc()->installComponents($a, [['component_id' => $old->id, 'qty' => 2]], [], 'Penambahan');
        $this->assertSame([3, 1], $this->stock($old));
        $h = $this->svc()->installComponents($a->fresh(), [['component_id' => $new->id, 'qty' => 1]], [['component_id' => $old->id, 'qty' => 1, 'condition' => 'broken', 'note' => 'mati total']], 'Perbaikan');
        $this->assertSame([2, 1], $this->stock($old));                                          // total & tersedia berkurang 1: dihapus dari stok
        $this->assertSame('major_damage', $h->items->firstWhere('action', 'remove')->condition);
        $this->assertStringContainsString('rusak', view('pdf.accessory-bast', ['d' => AccessoryBastDocument::build($h)])->render());
        $this->assertSame(1.0, $a->fresh()->installedComponents()->firstWhere(fn ($r) => $r['component']->id === $old->id)['qty']);
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_validation_failures_change_nothing(): void
    {
        $this->as($this->staff());
        $a = $this->laptop(); $ram = $this->ram('RAM QA42v', 2); $other = $this->ram('RAM Lain QA42v', 2);
        $before = fn () => [AccessoryHandover::count(), StockMovement::count(), $this->stock($ram), $this->stock($other), $a->fresh()->specs, $a->fresh()->spec_data];
        $snap = $before();
        $line = [['component_id' => $ram->id, 'qty' => 1]];
        $cases = [
            [fn () => $this->svc()->installComponents($a, [], []), 'Pilih komponen'],
            [fn () => $this->svc()->installComponents($a, [['component_id' => $ram->id, 'qty' => 0]]), 'bilangan bulat'],
            [fn () => $this->svc()->installComponents($a, [['component_id' => $ram->id, 'qty' => 1.5]]), 'bilangan bulat'],
            [fn () => $this->svc()->installComponents($a, [['component_id' => 999999, 'qty' => 1]]), 'tidak ditemukan'],
            [fn () => $this->svc()->installComponents($a, [['component_id' => $ram->id, 'qty' => 9]]), 'melebihi'],
            [fn () => $this->svc()->installComponents($a, $line, [['component_id' => $other->id, 'qty' => 1]]), 'hanya terpasang 0'],
            [fn () => $this->svc()->installComponents($a, $line, [], 'Bebas'), 'Alasan'],
            [fn () => $this->svc()->installComponents($a, $line, [], 'Upgrade', null, null, null, ['key' => 'tidak_ada', 'value' => 'x']), 'tidak dikenal'],
            [fn () => $this->svc()->installComponents($a, $line, [], 'Upgrade', null, null, 999999), 'karyawan aktif'],
        ];
        foreach ($cases as [$fn, $msg]) {
            try { $fn(); $this->fail("harus gagal: $msg"); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString($msg, $e->getMessage()); }
            $this->assertEquals($snap, $before(), "tidak boleh ada jejak setelah gagal: $msg");        // atomik
        }
        foreach (['disposed', 'returned', 'lost'] as $st) {
            $dead = $this->laptop(['status' => $st]);
            try { $this->svc()->installComponents($dead, $line); $this->fail($st); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('tidak bisa dimodifikasi', $e->getMessage()); }
        }
        $resigned = Employee::create(['name' => 'Peminta Resign QA42', 'email' => 'pr42@t.test', 'status' => 'resigned']);
        try { $this->svc()->installComponents($a, $line, [], 'Upgrade', null, null, $resigned->id); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('karyawan aktif', $e->getMessage()); }
        $this->assertEquals($snap, $before());
    }

    public function test_request_link_and_audit_stay_consistent(): void
    {
        $this->as($this->staff());
        $a = $this->laptop(); $ram = $this->ram('RAM QA42r');
        $u = $this->plain();
        $req = ITRequest::factory()->create(['type' => 'ITR', 'user_id' => $u->id, 'status' => 'Approved', 'sequence_number' => 4242, 'numbered_at' => now(), 'title' => 'Upgrade RAM', 'items' => []]);
        $h = $this->svc()->installComponents($a, [['component_id' => $ram->id, 'qty' => 1]], [], 'Upgrade', null, $req->id);
        $this->assertSame($req->id, $h->request_id);
        $this->assertStringContainsString($req->ticket_number, view('pdf.accessory-bast', ['d' => AccessoryBastDocument::build($h)])->render());
        $done = ITRequest::factory()->create(['type' => 'ITR', 'user_id' => $u->id, 'status' => 'Completed', 'sequence_number' => 4243, 'numbered_at' => now(), 'title' => 'Selesai', 'items' => []]);
        try { $this->svc()->installComponents($a->fresh(), [['component_id' => $ram->id, 'qty' => 1]], [], 'Upgrade', null, $done->id); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Completed', $e->getMessage()); }
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_ui_actions_header_row_and_asset_page_create_documents(): void
    {
        $this->as($this->staff());
        $a = $this->laptop(); $ram = $this->ram('RAM QA42u'); $ram2 = $this->ram('RAM QA42u2');

        // tombol header "Pasang ke Aset" di menu Komponen
        Livewire::test(ManageComponents::class)->assertActionVisible('install_header')
            ->callAction('install_header', ['component_id' => $ram->id, 'asset_id' => $a->id, 'qty' => 1, 'reason' => 'Upgrade', 'spec_key' => 'ram', 'spec_value' => '16 GB DDR4'])->assertHasNoActionErrors()->assertNotified();
        $h1 = AccessoryHandover::latest('id')->first();
        $this->assertSame(['install', $a->id], [$h1->type, $h1->asset_id]);
        $this->assertSame('16 GB DDR4', $a->fresh()->spec_data['ram']);

        // aksi per baris (nama lama tetap: install)
        Livewire::test(ManageComponents::class)->callAction(TestAction::make('install')->table($ram2), ['asset_id' => $a->id, 'qty' => 1, 'reason' => 'Penambahan'])->assertHasNoActionErrors()->assertNotified();
        $h2 = AccessoryHandover::latest('id')->first();
        $this->assertNotSame($h1->id, $h2->id);

        // halaman aset: pasang + lepas
        $emp = Employee::where('status', 'active')->firstOrFail();
        $v = fn () => Livewire::test(ViewAsset::class, ['record' => $a->getKey()]);
        $v()->callAction('uninstall', ['component_id' => $ram2->id, 'qty' => 1, 'reason' => 'Perbaikan', 'condition' => 'broken'])->assertHasNoActionErrors()->assertNotified();
        $h3 = AccessoryHandover::latest('id')->first();
        $this->assertSame('remove', $h3->items->first()->action);
        $this->assertSame([4, 4], $this->stock($ram2));                                         // 5 total: pasang 1 lalu lepas rusak -> total 4, tersedia 4 (rusak tidak kembali)
        $v()->callAction('install', ['component_id' => $ram->id, 'qty' => 1, 'reason' => 'Upgrade'])->assertHasNoActionErrors()->assertNotified();
        $this->assertSame(4, AccessoryHandover::where('type', 'install')->where('asset_id', $a->id)->count());   // h1, h2, h3, + pasang terakhir

        // gagal ditampilkan jelas, tidak mengubah apa pun
        $n = AccessoryHandover::count();
        Livewire::test(ManageComponents::class)->callAction('install_header', ['component_id' => $ram->id, 'asset_id' => $a->id, 'qty' => 99, 'reason' => 'Upgrade'])->assertNotifiedFailure();
        $this->assertSame($n, AccessoryHandover::count());

        // dokumen bisa diunggah TTD-nya lewat daftar BAST & arsipnya tampil di Riwayat Stok
        \Illuminate\Support\Facades\Storage::fake('local');
        $pdf = \Illuminate\Http\UploadedFile::fake()->createWithContent('TTD Upgrade.pdf', "%PDF-1.4\nttd");
        Livewire::test(\App\Filament\Resources\AccessoryHandovers\Pages\ListAccessoryHandovers::class)->callAction(TestAction::make('upload')->table($h1), ['files' => [$pdf]])->assertHasNoActionErrors();
        $this->assertSame(1, $h1->files()->count());
        $m = StockMovement::findOrFail($h1->items->first()->stock_movement_id);
        $this->assertCount(1, $m->fresh()->documents());
        $this->get(AssetResource::getUrl('view', ['record' => $a]))->assertOk()->assertSee('TTD ✓');
    }

    public function test_guidance_and_permissions(): void
    {
        $this->as($this->staff());
        $desc = Livewire::test(ManageComponents::class)->mountAction('stock_out_header')->instance()->getMountedAction()->getModalDescription();
        $this->assertStringContainsString('Pasang ke Aset', (string) $desc);
        $this->as($this->mgmt());
        Livewire::test(ManageComponents::class)->assertActionHidden('install_header');
        $this->as($this->staff());
        $this->assertSame(['install' => 'Pemasangan Komponen'], array_diff_key(AccessoryHandover::TYPES, AccessoryHandover::FORM_TYPES));   // tidak muncul di form BAST manual
    }

    /** Dokumen pemasangan tetap satu halaman A4 walau terisi penuh (peminta, 2 lepas, 2 pasang, perubahan spesifikasi, catatan). */
    public function test_install_document_stays_on_one_page_when_fully_filled(): void
    {
        $this->as($this->staff());
        $emp = Employee::where('status', 'active')->firstOrFail();
        $pic = Employee::where('status', 'active')->where('id', '!=', $emp->id)->firstOrFail();
        $a = $this->laptop();
        app(\App\Services\AssetService::class)->checkout($a, $emp, ['condition' => 'good']);
        $o1 = $this->ram('RAM Lama Satu Dengan Nama Yang Cukup Panjang QA42p'); $o2 = $this->ram('SSD Lama Dua QA42p');
        $n1 = $this->ram('RAM Baru Satu Dengan Nama Yang Cukup Panjang QA42p'); $n2 = $this->ram('SSD Baru Dua QA42p');
        $this->svc()->installComponents($a->fresh(), [['component_id' => $o1->id, 'qty' => 1], ['component_id' => $o2->id, 'qty' => 1]], [], 'Penambahan');
        $h = $this->svc()->installComponents($a->fresh(), [['component_id' => $n1->id, 'qty' => 1], ['component_id' => $n2->id, 'qty' => 1]],
            [['component_id' => $o1->id, 'qty' => 1, 'condition' => 'broken', 'note' => 'mati'], ['component_id' => $o2->id, 'qty' => 1, 'condition' => 'good']],
            'Upgrade', str_repeat('Catatan panjang pemasangan komponen. ', 10), null, $pic->id, ['key' => 'ram', 'value' => '32 GB DDR4']);
        $out = AccessoryBastDocument::pdf($h->fresh())->output();
        $this->assertSame(1, preg_match_all('#/Type\s*/Page[^s]#', $out), 'dokumen harus satu halaman');
        $this->assertSame(4, $h->items->count());
    }

    /** Komponen yang datanya terhapus permanen tidak boleh membuat halaman / form error (pernah terjadi di aplikasi). */
    public function test_orphan_component_movements_do_not_break_pages_or_forms(): void
    {
        $this->as($this->staff());
        $a = $this->laptop(); $ram = $this->ram('RAM QA42o'); $ghost = $this->ram('RAM Hantu QA42o');
        $this->svc()->installComponents($a, [['component_id' => $ram->id, 'qty' => 1], ['component_id' => $ghost->id, 'qty' => 1]], [], 'Penambahan');
        \Illuminate\Support\Facades\DB::table('components')->where('id', $ghost->id)->delete();                // dihapus permanen
        $this->assertSame([$ram->id], $a->fresh()->installedComponents()->pluck('component.id')->all());
        $this->get(AssetResource::getUrl('view', ['record' => $a]))->assertOk()->assertSee('RAM QA42o');
        Livewire::test(ViewAsset::class, ['record' => $a->getKey()])->mountAction('uninstall')->assertSuccessful();
        Livewire::test(ManageComponents::class)->mountAction('install_header', ['asset_id' => $a->id])->assertSuccessful();
        Livewire::test(ManageComponents::class)->mountAction(TestAction::make('install')->table($ram))->assertSuccessful();
        $opts = collect(\App\Filament\Support\ComponentInstallAction::fields(true, true))->first(fn ($f) => $f->getName() === 'old_id');
        $this->assertNotNull($opts);
    }

    /** Tabel dokumen: kolom No & Qty sempit (lebar mengikuti angka), Nama Barang lebar; Data Pelaksana (IT) tiga baris; berlaku di semua jenis dokumen. */
    public function test_document_layout_narrow_no_and_qty_columns_and_three_row_executor_block(): void
    {
        $this->as($this->staff());
        $emp = Employee::where('status', 'active')->firstOrFail();
        $a = $this->laptop(); $ram = $this->ram('RAM QA42w');
        $docs = [
            $this->svc()->installComponents($a, [['component_id' => $ram->id, 'qty' => 1]], [], 'Upgrade', null, null, null, ['key' => 'ram', 'value' => '16 GB']),
            $this->svc()->checkout($emp, [['item' => 'accessory:' . Accessory::create(['name' => 'Mouse QA42w', 'qty_total' => 200, 'min_qty' => 0])->id, 'qty' => 100]]),
        ];
        foreach ($docs as $h) {
            $d = AccessoryBastDocument::build($h->fresh());
            $html = view('pdf.accessory-bast', ['d' => $d])->render();
            // judul tabel dipisah dari baris judul kolom agar lebar kolom dipakai generator PDF (table-layout fixed membaca baris pertama)
            $this->assertMatchesRegularExpression('#<th style="width:4\.5%">No</th>.*?<th style="width:7%">Qty</th>#s', $html);
            $this->assertStringNotContainsString('<colgroup><col style="width:4.5%">', $html);
            $pelaksana = collect($d['blocks'])->first(fn ($b) => str_contains($b['label'], 'PELAKSANA') || str_contains($b['label'], 'PENYERAH'));
            $this->assertSame(['Nama', 'NIK', 'Jabatan'], array_column($pelaksana['rows'], 0), $h->type);                      // tiga baris terpisah
            $this->assertStringNotContainsString('Nama / NIK / Jabatan', $html);
            $this->assertSame(1, preg_match_all('#/Type\s*/Page[^s]#', AccessoryBastDocument::pdf($h->fresh())->output()));
        }
        $this->assertSame('100', AccessoryBastDocument::build($docs[1]->fresh())['rows'][0]['qty']);
    }
}
