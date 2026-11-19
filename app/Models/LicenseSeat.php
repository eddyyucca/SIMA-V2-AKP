<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LicenseSeat extends Model
{
    protected $guarded = [];
    protected $casts = ['assigned_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function license() { return $this->belongsTo(License::class); }
    public function employee() { return $this->belongsTo(Employee::class); }
    public function asset() { return $this->belongsTo(Asset::class); }
}
