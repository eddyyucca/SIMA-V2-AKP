<?php

namespace Tests\Qa;

use App\Filament\Resources\AccessoryHandovers\Pages\ListAccessoryHandovers;
use App\Models\AccessoryHandover;
use App\Models\AccessoryHandoverItem;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Category;
use App\Models\Component;
use App\Models\Employee;
use App\Models\StockMovement;
use App\Services\ComponentInstallBackfill;
use App\Support\AccessoryBastDocument;
use Carbon\Carbon;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\QaTestCase;

/** Dokumen susulan untuk pemasangan / pelepasan komponen lama: hanya membuat dokumen, stok tidak berubah, aman diulang. */
class Qa43_ComponentInstallBackfillTest extends QaTestCase
{
    private function svc(): ComponentInstallBackfill { return app(ComponentInstallBackfill::class); }

    private function laptop(): Asset
    {
        $cat = Category::where('code_prefix', 'NB')->firstOrFail();
        return Asset::create(['asset_tag' => Asset::generateTag($cat), 'category_id' => $cat->id, 'brand' => 'Lenovo', 'model' => 'T43', 'serial_number' => 'SN-T43-' . random_int(1000, 9999), 'status' => 'available', 'condition' => 'good']);
    }

    private function legacy(Component $c, Asset $a, string $type, string $at, string $reason = 'Upgrade', int $by = 1): StockMovement
    {
        return StockMovement::create(['stockable_type' => Component::class, 'stockable_id' => $c->id, 'type' => $type, 'qty' => 1, 'asset_id' => $a->id, 'reason' => $reason, 'moved_at' => Carbon::parse($at), 'created_by' => $by]);
    }

    private function snapshot(): array
    {
        return [
            Component::withTrashed()->orderBy('id')->get(['id', 'qty_total', 'qty_available'])->toArray(),
            StockMovement::count(),
            Asset::withTrashed()->orderBy('id')->get(['id', 'specs', 'spec_data', 'status', 'employee_id'])->toArray(),
        ];
    }

