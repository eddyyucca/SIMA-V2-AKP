<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Kode lama sebuah aset (sebelum diganti), supaya label yang sudah tercetak tetap bisa discan. */
class AssetTagAlias extends Model
{
    protected $guarded = [];

    public function asset() { return $this->belongsTo(Asset::class)->withTrashed(); }
}
