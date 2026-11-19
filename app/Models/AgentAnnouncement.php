<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pengumuman/kampanye dari IT yang ditampilkan SIMA Agent Helper di laptop sasaran. Dibuat dan dikelola hanya oleh
 * admin (gate "admin"). Teks selalu ditampilkan sebagai teks biasa (bukan HTML) di web maupun di laptop.
 */
class AgentAnnouncement extends Model
{
    public const SEVERITIES = ['info', 'important', 'urgent'];
    public const TARGET_MODES = ['all', 'selected'];
    public const TITLE_MAX = 150;
    public const BODY_MAX = 2000;

    protected $fillable = [
        'title', 'body', 'severity', 'require_ack', 'target_mode', 'starts_at', 'ends_at', 'ended_at', 'created_by',
    ];

    protected $casts = [
        'require_ack' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(AgentAnnouncementDelivery::class, 'announcement_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(AgentAnnouncementTarget::class, 'announcement_id');
    }

    /** Sedang berlaku: sudah mulai, belum berakhir, belum dihentikan. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('ended_at')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /** 'scheduled' | 'live' | 'finished' | 'stopped' */
    public function state(): string
    {
        if ($this->ended_at) {
            return 'stopped';
        }
        if ($this->ends_at && $this->ends_at->isPast()) {
            return 'finished';
        }
        if ($this->starts_at && $this->starts_at->isFuture()) {
            return 'scheduled';
        }

        return 'live';
    }
}
