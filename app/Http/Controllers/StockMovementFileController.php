<?php

namespace App\Http\Controllers;

use App\Models\StockMovementFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Dokumen Riwayat Stok diambil dari arsip privat; hanya tim dengan izin lihat inventaris. */
class StockMovementFileController extends Controller
{
    private function path(Request $request, StockMovementFile $file): string
    {
        abort_unless($request->user()->can('view inventory'), 403);
        abort_unless(Storage::disk('local')->exists($file->path), 404);
        return $file->path;
    }

    private function mime(StockMovementFile $file): string
    {
        return match (strtolower(pathinfo($file->path, PATHINFO_EXTENSION))) { 'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', default => 'application/pdf' };
    }

    public function view(Request $request, StockMovementFile $file)
    {
        return Storage::disk('local')->response($this->path($request, $file), $file->downloadName(), ['Content-Type' => $this->mime($file)], 'inline');
    }

    public function download(Request $request, StockMovementFile $file)
    {
        return Storage::disk('local')->download($this->path($request, $file), $file->downloadName(), ['Content-Type' => $this->mime($file)]);
    }
}
