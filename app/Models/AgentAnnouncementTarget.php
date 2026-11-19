<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Perangkat sasaran pengumuman bermode "selected". */
class AgentAnnouncementTarget extends Model
{
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = null;

    protected $fillable = ['announcement_id', 'asset_id'];
}
