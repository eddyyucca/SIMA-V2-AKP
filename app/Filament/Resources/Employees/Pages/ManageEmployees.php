<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use App\Reports\TextExport;
use App\Reports\RowsImport;
use App\Services\EmployeeImportService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;

class ManageEmployees extends ManageRecords
{
    protected static string $resource = EmployeeResource::class;

    private function export(string $type, string $ext)
    {
        $svc = app(EmployeeImportService::class);
        return Excel::download(new TextExport(EmployeeImportService::HEAD, $svc->exportRows()), 'karyawan-' . now()->format('Ymd') . '.' . $ext, $type);
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('import')->label('Import dari Excel / CSV')->icon(Heroicon::OutlinedArrowUpTray)
                    ->visible(fn () => ! \App\Support\Hris::managed() && auth()->user()->can('create', Employee::class))
                    ->modalHeading('Import karyawan')
                    ->modalDescription('Data dicocokkan lewat NIK (atau email bila NIK kosong): yang sudah ada diperbarui, yang belum ada ditambahkan. Sel kosong tidak menimpa data lama. Baris bermasalah dilewati dan dilaporkan.')
                    ->modalSubmitActionLabel('Import')
                    ->schema([
                        FileUpload::make('file')->label('File Excel (.xlsx) atau CSV')->required()->disk('local')->directory('employee-imports')->visibility('private')
                            ->rule(\App\Support\SpreadsheetFile::extensionRule())
                            ->maxSize(5120)->helperText('Format .xlsx, .xls, atau .csv. Kolom: nik, nama, email, jabatan, department, telepon, status. Unduh template lewat menu ini.'),
                        Toggle::make('create_departments')->label('Buat department baru otomatis bila belum ada')->default(false),
                    ])
                    ->action(function (array $data) {
                        $path = is_array($data['file']) ? (string) reset($data['file']) : (string) $data['file'];
                        $disk = Storage::disk('local');
                        try {
                            abort_unless(str_starts_with($path, 'employee-imports/') && ! str_contains($path, '..') && $disk->exists($path), 422);
                            $reader = new RowsImport;
                            Excel::import($reader, $disk->path($path), null, \App\Support\SpreadsheetFile::readerType($disk->path($path)));
                            $res = app(EmployeeImportService::class)->import($reader->rows, (bool) ($data['create_departments'] ?? false));
                        } catch (\Throwable $e) {
                            report($e);
                            Notification::make()->danger()->title('File tidak bisa dibaca')->body('Pastikan file Excel/CSV dengan baris pertama berisi judul kolom. ' . mb_substr($e->getMessage(), 0, 140))->send();
                            return;
                        } finally {
                            $disk->delete($path);
                        }
                        $n = count($res['errors']);
                        $note = Notification::make()->title("{$res['created']} karyawan ditambahkan, {$res['updated']} diperbarui, {$n} baris ditolak")
                            ->{$n ? 'warning' : 'success'}();
                        if ($n) {
                            $show = array_slice($res['errors'], 0, 10);
                            $body = implode('<br>', array_map('e', $show)) . ($n > 10 ? '<br>… dan ' . ($n - 10) . ' lainnya.' : '');
                            $note->body(new HtmlString($body))->persistent();
                        }
                        $note->send();
                    }),
                Action::make('export_xlsx')->label('Export Excel (.xlsx)')->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(fn () => $this->export(ExcelType::XLSX, 'xlsx')),
                Action::make('export_csv')->label('Export CSV')->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(fn () => $this->export(ExcelType::CSV, 'csv')),
                Action::make('template')->label('Unduh template import (CSV)')->icon(Heroicon::OutlinedDocumentText)
                    ->visible(fn () => ! \App\Support\Hris::managed())
                    ->url(route('template.employees')),
            ])->label('Import / Export')->icon(Heroicon::OutlinedArrowsUpDown)->button()->color('gray'),
            Action::make('sync_hris')->label('Sinkron dari HRIS')->icon(Heroicon::OutlinedArrowPath)->color('primary')
                ->visible(fn () => \App\Support\Hris::managed() && auth()->user()->can('manage master'))
                ->requiresConfirmation()->modalHeading('Sinkron karyawan dari HRIS?')
                ->modalDescription(\App\Support\Hris::LOCKED_MESSAGE . ' Sinkron berjalan otomatis tiap malam; tombol ini untuk menjalankannya sekarang. Tidak ada data yang dihapus.')
                ->action(function () {
                    $exit = \Illuminate\Support\Facades\Artisan::call('hris:sync-employees', ['--apply' => true]);
                    $out = trim(\Illuminate\Support\Facades\Artisan::output());
                    Notification::make()->title($exit === 0 ? 'Sinkron HRIS selesai' : 'Sinkron HRIS gagal')->body(mb_substr($out, 0, 600))->{$exit === 0 ? 'success' : 'danger'}()->persistent()->send();
                }),
            CreateAction::make()->visible(fn () => ! \App\Support\Hris::managed())->using(fn (array $data, $action) => EmployeeResource::saveWithAccount(null, $data, $action)),
        ];
    }
}
