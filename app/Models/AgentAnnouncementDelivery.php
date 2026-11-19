<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Catatan penerimaan pengumuman per perangkat: kapan ditampilkan dan kapan dikonfirmasi pengguna. */
class AgentAnnouncementDelivery extends Model
{
    protected $fillable = ['announcement_id', 'asset_id', 'shown_at', 'acked_at'];

    protected $casts = ['shown_at' => 'datetime', 'acked_at' => 'datetime'];

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(AgentAnnouncement::class, 'announcement_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
