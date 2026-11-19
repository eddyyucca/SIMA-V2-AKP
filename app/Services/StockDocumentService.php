<?php

namespace App\Services;

use App\Models\AccessoryHandoverFile;
use App\Models\StockMovement;
use App\Models\StockMovementFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Dokumen untuk baris Riwayat Stok. Pergerakan yang berasal dari BAST memakai arsip BAST-nya (satu tempat, tidak ganda);
 * pergerakan lain (pembelian, koreksi, pemakaian) memakai arsip pergerakan sendiri. Setiap unggahan disimpan permanen.
 */
class StockDocumentService
{
    public const DIR = 'stock-movement-docs';

    /** @return StockMovementFile|AccessoryHandoverFile */
    public function attach(StockMovement $m, string $path, ?string $originalName = null, ?string $note = null)
    {
        $handover = $m->handoverItem?->handover;
        if ($handover) {
            // path dari unggahan form berada di folder stok; pindahkan ke folder arsip BAST agar sama dengan unggahan BAST biasa
            $disk = Storage::disk('local');
            $target = 'accessory-bast-signed/' . basename($path);
            if (str_starts_with($path, self::DIR . '/') && $disk->exists($path)) { $disk->move($path, $target); $path = $target; }
            return app(AccessoryHandoverService::class)->attachSigned($handover, $path, $originalName, $note);
        }

        $disk = Storage::disk('local');
        if (! str_starts_with($path, self::DIR . '/') || str_contains($path, '..') || ! $disk->exists($path)) {
            throw new InvalidArgumentException('File tidak ditemukan, unggah ulang.');
        }
        $head = (string) substr((string) $disk->get($path), 0, 8);
        if (! (str_starts_with($head, '%PDF') || str_starts_with($head, "\xFF\xD8\xFF") || str_starts_with($head, "\x89PNG"))) {
            $disk->delete($path);
            throw new InvalidArgumentException('File bukan PDF / JPG / PNG yang valid.');
        }
        return $m->files()->create([
            'path' => $path, 'original_name' => filled($originalName) ? mb_substr(basename($originalName), 0, 255) : null,
            'note' => filled($note) ? mb_substr(trim($note), 0, 250) : null, 'uploaded_by' => auth()->id(),
        ]);
    }
}
