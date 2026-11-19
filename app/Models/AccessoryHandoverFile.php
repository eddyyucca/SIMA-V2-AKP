<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Scan BAST accessories yang sudah ditandatangani. Diarsipkan permanen: tidak ada penghapusan otomatis. */
class AccessoryHandoverFile extends Model
{
    protected $guarded = [];

    public function handover() { return $this->belongsTo(AccessoryHandover::class, 'accessory_handover_id'); }
    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }

    public function downloadName(): string
    {
        $ext = pathinfo((string) $this->path, PATHINFO_EXTENSION) ?: 'pdf';
        return 'BAST-TTD-' . str_replace('/', '-', (string) $this->handover?->bast_number) . '-' . $this->id . '.' . $ext;
    }
}
