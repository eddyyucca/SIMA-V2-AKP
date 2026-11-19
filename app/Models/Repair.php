<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;

class Repair extends Model
{
    use Audited;
    protected $guarded = [];
    protected $casts = ['reported_at' => 'datetime', 'finished_at' => 'datetime', 'warranty_claim' => 'boolean'];

    public function asset() { return $this->belongsTo(Asset::class)->withTrashed(); }
    public function reporter() { return $this->belongsTo(Employee::class, 'reported_by'); }
}
