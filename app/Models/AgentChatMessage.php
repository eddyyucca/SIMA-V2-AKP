<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu pesan dalam sesi chat. sender 'it' (admin, user_id terisi) atau 'user' (pengguna laptop). */
class AgentChatMessage extends Model
{
    public $timestamps = false;

    protected $fillable = ['chat_session_id', 'sender', 'user_id', 'body', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(AgentChatSession::class, 'chat_session_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
