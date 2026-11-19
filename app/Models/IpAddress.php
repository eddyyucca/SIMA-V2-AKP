<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Satu baris daftar IP: perangkat, alamat, kredensial, lokasi. Password terenkripsi dan tidak pernah masuk audit log. */
class IpAddress extends Model
{
    use SoftDeletes, LogsActivity;

    protected $guarded = [];
    protected $casts = ['password' => 'encrypted', 'password_changed_at' => 'datetime'];
    protected $hidden = ['password'];

    public function location() { return $this->belongsTo(Location::class)->withTrashed(); }

    protected static function booted(): void
    {
        static::saving(function (self $m) {
            if ($m->isDirty('password')) $m->password_changed_at = now();
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['password', 'updated_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
