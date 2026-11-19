<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Foto harian pencapaian rollout agent (jumlah laptop, terpasang, online) untuk grafik tren di dashboard. */
class AgentRolloutSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = ['snapshot_date', 'laptops_total', 'installed', 'online'];

    protected $casts = ['snapshot_date' => 'date'];
}
