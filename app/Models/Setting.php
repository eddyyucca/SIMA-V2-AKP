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

    private const MEMO = 'akp.settings';

    /**
     * Semua pengaturan dibaca SEKALI per permintaan (disimpan di container), bukan satu kali baca cache per pemanggilan:
     * dengan cache database, Dashboard memanggilnya ribuan kali (±0,5 detik). Container baru per permintaan/tes, jadi tidak basi.
     */
    private static function memoized(): array
    {
        $app = app();
        if (! $app->bound(self::MEMO)) {
            $app->instance(self::MEMO, Cache::rememberForever('app_settings', fn () => static::query()->pluck('value', 'key')->all()));
        }
        return $app->make(self::MEMO);
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::memoized()[$key] ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        self::forgetCache();
    }

    private static function forgetCache(): void
    {
        Cache::forget('app_settings');
        app()->forgetInstance(self::MEMO);
    }

    protected static function booted(): void
    {
        // perubahan lewat jalur apa pun (bukan hanya put()) membatalkan salinan
        static::saved(fn () => self::forgetCache());
        static::deleted(fn () => self::forgetCache());
    }
}
