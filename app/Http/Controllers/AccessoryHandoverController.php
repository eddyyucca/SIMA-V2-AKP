<?php

namespace App\Http\Controllers;

use App\Models\AccessoryHandover;
use App\Models\AccessoryHandoverFile;
use App\Support\AccessoryBastDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** BAST accessories: PDF dibuat saat dibuka (tidak disimpan), scan bertanda tangan diambil dari arsip privat. */
class AccessoryHandoverController extends Controller
{
    /** Tim dengan izin lihat inventaris, atau karyawan penerima/penyerah di BAST itu sendiri. */
    private function authorizeAccess(Request $request, AccessoryHandover $h): void
    {
        $u = $request->user();
        $own = $u->employee && in_array((int) $u->employee->id, [(int) $h->employee_id, (int) $h->pic_id], true);
        abort_unless($u->can('view inventory') || $own, 403);
    }

    public function view(Request $request, AccessoryHandover $handover)
    {
        $this->authorizeAccess($request, $handover);
        return AccessoryBastDocument::pdf($handover)->stream($handover->downloadName());
    }

    public function download(Request $request, AccessoryHandover $handover)
    {
        $this->authorizeAccess($request, $handover);
        return AccessoryBastDocument::pdf($handover)->download($handover->downloadName());
    }

    private function filePath(Request $request, AccessoryHandoverFile $file): string
    {
        $this->authorizeAccess($request, $file->handover);
        abort_unless(Storage::disk('local')->exists($file->path), 404);
        return $file->path;
    }

    private function mime(AccessoryHandoverFile $file): string
    {
        return match (strtolower(pathinfo($file->path, PATHINFO_EXTENSION))) { 'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', default => 'application/pdf' };
    }

    public function file(Request $request, AccessoryHandoverFile $file)
    {
        return Storage::disk('local')->response($this->filePath($request, $file), $file->downloadName(), ['Content-Type' => $this->mime($file)], 'inline');
    }

    public function fileDownload(Request $request, AccessoryHandoverFile $file)
    {
        return Storage::disk('local')->download($this->filePath($request, $file), $file->downloadName(), ['Content-Type' => $this->mime($file)]);
    }
}
