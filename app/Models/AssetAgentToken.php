<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Token SIMA Asset Agent: satu per aset, dibuat dari halaman detail aset. Token asli (plaintext) hanya ada
 * sesaat setelah dibuat (ditampilkan sekali ke admin); yang disimpan hanya hash-nya.
 */
class AssetAgentToken extends Model
{
    use HasFactory;

    protected $fillable = ['asset_id', 'token_hash', 'token_lookup', 'created_by', 'revoked_at'];

    protected $casts = ['revoked_at' => 'datetime'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Membuat token baru untuk sebuah aset. Mengembalikan [model, token_plaintext]. */
    public static function issueFor(Asset $asset, ?int $createdBy): array
    {
        $plaintext = Str::random(40);
        $token = static::create([
            'asset_id' => $asset->id,
            'token_hash' => Hash::make($plaintext),
            'token_lookup' => static::lookupHash($plaintext),
            'created_by' => $createdBy,
        ]);

        return [$token, $plaintext];
    }

    /** SHA-256 untuk pencarian langsung lewat indeks (aman untuk token acak berentropi tinggi). */
    public static function lookupHash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * Mencari token aktif (belum dicabut) yang cocok dengan $plaintext, atau null.
     *
     * Jalur cepat: cari lewat token_lookup (satu kueri berindeks), lalu periksa bcrypt untuk SATU token itu saja.
     * Jalur lama: token yang dibuat sebelum token_lookup ada (kolomnya kosong) diperiksa dengan bcrypt, dan bila cocok
     * langsung diisi token_lookup-nya, jadi setiap token lama hanya membayar biaya itu sekali.
     */
    public static function findActiveMatching(string $plaintext): ?self
    {
        if ($plaintext === '') {
            return null;
        }

        $fast = static::whereNull('revoked_at')->where('token_lookup', static::lookupHash($plaintext))->first();
        if ($fast) {
            return Hash::check($plaintext, $fast->token_hash) ? $fast : null;
        }

        $legacy = static::whereNull('revoked_at')->whereNull('token_lookup')->get()
            ->first(fn (self $candidate) => Hash::check($plaintext, $candidate->token_hash));
        $legacy?->forceFill(['token_lookup' => static::lookupHash($plaintext)])->save();

        return $legacy;
    }
}
