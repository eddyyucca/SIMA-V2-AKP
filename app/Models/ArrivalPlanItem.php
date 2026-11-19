<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;

class ArrivalPlanItem extends Model
{
    use Audited;

    protected $guarded = [];
    protected $casts = ['ready_at' => 'datetime'];

    /** Status barang yang sedang dicadangkan. */
    public const RESERVING = ['planned', 'prepared', 'ready'];

    public function plan() { return $this->belongsTo(ArrivalPlan::class, 'arrival_plan_id'); }
    public function asset() { return $this->belongsTo(Asset::class)->withTrashed(); }
    public function accessory() { return $this->belongsTo(Accessory::class)->withTrashed(); }
    public function assignment() { return $this->belongsTo(AssetAssignment::class, 'asset_assignment_id'); }

    public function isReserving(): bool { return in_array($this->status, self::RESERVING, true); }

    public function label(): string
    {
        return $this->type === 'asset'
            ? trim(($this->asset?->asset_tag ?? '?') . ' — ' . ($this->asset?->brand ?? '') . ' ' . ($this->asset?->model ?? ''))
            : ($this->accessory?->name ?? '?') . ' × ' . $this->qty;
    }

    /** Kendala yang membuat barang tidak bisa diserahkan (null = aman). */
    public function problem(): ?string
    {
        if (! $this->isReserving()) return null;
        if ($this->type === 'asset') {
            $a = $this->asset;
            if (! $a || $a->trashed()) return 'Aset sudah dihapus';
            if ($a->status !== 'available' || $a->employee_id) return 'Aset tidak tersedia (' . (Asset::STATUSES[$a->status] ?? $a->status) . ')';
            return null;
        }
        $c = $this->accessory;
        if (! $c || $c->trashed()) return 'Accessory sudah dihapus';
        return $c->qty_available < $this->qty ? "Stok tinggal {$c->qty_available}" : null;
    }
}
