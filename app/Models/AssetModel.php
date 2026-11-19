<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetModel extends Model
{
    use SoftDeletes, Audited;
    protected $table = 'asset_models';
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updated(function (AssetModel $m) {
            if ($m->wasChanged('name')) {
                Asset::withTrashed()->where('asset_model_id', $m->id)->update(['model' => $m->name]);
            }
            if ($m->wasChanged('brand_id')) {
                Asset::withTrashed()->where('asset_model_id', $m->id)->update(['brand_id' => $m->brand_id, 'brand' => Brand::withTrashed()->find($m->brand_id)?->name]);
            }
        });
    }

    public function brand() { return $this->belongsTo(Brand::class)->withTrashed(); }
    public function category() { return $this->belongsTo(Category::class); }
    public function assets() { return $this->hasMany(Asset::class); }

    /** Cari model pada merek tertentu (tanpa membedakan huruf besar/kecil); buat jika belum ada. */
    public static function resolve(int $brandId, string $name, ?int $categoryId = null): self
    {
        $name = trim($name);
        $m = static::withTrashed()->where('brand_id', $brandId)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? static::create(['brand_id' => $brandId, 'name' => $name, 'category_id' => $categoryId]);
        if ($m->trashed()) $m->restore();
        return $m;
    }
}
