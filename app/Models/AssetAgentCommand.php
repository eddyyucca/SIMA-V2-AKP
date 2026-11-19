<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Perintah untuk SIMA Asset Agent di satu laptop. Hanya jenis di TYPES yang dikenal; agent juga memegang daftar yang
 * sama dan menolak jenis lain, jadi server (atau siapa pun yang membobolnya) tidak bisa menyuruh agent menjalankan
 * kode bebas lewat saluran ini.
 */
class AssetAgentCommand extends Model
{
    public const TYPES = [
        // laporan dan diagnostik (hanya baca)
        'refresh', 'collect_log', 'diagnostics', 'network_diagnostics', 'event_errors', 'startup_items',
        // keamanan
        'defender_scan', 'defender_update',
        // komunikasi
        'notify_user',
        // pemeliharaan berdampak: restart terjadwal (jeda wajib) dan pembatalannya; HANYA admin, lihat AssetAgentCommandController
        'restart_computer', 'cancel_restart',
    ];

    /** Jenis yang berdampak pada pekerjaan pengguna: hanya admin (gate "admin"). */
    public const ADMIN_ONLY_TYPES = ['restart_computer', 'cancel_restart'];

    public const RESTART_MIN_MINUTES = 5;
    public const RESTART_MAX_MINUTES = 120;
    public const RESTART_MESSAGE_MAX = 150;

    public const STATUS_ACTIVE = ['pending', 'sent'];

    public const TTL_HOURS = 24;

    /** Restart yang menunggu laptop online jangan tertunda sampai besok (mengejutkan pengguna): kedaluwarsa dalam 2 jam. */
    public const RESTART_TTL_MINUTES = 120;

    public static function ttlMinutesFor(string $type): int
    {
        return $type === 'restart_computer' ? self::RESTART_TTL_MINUTES : self::TTL_HOURS * 60;
    }

    public const MAX_ACTIVE_PER_ASSET = 5;
    public const RESULT_MAX = 20000;
    public const MESSAGE_MAX = 200;

    public $timestamps = false;

    protected $fillable = [
        'asset_id', 'type', 'params', 'status', 'requested_by', 'requested_at', 'delivered_at', 'finished_at', 'expires_at', 'result',
    ];

    protected $casts = [
        'params' => 'array',
        'requested_at' => 'datetime',
        'delivered_at' => 'datetime',
        'finished_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** Belum selesai dan belum kedaluwarsa. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::STATUS_ACTIVE)->where('expires_at', '>', now());
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::STATUS_ACTIVE, true) && $this->expires_at?->isFuture();
    }
}
