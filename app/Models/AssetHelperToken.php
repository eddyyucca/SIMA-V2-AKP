<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Token khusus SIMA Agent Helper (program di sesi pengguna). Cakupannya HANYA pengumuman dan chat untuk satu aset:
 * tidak bisa dipakai untuk heartbeat, perintah, atau update. Token acak 40 karakter berumur 48 jam, dirotasi otomatis
 * lewat jawaban heartbeat, dan hanya hash SHA-256-nya yang disimpan (cukup untuk token acak berentropi tinggi, dan
 * memungkinkan pencarian langsung lewat indeks tanpa memeriksa semua token).
 */
class AssetHelperToken extends Model
{
    public const TTL_HOURS = 48;
    public const ROTATE_WHEN_LEFT_HOURS = 12;

    protected $fillable = ['asset_id', 'token_hash', 'expires_at'];

    protected $casts = ['expires_at' => 'datetime'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /** Membuat (atau mengganti) token untuk aset; mengembalikan [model, plaintext]. */
    public static function rotateFor(Asset $asset): array
    {
        $plaintext = Str::random(40);
        $token = self::updateOrCreate(
            ['asset_id' => $asset->id],
            ['token_hash' => self::hash($plaintext), 'expires_at' => now()->addHours(self::TTL_HOURS)]
        );

        return [$token, $plaintext];
    }

    public static function findValid(string $plaintext): ?self
    {
        if (strlen($plaintext) !== 40) {
            return null;
        }

        return self::where('token_hash', self::hash($plaintext))->where('expires_at', '>', now())->first();
    }

    public function needsRotation(): bool
    {
        return $this->expires_at === null || $this->expires_at->lt(now()->addHours(self::ROTATE_WHEN_LEFT_HOURS));
    }
}
