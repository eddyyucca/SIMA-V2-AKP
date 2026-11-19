<?php

namespace App\Filament\Support;

use App\Filament\Resources\Assets\AssetResource;
use App\Models\Asset;
use App\Reports\AssetListReport;
use App\Reports\TextExport;
use App\Services\AssetService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;

/** Deretan tombol ikon di atas tabel Aset: tambah, hapus terpilih, muat ulang, CSV, unduh (Excel/PDF), cetak. */
class AssetToolbar
{
    private const MAX_ROWS = 20000;

    /**
     * Di Filament, baris yang dicentang hanya ada di browser sampai sebuah aksi dibuka lewat Alpine `mountAction` milik tabel.
     * Tanpa ini, tombol di header tabel tidak pernah melihat centang. Ekspresi ini tetap di dalam lingkup Alpine tabel.
     */
    private static function mountWithSelection(string $name): string
    {
        return "mountAction('{$name}', {}, { table: true })";
    }

    public static function actions(): array
    {
        return [
            Action::make('toolbar_add')->label('Tambah aset')->tooltip('Tambah aset')->icon(Heroicon::OutlinedPlus)->iconButton()
                ->visible(fn () => auth()->user()->can('create', Asset::class))
                ->url(fn () => AssetResource::getUrl('create')),

            Action::make('toolbar_delete')->label('Hapus terpilih')->tooltip('Hapus aset terpilih')->icon(Heroicon::OutlinedTrash)->iconButton()->color('danger')
                ->visible(fn () => auth()->user()->can('deleteAny', Asset::class))
                ->alpineClickHandler(static::mountWithSelection('toolbar_delete'))
                ->modalHeading('Hapus aset terpilih?')
                ->modalDescription(fn ($livewire) => $livewire->getSelectedTableRecords()->isEmpty()
                    ? 'Belum ada aset yang dicentang. Centang kotak di sebelah kiri baris aset dulu.'
                    : $livewire->getSelectedTableRecords()->count() . ' aset akan dihapus (bisa dipulihkan lewat filter "Dihapus"). Aset yang sedang dipegang, tidak berstatus Tersedia, atau punya urusan terbuka akan dilewati.')
                ->modalSubmitActionLabel('Hapus')
                ->requiresConfirmation()
                ->action(function ($livewire) {
                    $selected = $livewire->getSelectedTableRecords();
                    if ($selected->isEmpty()) {
                        Notification::make()->warning()->title('Pilih aset dulu')->body('Centang kotak di sebelah kiri aset yang ingin dihapus.')->send();
                        return;
                    }
                    $res = app(AssetService::class)->deleteMany($selected);
                    $livewire->deselectAllTableRecords();
                    $skipped = collect($res['skipped'])->map(fn ($why, $tag) => "{$tag}: {$why}")->values();
                    $n = Notification::make()->title(count($res['deleted']) . ' aset dihapus' . ($skipped->isNotEmpty() ? ', ' . $skipped->count() . ' dilewati' : ''))
                        ->{$skipped->isNotEmpty() ? 'warning' : 'success'}();
                    if ($skipped->isNotEmpty()) {
                        $n->body(new \Illuminate\Support\HtmlString($skipped->take(8)->map(fn ($s) => e($s))->implode('<br>') . ($skipped->count() > 8 ? '<br>… dan ' . ($skipped->count() - 8) . ' lainnya.' : '')))->persistent();
                    }
                    $n->send();
                }),

            Action::make('toolbar_refresh')->label('Muat ulang')->tooltip('Muat ulang data')->icon(Heroicon::OutlinedArrowPath)->iconButton()
                ->action(fn ($livewire) => $livewire->deselectAllTableRecords()),

            Action::make('toolbar_csv')->label('CSV')->tooltip('Export CSV')->icon(Heroicon::OutlinedTableCells)->iconButton()
                ->alpineClickHandler(static::mountWithSelection('toolbar_csv'))
                ->action(fn ($livewire) => static::download($livewire, ExcelType::CSV, 'csv')),

            ActionGroup::make([
                Action::make('toolbar_xlsx')->label('Excel (.xlsx)')->icon(Heroicon::OutlinedTableCells)
                    ->alpineClickHandler(static::mountWithSelection('toolbar_xlsx'))
                    ->action(fn ($livewire) => static::download($livewire, ExcelType::XLSX, 'xlsx')),
                Action::make('toolbar_pdf')->label('PDF')->icon(Heroicon::OutlinedDocumentText)
                    ->alpineClickHandler(static::mountWithSelection('toolbar_pdf'))
                    ->action(function ($livewire) {
                        $pdf = static::pdf($livewire);
                        return response()->streamDownload(fn () => print($pdf->output()), 'daftar-aset-' . now()->format('Ymd') . '.pdf');
                    }),
            ])->icon(Heroicon::OutlinedArrowDownTray)->tooltip('Unduh')->iconButton()->dropdownPlacement('bottom-end'),

            Action::make('toolbar_print')->label('Cetak')->tooltip('Cetak')->icon(Heroicon::OutlinedPrinter)->iconButton()
                ->alpineClickHandler(static::mountWithSelection('toolbar_print'))
                ->action(function ($livewire) {
                    $file = 'prints/' . bin2hex(random_bytes(16)) . '.pdf';
                    $disk = Storage::disk('local');
                    foreach ($disk->files('prints') as $old) {                       // bersihkan hasil cetak > 1 jam
                        if ($disk->lastModified($old) < now()->subHour()->getTimestamp()) $disk->delete($old);
                    }
                    $disk->put($file, static::pdf($livewire)->output());
                    $url = route('print.file', ['file' => basename($file, '.pdf')]);
                    $livewire->js('window.open(' . json_encode($url) . ", '_blank')");
                    Notification::make()->title('Dokumen cetak siap')->body('Jika tab baru tidak terbuka, klik tombol di bawah.')
                        ->actions([\Filament\Actions\Action::make('open')->label('Buka untuk dicetak')->url($url, shouldOpenInNewTab: true)])->send();
                }),
        ];
    }

    /** Baris yang diekspor: yang dicentang; bila tidak ada, semua hasil pencarian/filter saat ini (tanpa batas halaman). */
    private static function records($livewire): Collection
    {
        $selected = $livewire->getSelectedTableRecords();
        if ($selected->isNotEmpty()) return $selected->values();
        return $livewire->getFilteredSortedTableQuery()->limit(self::MAX_ROWS)->get();
    }

    private static function download($livewire, string $type, string $ext)
    {
        [$head, $rows] = AssetListReport::build(static::records($livewire));
        return Excel::download(new TextExport($head, $rows), 'daftar-aset-' . now()->format('Ymd') . '.' . $ext, $type);
    }

    private static function pdf($livewire)
    {
        [$head, $rows] = AssetListReport::build(static::records($livewire), pretty: true);
        return Pdf::loadView('pdf.table', ['title' => 'Daftar Aset', 'head' => $head, 'rows' => $rows])->setPaper('a4', 'landscape');
    }
}
