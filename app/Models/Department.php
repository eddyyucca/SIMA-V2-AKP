<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use SoftDeletes, Audited;
    protected $guarded = [];
    protected $casts = ['hris_synced_at' => 'datetime'];

    public function employees() { return $this->hasMany(Employee::class); }

    /** Nama department urut abjad: satu-satunya sumber pilihan Department di formulir request (ITERA) dan halaman lain. */
    public static function names(): array
    {
        return static::usable()->orderBy('name')->pluck('name')->all();
    }

    /**
     * Department yang boleh dipilih di formulir. Setelah HRIS menyinkronkan department, hanya yang berasal dari HRIS yang tampil
     * (sisa input manual / impor SIMA tetap tersimpan demi riwayat, tetapi tidak bisa dipilih lagi).
     */
    public function scopeUsable($q)
    {
        return static::query()->whereNotNull('hris_synced_at')->exists() ? $q->whereNotNull('hris_synced_at') : $q;
    }

    /** id => nama untuk Select. */
    public static function options(): array
    {
        return static::usable()->orderBy('name')->pluck('name', 'id')->all();
    }
}
