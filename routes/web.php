<?php

use App\Http\Controllers\ReportController;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// alamat pendek pada QR label (LABEL_QR_MODE=url): hanya mengalihkan ke halaman Scan; login dan izin diperiksa di sana
Route::get('/s/{code}', fn (string $code) => redirect(\App\Filament\Pages\ScanPage::getUrl(['kode' => $code])))->where('code', '[^/]+')->name('scan.short');

require __DIR__ . '/requisition.php';

Route::middleware([Authenticate::class])->group(function () {
    Route::get('/bast/{assignment}', [ReportController::class, 'bast'])->name('bast.download');
    Route::get('/bast/{assignment}/lihat', [ReportController::class, 'bastView'])->name('bast.view');
    Route::get('/bast-file/{file}', [ReportController::class, 'bastFile'])->name('bast.file');
    Route::get('/bast-file/{file}/unduh', [ReportController::class, 'bastFileDownload'])->name('bast.file.download');
    Route::get('/kontrak-sewa/{contract}', [ReportController::class, 'contractView'])->name('contract.view');
    Route::get('/kontrak-sewa/{contract}/unduh', [ReportController::class, 'contractDownload'])->name('contract.download');
    Route::get('/accessory-bast/{handover}', [\App\Http\Controllers\AccessoryHandoverController::class, 'view'])->name('accessory-bast.view');
    Route::get('/accessory-bast/{handover}/unduh', [\App\Http\Controllers\AccessoryHandoverController::class, 'download'])->name('accessory-bast.download');
    Route::get('/accessory-bast-file/{file}', [\App\Http\Controllers\AccessoryHandoverController::class, 'file'])->name('accessory-bast.file');
    Route::get('/accessory-bast-file/{file}/unduh', [\App\Http\Controllers\AccessoryHandoverController::class, 'fileDownload'])->name('accessory-bast.file.download');
    Route::get('/stok-dokumen/{file}', [\App\Http\Controllers\StockMovementFileController::class, 'view'])->name('stock-movement-file.view');
    Route::get('/stok-dokumen/{file}/unduh', [\App\Http\Controllers\StockMovementFileController::class, 'download'])->name('stock-movement-file.download');
    Route::get('/disposal/{disposal}/ba/lihat', [ReportController::class, 'disposalView'])->name('disposal.ba.view');
    Route::get('/disposal/{disposal}/ba', [ReportController::class, 'disposal'])->name('disposal.ba');
    Route::get('/reports/{key}.{format}', [ReportController::class, 'download'])->where('format', 'xlsx|pdf')->name('reports.download');
    Route::get('/labels/{kind}', [\App\Http\Controllers\LabelController::class, 'print'])->whereIn('kind', ['asset', 'accessory', 'component'])->name('labels.print');
    Route::get('/cetak/{file}', [ReportController::class, 'printFile'])->name('print.file');
    Route::get('/templates/karyawan.csv', [ReportController::class, 'templateEmployees'])->name('template.employees');
    Route::get('/templates/aset.csv', [ReportController::class, 'template'])->name('template.assets');
});

// Alamat lama Riwayat Stok gabungan: tiap jenis barang kini punya riwayat sendiri (di menu masing-masing).
Route::get('/admin/stock-movements', function (\Illuminate\Http\Request $request) {
    $map = [
        \App\Models\Consumable::class => \App\Filament\Pages\StockHistory\ConsumableStockHistory::class,
        \App\Models\Component::class => \App\Filament\Pages\StockHistory\ComponentStockHistory::class,
        \App\Models\Accessory::class => \App\Filament\Pages\StockHistory\AccessoryStockHistory::class,
    ];
    $page = $map[data_get($request->query('filters'), 'stockable_type.value')] ?? \App\Filament\Pages\StockHistory\AccessoryStockHistory::class;
    return redirect($page::getUrl());
});
