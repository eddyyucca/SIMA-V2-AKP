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

    public function asset() { return $this->belongsTo(Asset::class)->withTrashed(); }
    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
}
