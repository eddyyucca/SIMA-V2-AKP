<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** Pengaturan aplikasi (key-value), perubahan tercatat di audit log. */
class Setting extends Model
{
    use Audited;

    protected $guarded = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        $all = Cache::rememberForever('app_settings', fn () => static::query()->pluck('value', 'key')->all());
        return $all[$key] ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('app_settings');
    }
}
