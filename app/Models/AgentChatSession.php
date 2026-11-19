<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sesi chat antara IT dan pengguna satu laptop. ATURAN: sesi hanya DIBUKA dan DITUTUP oleh admin IT (lewat web);
 * pengguna tidak punya cara menutup atau memulai sesi. Sesi yang tidak ada aktivitas selama IDLE_MINUTES ditutup
 * otomatis supaya jendela chat tidak tertinggal selamanya di laptop.
 */
class AgentChatSession extends Model
{
    public const IDLE_MINUTES = 30;
    public const MESSAGE_MAX = 1000;

    public $timestamps = false;

    protected $fillable = ['asset_id', 'started_by', 'closed_by', 'status', 'started_at', 'last_activity_at', 'closed_at', 'close_reason'];

    protected $casts = [
        'started_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AgentChatMessage::class, 'chat_session_id')->orderBy('id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /** Menutup sesi yang terlalu lama tanpa aktivitas; mengembalikan true bila ditutup. */
    public function closeIfIdle(): bool
    {
        if ($this->isOpen() && ($this->last_activity_at ?? $this->started_at)->lt(now()->subMinutes(self::IDLE_MINUTES))) {
            $this->update(['status' => 'closed', 'closed_at' => now(), 'close_reason' => 'idle']);

            return true;
        }

        return false;
    }
}
