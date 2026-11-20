<?php

namespace App\Services;

use App\Models\Repair;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

class NotificationService
{
    public function users(array $roles)
    {
        return User::role($roles)->get();
    }

    public function notify(array $roles, string $title, ?string $body = null, string $status = 'warning'): void
    {
        $users = $this->users($roles);
        if ($users->isEmpty()) return;
        Notification::make()->title($title)->body($body)->status($status)->sendToDatabase($users);
    }

    public function lowStock(Model $item): void
    {
        $left = $item instanceof \App\Models\Consumable ? $item->qty : $item->qty_available;
        $this->notify(['Staff IT'], "Stok menipis: {$item->name}", "Sisa {$left}, minimum {$item->min_qty}.");
    }

    public function bastIssued(\App\Models\AssetAssignment $a): void
    {
        $a->loadMissing(['to.user', 'from.user']);
        $user = ($a->to ?: $a->from)?->user;
        if ($user) {
            Notification::make()->title('BAST ' . $a->bast_number)
                ->body(\App\Models\AssetAssignment::TYPES[$a->type] . ' aset ' . ($a->asset?->asset_tag ?? ''))
                ->status('info')->sendToDatabase($user);
        }
    }

    /** Pengumuman agent diterbitkan: kabar ke lonceng Admin & Staff IT (siapa pun di tim bisa melihat apa yang sedang tayang di laptop). */
    public function announcementPublished(\App\Models\AgentAnnouncement $a, int $targets, ?\App\Models\User $by = null): void
    {
        $this->notify(['Admin', 'Staff IT'], 'Pengumuman agent diterbitkan: ' . str($a->title)->limit(60),
            trans_choice('{1} Sasaran: 1 perangkat.|[2,*] Sasaran: :count perangkat.', $targets, ['count' => $targets])
            . ($a->require_ack ? ' Wajib dikonfirmasi pengguna.' : '') . ($by ? ' Oleh ' . $by->name . '.' : ''), 'info');
    }

    public function newDamageReport(Repair $r): void
    {
        $r->loadMissing('asset');
        $this->notify(['Staff IT'], 'Laporan kerusakan baru', ($r->asset?->asset_tag ?? '') . ': ' . str($r->issue)->limit(80));
    }
}
