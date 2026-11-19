<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Nomor BAST yang sudah dicetak pada formulir dan menunggu dipakai oleh serah terima yang dicatat (lihat AssetService::reserveBastNumber). */
class BastNumberReservation extends Model
{
    protected $guarded = [];
    protected $casts = ['used_at' => 'datetime'];

    public function asset() { return $this->belongsTo(Asset::class)->withTrashed(); }
}
