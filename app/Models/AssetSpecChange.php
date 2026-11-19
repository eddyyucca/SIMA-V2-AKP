<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu perubahan spesifikasi/identitas/OS/software perangkat yang terdeteksi dari laporan SIMA Asset Agent
 * (lihat App\Services\AssetSpecChangeRecorder). Nilai disimpan sudah berbentuk teks siap tampil.
 */
class AssetSpecChange extends Model
{
    public $timestamps = false;

    protected $fillable = ['asset_id', 'category', 'field', 'old_value', 'new_value', 'changed_at'];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
