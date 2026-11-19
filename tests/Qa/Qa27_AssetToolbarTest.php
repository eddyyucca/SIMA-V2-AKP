<?php

namespace Tests\Qa;

use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Models\Asset;
use App\Reports\AssetListReport;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\QaTestCase;

class Qa27_AssetToolbarTest extends QaTestCase
{
    private function list()
    {
        return Livewire::test(ListAssets::class);
    }

    public function test_add_button_points_to_create_and_is_hidden_for_read_only_roles(): void
    {
        $this->as($this->staff());
        $this->list()->assertActionVisible(TestAction::make('toolbar_add')->table())->assertActionHasUrl(TestAction::make('toolbar_add')->table(), \App\Filament\Resources\Assets\AssetResource::getUrl('create'));
        $this->as($this->mgmt());
        $this->list()->assertActionHidden(TestAction::make('toolbar_add')->table())->assertActionHidden(TestAction::make('toolbar_delete')->table());
    }

    public function test_csv_and_excel_download_follow_filters_and_selection(): void
    {
        $this->as($this->staff());
        $d = now()->format('Ymd');
        $this->list()->callAction(TestAction::make('toolbar_csv')->table())->assertFileDownloaded("daftar-aset-{$d}.csv");
        $this->list()->callAction(TestAction::make('toolbar_xlsx')->table())->assertFileDownloaded("daftar-aset-{$d}.xlsx");
        $this->list()->callAction(TestAction::make('toolbar_pdf')->table())->assertFileDownloaded("daftar-aset-{$d}.pdf");

        // isi laporan: mengikuti filter status; serial tetap teks; angka tetap angka
        $assets = Asset::where('ownership', 'owned')->where('status', 'deployed')->get();
        [$head, $rows] = AssetListReport::build($assets);
        $this->assertCount($assets->count(), $rows);
        $this->assertSame('Kode', $head[0]);
        $this->assertIsFloat($rows[0][11]);                                      // harga perolehan = angka
        $this->assertIsString($rows[0][4]);                                      // serial = teks
        [, $pretty] = AssetListReport::build($assets, pretty: true);
        $this->assertIsString($pretty[0][9]);                                   // cetak: Rupiah bertitik
    }

    public function test_download_respects_current_filter_and_selected_rows(): void
    {
        $this->as($this->staff());
        $deployed = Asset::where('ownership', 'owned')->where('status', 'deployed')->get();
        $t = $this->list()->filterTable('status', 'deployed');
        $rows = $t->instance()->getFilteredSortedTableQuery()->get();
        $this->assertCount($deployed->count(), $rows);                           // semua hasil filter, bukan hanya halaman pertama

        $one = $deployed->first();
        $t->selectTableRecords([$one->id]);
        $this->assertSame([$one->id], $t->instance()->getSelectedTableRecords()->pluck('id')->all());
        $t->callAction(TestAction::make('toolbar_csv')->table())->assertFileDownloaded('daftar-aset-' . now()->format('Ymd') . '.csv');
    }

    public function test_refresh_clears_selection_and_keeps_filters(): void
    {
        $this->as($this->staff());
        $a = Asset::where('ownership', 'owned')->first();
        $t = $this->list()->filterTable('status', $a->status)->selectTableRecords([$a->id]);
        $t->callAction(TestAction::make('toolbar_refresh')->table())->assertDispatched('deselectAllTableRecords');   // centang dikosongkan di browser
        $t->assertSet('tableFilters.status.value', $a->status);                                         // filter tetap
    }

    public function test_print_creates_a_private_pdf_served_only_to_authorized_users(): void
    {
        Storage::fake('local');
        $this->as($this->staff());
        $this->list()->callAction(TestAction::make('toolbar_print')->table())->assertNotified('Dokumen cetak siap');
        $files = Storage::disk('local')->files('prints');
        $this->assertCount(1, $files);
        $id = basename($files[0], '.pdf');
        $this->get(route('print.file', $id))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('print.file', 'a1b2'))->assertNotFound();                          // nama harus 32 hex
        $this->get('/cetak/' . str_repeat('a', 32))->assertNotFound();
        $this->as($this->plain())->get(route('print.file', $id))->assertForbidden();
        auth()->forgetGuards();
        $this->get(route('print.file', $id))->assertRedirect();
    }

    public function test_delete_selected_removes_only_eligible_assets_and_reports_skipped(): void
    {
        $this->as($this->admin());
        $free = $this->freeAsset();
        $held = $this->held();
        $this->assertNotNull($held->employee_id);

        $t = $this->list()->selectTableRecords([$free->id, $held->id]);
        $t->callAction(TestAction::make('toolbar_delete')->table())->assertNotified();
        $this->assertTrue(Asset::withTrashed()->find($free->id)->trashed());
        $this->assertFalse(Asset::withTrashed()->find($held->id)->trashed());                  // sedang dipegang: dilewati
        $this->assertStringContainsString('dipegang', app(\App\Services\AssetService::class)->deleteBlocker($held->fresh()->load('employee')));
        $t->assertDispatched('deselectAllTableRecords');                                          // pilihan dikosongkan
    }

    public function test_delete_without_selection_only_warns_and_staff_cannot_delete(): void
    {
        $this->as($this->admin());
        $before = Asset::count();
        $this->list()->callAction(TestAction::make('toolbar_delete')->table())->assertNotified('Pilih aset dulu');
        $this->assertSame($before, Asset::count());

        $this->as($this->staff());                                                              // Staff IT tidak punya izin hapus
        $this->list()->assertActionHidden(TestAction::make('toolbar_delete')->table());
    }

    public function test_toolbar_buttons_are_rendered_on_the_asset_page(): void
    {
        $this->as($this->admin());
        $html = $this->get('/admin/assets')->assertOk()->getContent();
        foreach (['Tambah aset', 'Hapus aset terpilih', 'Muat ulang data', 'Export CSV', 'Unduh', 'Cetak', 'Excel (.xlsx)', 'PDF'] as $label) {
            $this->assertStringContainsString($label, $html, $label);
        }
        $this->as($this->mgmt());
        $html = $this->get('/admin/assets')->assertOk()->getContent();
        $this->assertStringNotContainsString('Hapus aset terpilih', $html);          // Manajemen: tanpa tombol hapus
        $this->assertStringContainsString('Export CSV', $html);
    }

    /**
     * Di browser, baris yang dicentang hanya diteruskan ke server bila aksi dibuka lewat `mountAction` milik Alpine tabel.
     * Aksi header biasa (wire:click) tidak membawa centang, sehingga "Hapus terpilih" selalu menganggap tidak ada pilihan.
     * Tes Livewire tidak bisa melihat ini, jadi pastikan markup tombolnya memakai handler Alpine tersebut.
     */
    public function test_toolbar_actions_that_need_selection_use_the_table_alpine_handler(): void
    {
        $this->as($this->admin());
        $html = $this->get('/admin/assets')->assertOk()->getContent();
        foreach (['toolbar_delete', 'toolbar_csv', 'toolbar_xlsx', 'toolbar_pdf', 'toolbar_print'] as $name) {
            $this->assertStringContainsString("x-on:click=\"mountAction('{$name}', {}, { table: true })\"", $html, $name);
            $this->assertStringNotContainsString("wire:click=\"mountAction('{$name}'", $html, $name);
        }
    }
}
