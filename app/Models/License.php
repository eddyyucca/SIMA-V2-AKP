<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class License extends Model
{
    use SoftDeletes, Audited;
    protected $guarded = [];
    protected $casts = ['license_key' => 'encrypted', 'purchase_date' => 'date', 'expires_at' => 'date'];

    public const TYPES = ['perpetual' => 'Perpetual', 'subscription' => 'Langganan', 'domain' => 'Domain', 'hosting' => 'Hosting', 'vps' => 'VPS'];

    public function vendor() { return $this->belongsTo(Vendor::class); }
    public function seatRows() { return $this->hasMany(LicenseSeat::class); }
    public function activeSeats() { return $this->hasMany(LicenseSeat::class)->whereNull('revoked_at'); }

    public function getSeatsUsedAttribute(): int { return $this->activeSeats()->count(); }
    public function getSeatsFreeAttribute(): int { return max(0, $this->seats - $this->seats_used); }
}