    public function test_demo_legacy_movements_get_documents_without_changing_stock_and_is_idempotent(): void
    {
        $this->as($this->staff());
        $legacyCount = $this->svc()->plan()->flatMap(fn ($g) => $g['moves'])->count();
        $this->assertGreaterThan(0, $legacyCount, 'data demo punya pemasangan lama');
        $snap = $this->snapshot();
        $docsBefore = AccessoryHandover::count();

        // dry-run: tidak menyimpan apa pun
        $this->artisan('components:backfill-install-documents --dry-run')->expectsOutputToContain('akan dibuat')->assertSuccessful();
        $this->assertSame($docsBefore, AccessoryHandover::count());

        $r = $this->svc()->run();
        $this->assertGreaterThan(0, $r['created']);
        $this->assertSame(0, $r['skipped']);
        $this->assertSame($docsBefore + $r['created'], AccessoryHandover::count());
        $this->assertEquals($snap, $this->snapshot(), 'stok, spesifikasi, dan riwayat tidak boleh berubah');
        $this->assertTrue($this->svc()->plan()->isEmpty(), 'semua pergerakan lama sudah berdokumen');
        $this->assertSame($legacyCount, AccessoryHandoverItem::whereIn('action', ['install', 'remove'])->whereNotNull('stock_movement_id')->count());

        // diulang: tidak ada yang baru
        $again = $this->svc()->run();
        $this->assertSame(0, $again['created']);
        $this->artisan('components:backfill-install-documents')->expectsOutputToContain('0 dokumen')->assertSuccessful();
        $this->assertSame($docsBefore + $r['created'], AccessoryHandover::count());
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_grouping_dates_numbers_and_holder_at_that_time(): void
    {
        $this->as($this->staff());
        $this->svc()->run();                                                                    // bereskan data demo lebih dulu
        $a = $this->laptop(); $b = $this->laptop();
        $e1 = Employee::where('status', 'active')->firstOrFail();
        $ram8 = Component::create(['name' => 'RAM 8 QA43', 'qty_total' => 5, 'min_qty' => 0]); $ram16 = Component::create(['name' => 'RAM 16 QA43', 'qty_total' => 5, 'min_qty' => 0]);

        // riwayat pemegang laptop A: stok gudang -> e1 (1 Okt) -> kembali ke gudang (20 Okt)
        AssetAssignment::create(['asset_id' => $a->id, 'type' => 'checkout', 'from_employee_id' => null, 'to_employee_id' => $e1->id, 'assigned_at' => Carbon::parse('2026-10-01 09:00'), 'condition' => 'good', 'bast_number' => 'BAST/T43/1']);
        AssetAssignment::create(['asset_id' => $a->id, 'type' => 'checkin', 'from_employee_id' => $e1->id, 'to_employee_id' => null, 'assigned_at' => Carbon::parse('2026-10-20 09:00'), 'condition' => 'good', 'bast_number' => 'BAST/T43/2']);

        // sebelum diserahkan (gudang), saat dipegang e1 (satu proses ganti: lepas+pasang berdekatan), setelah dikembalikan, tahun berbeda
        $m0 = $this->legacy($ram8, $a, 'install', '2026-09-20 10:00:00', 'Penambahan');
        $m1 = $this->legacy($ram8, $a, 'uninstall', '2026-10-10 14:00:00');
        $m2 = $this->legacy($ram16, $a, 'install', '2026-10-10 14:00:01');
        $m3 = $this->legacy($ram16, $a, 'uninstall', '2026-10-25 08:00:00', 'Perbaikan');
        $m4 = $this->legacy($ram16, $a, 'install', '2026-10-10 14:10:00');                       // 10 menit kemudian: dokumen terpisah
        $m5 = $this->legacy($ram8, $b, 'install', '2025-12-31 23:00:00', 'Aneh Banget');         // tahun lain, alasan di luar daftar

        $plan = $this->svc()->plan();
        $this->assertSame(5, $plan->count());
        $r = $this->svc()->run();
        $this->assertSame(5, $r['created']);

        $doc = fn (StockMovement $m) => AccessoryHandoverItem::where('stock_movement_id', $m->id)->firstOrFail()->handover;
        $d0 = $doc($m0); $d12 = $doc($m1); $d3 = $doc($m3); $d4 = $doc($m4); $d5 = $doc($m5);
        $this->assertSame($d12->id, $doc($m2)->id);                                              // lepas+pasang satu proses -> satu dokumen
        $this->assertCount(2, $d12->items);
        $this->assertSame(['remove', 'install'], $d12->items->pluck('action')->all());
        $this->assertCount(5, collect([$d0, $d12, $d3, $d4, $d5])->pluck('id')->unique());

        // tanggal kejadian, bukan tanggal pembuatan; nomor memakai bulan & tahun kejadian
        $this->assertSame('2026-10-10 14:00:00', $d12->handed_at->format('Y-m-d H:i:s'));
        $this->assertMatchesRegularExpression('#^\d{3}/IT/BPK/AKP/X/2026$#', $d12->bast_number);
        $this->assertMatchesRegularExpression('#^\d{3}/IT/BPK/AKP/IX/2026$#', $d0->bast_number);
        $this->assertMatchesRegularExpression('#^001/IT/BPK/AKP/XII/2025$#', $d5->bast_number);
        $this->assertCount(AccessoryHandover::where('type', 'install')->count(), AccessoryHandover::where('type', 'install')->pluck('bast_number')->unique());

        // pemegang aset saat itu
        $this->assertNull($d0->employee_id);                                                     // sebelum diserahkan: gudang IT
        $this->assertSame($e1->id, $d12->employee_id);                                           // saat dipegang e1
        $this->assertSame($e1->id, $d4->employee_id);
        $this->assertNull($d3->employee_id);                                                     // setelah dikembalikan ke gudang
        $this->assertNull($d5->employee_id);                                                     // aset tanpa riwayat

        // alasan, catatan susulan, penanda
        $this->assertSame(['Penambahan', 'Upgrade', 'Perbaikan'], [$d0->reason, $d12->reason, $d3->reason]);
        $this->assertNull($d5->reason);
        $this->assertStringContainsString('Aneh Banget', $d5->notes);
        $this->assertStringContainsString('Dokumen susulan', $d12->notes);
        $this->assertTrue($d12->meta['backfilled']);
        $this->assertSame('good', $d12->items->firstWhere('action', 'remove')->condition);
        $this->assertSame([1, 1], [$d12->created_by, $d3->created_by]);                          // pelaksana = pembuat pergerakan lama
    }

    public function test_documents_render_link_to_history_and_accept_signed_scans(): void
    {
        Storage::fake('local');
        $this->as($this->staff());
        $this->svc()->run();
        $a = $this->laptop(); $ram = Component::create(['name' => 'RAM Susulan QA43', 'qty_total' => 3, 'min_qty' => 0]);
        $m = $this->legacy($ram, $a, 'install', '2026-11-02 10:00:00');
        $this->svc()->run();
        $h = AccessoryHandoverItem::where('stock_movement_id', $m->id)->firstOrFail()->handover;

        $html = view('pdf.accessory-bast', ['d' => AccessoryBastDocument::build($h->fresh())])->render();
        foreach (['BUKTI PEMASANGAN KOMPONEN ASET', $a->asset_tag, 'RAM Susulan QA43', 'Dipasang', 'Belum ada pemegang (stok gudang IT)', 'Dokumen susulan', '02-11-2026'] as $n) $this->assertStringContainsString($n, $html, $n);
        $this->assertSame(1, preg_match_all('#/Type\s*/Page[^s]#', AccessoryBastDocument::pdf($h->fresh())->output()));
        $this->get(route('accessory-bast.view', $h))->assertOk();

        // tampil di halaman aset & Riwayat Stok (dokumen terhubung ke pergerakan lama)
        $this->get(\App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $a]))->assertOk()->assertSee($h->bast_number)->assertSee('Belum TTD');
        $this->assertSame($h->id, $m->fresh()->handoverItem->handover->id);
        $pdf = \Illuminate\Http\UploadedFile::fake()->createWithContent('TTD Susulan.pdf', "%PDF-1.4\nttd");
        Livewire::test(ListAccessoryHandovers::class)->callAction(TestAction::make('upload')->table($h), ['files' => [$pdf]])->assertHasNoActionErrors();
        $this->assertSame(1, $h->files()->count());
        $this->get(\App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $a]))->assertOk()->assertSee('TTD ✓');
        $this->assertCount(1, $m->fresh()->documents());
    }

    public function test_orphans_are_skipped_and_new_flow_documents_are_not_duplicated(): void
    {
        $this->as($this->staff());
        $this->svc()->run();
        $a = $this->laptop(); $ghost = Component::create(['name' => 'Hantu QA43', 'qty_total' => 1, 'min_qty' => 0]); $ok = Component::create(['name' => 'Normal QA43', 'qty_total' => 4, 'min_qty' => 0]);
        $this->legacy($ghost, $a, 'install', '2026-11-03 09:00:00');
        \Illuminate\Support\Facades\DB::table('components')->where('id', $ghost->id)->delete();                  // data komponen sudah terhapus permanen
        $r = $this->svc()->run();
        $this->assertSame(['created' => 0, 'skipped' => 1], ['created' => $r['created'], 'skipped' => $r['skipped']]);   // dilewati, tidak error

        // dokumen dari alur baru (sudah berdokumen) tidak dibuat ulang
        $h = app(\App\Services\AccessoryHandoverService::class)->installComponents($a, [['component_id' => $ok->id, 'qty' => 1]], [], 'Upgrade');
        $before = AccessoryHandover::count();
        $this->svc()->run();
        $this->assertSame($before, AccessoryHandover::count());
        $this->assertCount(1, $h->fresh()->items);
    }
}
