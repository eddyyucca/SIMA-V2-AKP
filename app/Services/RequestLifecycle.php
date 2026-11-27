<?php

namespace App\Services;

use App\Models\AccessoryHandover;
use App\Models\ITRequest;
use App\Notifications\RequestStatusUpdated;
use Filament\Notifications\Notification;

/**
 * Menyambungkan pemrosesan di IT Asset (BAST accessories, peminjaman aset, pengembalian) dengan status request ITERA, supaya
 * status maju sendiri dan pemohon dikabari: serah terima pertama -> In Progress, semua barang tertaut sudah diserahkan -> Completed,
 * aset dipinjamkan -> In Progress, aset kembali -> Completed. Status Completed final; yang Pending/Rejected tidak disentuh.
 */
class RequestLifecycle
{
    /** Request baru (atau dikirim ulang) siap ditinjau Tim IT: push browser, Telegram, dan notifikasi dalam aplikasi. */
    public function notifyIt(ITRequest $item): void
    {
        \App\Models\User::requisitionAdmins()->get()->each(fn ($it) => $it->notify(new \App\Notifications\NewRequestSubmitted($item)));
        TelegramNotifier::notifyNewRequest($item);
        app(NotificationService::class)->notify(['Admin', 'Staff IT'], 'Request baru menunggu peninjauan', ($item->requester_name ?: 'Karyawan') . ' mengajukan ' . $this->typeLabel($item) . ': ' . ($item->title ?: '-'), 'info');
    }

    public function typeLabel(ITRequest $item): string
    {
        return ['ITR' => 'IT Request', 'PEMINJAMAN_ASET' => 'Peminjaman Aset', 'TICKET_SISTEM' => 'Ticket Sistem', 'BAK' => 'BAK', 'WO' => 'Work Order'][$item->type] ?? (string) $item->type;
    }

    /** BAST serah terima dibuat dari request ITR. */
    public function handedOver(ITRequest $r, AccessoryHandover $h): void
    {
        foreach ($h->items as $it) {
            $kind = array_search($it->stockable_type, \App\Models\AccessoryHandoverItem::KINDS, true);
            if ($kind === false) continue;
            $r->links()->where('kind', $kind)->where('item_id', $it->stockable_id)->whereNull('processed_at')->update(['processed_at' => now()]);
        }
        $links = $r->links()->get();
        $allDone = $links->isNotEmpty() && $links->whereNull('processed_at')->isEmpty();
        if ($allDone) {
            $this->move($r, 'Completed', 'Semua barang sudah diserahkan', 'BAST ' . $h->bast_number . ' dibuat. Barang dapat diambil / sudah diterima; lihat di Request Saya.');
        } else {
            $this->move($r, 'In Progress', 'Barang sedang diserahkan', 'BAST ' . $h->bast_number . ' dibuat untuk sebagian barang; sisanya masih diproses Tim IT.');
        }
    }

    /** Aset dipinjamkan lewat request Peminjaman Aset. */
    public function borrowed(ITRequest $r, int $count): void
    {
        $this->move($r, 'In Progress', 'Aset dipinjamkan', "$count aset sudah dipinjamkan kepada Anda. Kembalikan sebelum tanggal jatuh tempo.");
    }

    /** Aset dikembalikan: peminjaman selesai. */
    public function returned(ITRequest $r): void
    {
        $this->move($r, 'Completed', 'Peminjaman selesai', 'Aset sudah dikembalikan dan tercatat Tersedia. Terima kasih.');
    }

    /** Tiket perbaikan dibuat dari BAK / Ticket Sistem. */
    public function reported(ITRequest $r, int $count): void
    {
        $this->move($r, 'In Progress', 'Tiket perbaikan dibuat', "$count tiket perbaikan dibuat untuk perangkat Anda; Tim IT sedang menanganinya.");
    }

    /** Perbaikan selesai: request selesai bila semua tiket yang ditautkan sudah selesai. */
    public function repairFinished(\App\Models\Repair $repair): void
    {
        $link = \App\Models\RequestItemLink::where('repair_id', $repair->id)->first();
        if (! $link || ! ($r = $link->request)) return;
        $open = $r->links()->whereNotNull('repair_id')->whereHas('repair', fn ($q) => $q->where('status', '!=', 'done'))->exists();
        if ($open) return;
        $this->move($r, 'Completed', 'Perbaikan selesai', 'Perbaikan perangkat sudah selesai.');
    }

    /** Maju hanya dari Approved / In Progress; Completed tidak turun lagi. */
    private function move(ITRequest $r, string $to, string $title, string $body): void
    {
        $r = $r->fresh();
        if (! in_array($r->status, ['Approved', 'In Progress'], true)) return;
        if ($r->status !== $to) {
            $r->status = $to;
            if ($to === 'Completed' && $r->completed_at === null) $r->completed_at = now();
            $r->save();
        }
        $this->notifyOwner($r, $title, $body, $to === 'Completed' ? 'success' : 'info');
    }

    /** Kabari pemohon: push browser (bila aktif) + notifikasi dalam aplikasi. */
    public function notifyOwner(ITRequest $r, string $title, string $body, string $status = 'info'): void
    {
        if (! $r->user) return;
        $ref = $r->ticket_number ?: ($r->title ?: 'Request');
        $r->user->notify(new RequestStatusUpdated($r));
        Notification::make()->title($title)->body($ref . ' — ' . $body)->status($status)->sendToDatabase($r->user);
    }
}
