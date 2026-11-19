<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Dokumen yang diunggah untuk satu baris Riwayat Stok. Diarsipkan permanen: tidak ada penghapusan otomatis. */
class StockMovementFile extends Model
{
    protected $guarded = [];

    public function movement() { return $this->belongsTo(StockMovement::class, 'stock_movement_id'); }
    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }

    public function downloadName(): string
    {
        return $this->original_name ?: basename((string) $this->path);
    }
}
