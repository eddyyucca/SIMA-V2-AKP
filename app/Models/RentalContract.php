<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Berkas kontrak sewa (PDF). Satu baris = satu periode (kontrak awal atau pembaruan tahunan). */
class RentalContract extends Model
{
    protected $guarded = [];
    protected $casts = ['start_date' => 'date', 'end_date' => 'date'];

    /** Saran jenis kontrak (boleh diketik bebas). */
    public const LABEL_SUGGESTIONS = ['Kontrak Utama', 'Addendum', 'SLA', 'Berita Acara', 'Kontrak 1', 'Kontrak 2'];
    public const KINDS = ['initial' => 'Kontrak awal', 'renewal' => 'Pembaruan tahunan'];

    /** Label dirapikan: spasi ganda dibuang, dipotong 60 karakter. */
    public static function cleanLabel(?string $v): string
    {
        $v = trim((string) preg_replace('/\s+/u', ' ', (string) $v));
        return mb_substr($v, 0, 60);
    }

    /** Kunci pengelompokan jenis (tidak peka huruf besar/kecil). */
    public function groupKey(): string { return mb_strtolower($this->label); }

    public function asset() { return $this->belongsTo(Asset::class)->withTrashed(); }
    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }

    /** Masa Aktif selama tanggal berakhir belum lewat; selain itu Expired. */
    public function isActive(): bool { return $this->end_date->gte(now()->startOfDay()); }
    public function statusLabel(): string { return $this->isActive() ? 'Masa Aktif' : 'Expired'; }

    /** Nama file saat diunggah (dibersihkan); berkas lama tanpa nama asli memakai nama bawaan. */
    public function downloadName(): string
    {
        $n = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\"]+/u', '', basename((string) $this->original_name)));
        if ($n !== '') return str_ends_with(strtolower($n), '.pdf') ? $n : $n . '.pdf';
        $tag = str_replace('/', '-', (string) $this->asset?->asset_tag);
        return 'Kontrak-' . preg_replace('/[^A-Za-z0-9]+/', '', $this->label) . '-' . $tag . '-' . $this->start_date->format('Ymd') . '.pdf';
    }
}
