<?php

namespace App\Filament\Support;

use App\Reports\StockListReport;
use App\Reports\TextExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Tombol ikon di atas tabel Accessories / Komponen / Consumable: CSV, unduh (Excel/PDF), cetak.
 * Yang diekspor adalah semua hasil pencarian dan filter saat ini (tanpa batas halaman).
 */
class StockToolbar
{
    private const MAX_ROWS = 20000;

    /** @param class-string $class Accessory::class | Component::class | Consumable::class */
    public static function actions(string $class): array
    {
        return [
            Action::make('toolbar_csv')->label('CSV')->tooltip('Export CSV')->icon(Heroicon::OutlinedTableCells)->iconButton()
                ->action(fn ($livewire) => static::download($livewire, $class, ExcelType::CSV, 'csv')),

            ActionGroup::make([
                Action::make('toolbar_xlsx')->label('Excel (.xlsx)')->icon(Heroicon::OutlinedTableCells)
                    ->action(fn ($livewire) => static::download($livewire, $class, ExcelType::XLSX, 'xlsx')),
                Action::make('toolbar_pdf')->label('PDF')->icon(Heroicon::OutlinedDocumentText)
                    ->action(function ($livewire) use ($class) {
                        $pdf = static::pdf($livewire, $class);
                        return response()->streamDownload(fn () => print($pdf->output()), StockListReport::FILES[$class] . '-' . now()->format('Ymd') . '.pdf');
                    }),
            ])->icon(Heroicon::OutlinedArrowDownTray)->tooltip('Unduh')->iconButton()->dropdownPlacement('bottom-end'),

            Action::make('toolbar_print')->label('Cetak')->tooltip('Cetak')->icon(Heroicon::OutlinedPrinter)->iconButton()
                ->action(function ($livewire) use ($class) {
                    $file = 'prints/' . bin2hex(random_bytes(16)) . '.pdf';
                    $disk = Storage::disk('local');
                    foreach ($disk->files('prints') as $old) {                       // bersihkan hasil cetak > 1 jam
                        if ($disk->lastModified($old) < now()->subHour()->getTimestamp()) $disk->delete($old);
                    }
                    $disk->put($file, static::pdf($livewire, $class)->output());
                    $url = route('print.file', ['file' => basename($file, '.pdf')]);
                    $livewire->js('window.open(' . json_encode($url) . ", '_blank')");
                    Notification::make()->title('Dokumen cetak siap')->body('Jika tab baru tidak terbuka, klik tombol di bawah.')
                        ->actions([Action::make('open')->label('Buka untuk dicetak')->url($url, shouldOpenInNewTab: true)])->send();
                }),
        ];
    }

    private static function records($livewire): Collection
    {
        return $livewire->getFilteredSortedTableQuery()->limit(self::MAX_ROWS)->get();
    }

    private static function download($livewire, string $class, string $type, string $ext)
    {
        [$head, $rows] = StockListReport::build($class, static::records($livewire));
        return Excel::download(new TextExport($head, $rows), StockListReport::FILES[$class] . '-' . now()->format('Ymd') . '.' . $ext, $type);
    }

    private static function pdf($livewire, string $class)
    {
        [$head, $rows] = StockListReport::build($class, static::records($livewire), pretty: true);
        return Pdf::loadView('pdf.table', ['title' => StockListReport::TITLES[$class], 'head' => $head, 'rows' => $rows])->setPaper('a4', 'landscape');
    }
}
