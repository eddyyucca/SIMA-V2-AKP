<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PDF BAST bertanda tangan yang diunggah manual. Diarsipkan permanen: tidak ada penghapusan otomatis. */
class AssetBastFile extends Model
{
    protected $guarded = [];

    public function asset() { return $this->belongsTo(Asset::class)->withTrashed(); }
    public function assignment() { return $this->belongsTo(AssetAssignment::class, 'asset_assignment_id'); }
    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }

    public function downloadName(): string
    {
        $tag = str_replace('/', '-', (string) $this->asset?->asset_tag);
        return 'BAST-TTD-' . $tag . '-' . $this->created_at->format('Ymd') . '-' . $this->id . '.pdf';
    }
}
