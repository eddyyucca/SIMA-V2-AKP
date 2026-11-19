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

    public function newDamageReport(Repair $r): void
    {
        $r->loadMissing('asset');
        $this->notify(['Staff IT'], 'Laporan kerusakan baru', ($r->asset?->asset_tag ?? '') . ': ' . str($r->issue)->limit(80));
    }
}
