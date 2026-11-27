<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Barang di database IT Asset yang dicocokkan tim IT dengan sebuah request ITERA. */
class RequestItemLink extends Model
{
    protected $guarded = [];
    protected $casts = ['processed_at' => 'datetime', 'qty' => 'float'];

    public const KINDS = ['asset' => 'Aset', 'accessory' => 'Accessories', 'consumable' => 'Consumable', 'component' => 'Komponen'];
    public const CLASSES = ['asset' => Asset::class, 'accessory' => Accessory::class, 'consumable' => Consumable::class, 'component' => Component::class];

    public function request() { return $this->belongsTo(ITRequest::class, 'request_id'); }
    public function repair() { return $this->belongsTo(Repair::class); }
    public function assignment() { return $this->belongsTo(AssetAssignment::class, 'assignment_id'); }

    public function item(): ?Model
    {
        $c = self::CLASSES[$this->kind] ?? null;
        return $c ? $c::withTrashed()->find($this->item_id) : null;
    }

    public function label(): string
    {
        $i = $this->item();
        if (! $i) return '(barang sudah dihapus)';
        return $this->kind === 'asset' ? $i->asset_tag . ' — ' . trim(($i->brand ?? '') . ' ' . ($i->model ?? '')) : $i->name;
    }
}
