<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use SoftDeletes, Audited;
    protected $guarded = [];

    /** Umur ekonomis bawaan (tahun) untuk kategori yang belum punya sendiri: dari Pengaturan, atau config. */
    public static function defaultLifeYears(): int
    {
        return max(1, (int) (Setting::get('depreciation_default_years') ?: config('depreciation.default_life_years')));
    }

    /** Umur ekonomis efektif kategori ini (miliknya sendiri, atau bawaan). */
    public function effectiveLifeYears(): int
    {
        return (int) ($this->useful_life_years ?: static::defaultLifeYears());
    }
}
