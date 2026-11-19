<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockOpname extends Model
{
    protected $guarded = [];
    protected $casts = ['started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function location() { return $this->belongsTo(Location::class); }
    public function officer() { return $this->belongsTo(User::class, 'officer_id'); }
    public function items() { return $this->hasMany(StockOpnameItem::class); }
}
