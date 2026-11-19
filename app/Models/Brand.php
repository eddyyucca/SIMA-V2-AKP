<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Brand extends Model
{
    use SoftDeletes, Audited;
    protected $guarded = [];

    protected static function booted(): void
    {
        // ganti nama merek → nama pada aset ikut berubah
        static::updated(function (Brand $b) {
            if ($b->wasChanged('name')) {
                Asset::withTrashed()->where('brand_id', $b->id)->update(['brand' => $b->name]);
            }
        });
    }

    public function models() { return $this->hasMany(AssetModel::class); }
    public function assets() { return $this->hasMany(Asset::class); }

    /** Cari merek tanpa membedakan huruf besar/kecil; buat jika belum ada. */
    public static function resolve(string $name): self
    {
        $name = trim($name);
        $b = static::withTrashed()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first() ?? static::create(['name' => $name]);
        if ($b->trashed()) $b->restore();
        return $b;
    }
}
