<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;

class Disposal extends Model
{
    use Audited;
    protected $guarded = [];
    protected $casts = ['approved_at' => 'datetime', 'data_wiped' => 'boolean'];

    public const METHODS = ['sell' => 'Jual', 'auction' => 'Lelang', 'donate' => 'Donasi', 'ewaste' => 'E-waste'];

    /** Nomor dokumen Berita Acara Penghapusan: BA/HPS/<tahun disetujui>/<id 4 digit>. Tetap sama untuk pengajuan yang sama. */
    public function documentNumber(): string
    {
        return 'BA/HPS/' . ($this->approved_at ?? $this->created_at ?? now())->format('Y') . '/' . str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    public function asset() { return $this->belongsTo(Asset::class)->withTrashed(); }
    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
}
