<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use SoftDeletes, Audited;
    protected $guarded = [];

    /** Nama department urut abjad: satu-satunya sumber pilihan Department di formulir request (ITERA) dan halaman lain. */
    public static function names(): array
    {
        return static::query()->orderBy('name')->pluck('name')->all();
    }
}
