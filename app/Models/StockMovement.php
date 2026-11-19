<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $guarded = [];
    protected $casts = ['moved_at' => 'datetime'];

    public function stockable() { return $this->morphTo()->withTrashed(); }
    public function receipt() { return $this->hasOne(StockReceipt::class, 'stock_movement_id'); }
    public function employee() { return $this->belongsTo(Employee::class); }
    public function asset() { return $this->belongsTo(Asset::class); }
    public function location() { return $this->belongsTo(Location::class); }
    public function department() { return $this->belongsTo(Department::class); }
    public function files() { return $this->hasMany(StockMovementFile::class)->latest('id'); }
    /** Baris BAST yang menghasilkan pergerakan ini (jika ada): scan TTD BAST ikut jadi dokumen pergerakan. */
    public function handoverItem() { return $this->hasOne(AccessoryHandoverItem::class, 'stock_movement_id'); }

    /**
     * Semua dokumen untuk pergerakan ini: unggahan langsung + scan BAST bertanda tangan (bila dari BAST).
     * @return \Illuminate\Support\Collection<int,array{name:string,note:?string,by:?string,at:\Illuminate\Support\Carbon,source:string,view:string,download:string}>
     */
    public function documents(): \Illuminate\Support\Collection
    {
        $own = $this->files()->with('uploader')->get()->map(fn (StockMovementFile $f) => [
            'name' => $f->downloadName(), 'note' => $f->note, 'by' => $f->uploader?->name, 'at' => $f->created_at, 'source' => 'Dokumen',
            'view' => route('stock-movement-file.view', $f), 'download' => route('stock-movement-file.download', $f),
        ]);
        $h = $this->handoverItem?->handover;
        $bast = $h ? $h->files()->with('uploader')->get()->map(fn (AccessoryHandoverFile $f) => [
            'name' => $f->original_name ?: basename($f->path), 'note' => $f->note, 'by' => $f->uploader?->name, 'at' => $f->created_at, 'source' => 'BAST ' . $h->bast_number,
            'view' => route('accessory-bast.file', $f), 'download' => route('accessory-bast.file.download', $f),
        ]) : collect();
        return $own->concat($bast)->sortByDesc('at')->values();
    }

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
