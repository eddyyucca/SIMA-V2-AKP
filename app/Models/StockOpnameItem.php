<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockOpnameItem extends Model
{
    protected $guarded = [];
    protected $casts = ['scanned_at' => 'datetime'];

    public const RESULTS = ['found' => 'Ditemukan', 'wrong_location' => 'Lokasi Berbeda', 'missing' => 'Tidak Ditemukan'];

    public function opname() { return $this->belongsTo(StockOpname::class, 'stock_opname_id'); }
    public function asset() { return $this->belongsTo(Asset::class)->withTrashed(); }
}
