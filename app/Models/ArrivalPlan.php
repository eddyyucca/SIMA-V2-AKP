<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;

class ArrivalPlan extends Model
{
    use Audited;

    protected $guarded = [];
    protected $casts = ['arrival_date' => 'date', 'handed_over_at' => 'datetime'];

    public const STATUSES = ['planned' => 'Direncanakan', 'prepared' => 'Disiapkan', 'ready' => 'Siap', 'handed_over' => 'Diserahkan', 'cancelled' => 'Dibatalkan'];
    public const ITEM_STATUSES = ['planned' => 'Direncanakan', 'prepared' => 'Disiapkan', 'ready' => 'Siap', 'handed_over' => 'Diserahkan', 'cancelled' => 'Dibatalkan'];
    /** Rencana yang masih berjalan; barang berstatus ini sedang dicadangkan. */
    public const ACTIVE = ['planned', 'prepared', 'ready'];

    public function items() { return $this->hasMany(ArrivalPlanItem::class); }
    public function department() { return $this->belongsTo(Department::class); }
    public function location() { return $this->belongsTo(Location::class); }
    public function pic() { return $this->belongsTo(User::class, 'pic_id'); }
    public function employee() { return $this->belongsTo(Employee::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    public function scopeActive($q) { return $q->whereIn('status', self::ACTIVE); }

    public function isActive(): bool { return in_array($this->status, self::ACTIVE, true); }

    /** Hari menuju tanggal tiba (negatif = sudah lewat). */
    public function getDaysLeftAttribute(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->arrival_date, false);
    }

    /** Kemajuan penyiapan: barang siap dari seluruh barang (tidak menghitung yang dibatalkan). */
    public function progress(): array
    {
        $this->loadMissing('items');
        $items = $this->items->whereIn('status', ['planned', 'prepared', 'ready', 'handed_over']);
        return ['total' => $items->count(), 'ready' => $items->whereIn('status', ['ready', 'handed_over'])->count()];
    }
}
