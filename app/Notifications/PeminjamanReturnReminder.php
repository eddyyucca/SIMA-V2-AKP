<?php

namespace App\Notifications;

use App\Models\ITRequest;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

// Reminder ke tim IT untuk Peminjaman Aset, dikirim oleh App\Console\Commands\SendPeminjamanReminders
// (dijadwalkan tiap 30 menit, lihat routes/console.php) dalam 3 tahap berbeda ($kind):
// - "upcoming": H-30 menit sebelum Tanggal Kembali (sekali)
// - "due": pas Tanggal Kembali tercapai (sekali)
// - "overdue": berulang tiap 6 jam selama belum ditandai "Sudah Kembali"
class PeminjamanReturnReminder extends Notification
{
    public function __construct(private ITRequest $request, private string $kind = 'overdue')
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // tanpa kunci VAPID (belum diatur) tidak ada push; request tetap tersimpan normal
        return config('webpush.vapid.public_key') && config('webpush.vapid.private_key') ? [WebPushChannel::class] : [];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        [$title, $body] = match ($this->kind) {
            'upcoming' => [
                'Peminjaman Akan Jatuh Tempo: ' . ($this->request->ticket_number ?? $this->request->title),
                $this->request->requester_name . ' — peminjaman aset akan jatuh tempo dalam '
                    . ITRequest::formatMinutesAsDuration((int) now()->diffInMinutes($this->request->loan_end_date)) . '.',
            ],
            'due' => [
                'Reminder Peminjaman Aset: ' . ($this->request->ticket_number ?? $this->request->title),
                $this->request->requester_name . ' — baru saja jatuh tempo pengembalian aset.',
            ],
            default => [
                'Reminder Peminjaman Aset: ' . ($this->request->ticket_number ?? $this->request->title),
                $this->request->requester_name . ' — terlambat '
                    . ITRequest::formatMinutesAsDuration((int) $this->request->loan_end_date->diffInMinutes(now())) . ' mengembalikan aset.',
            ],
        };

        return (new WebPushMessage())
            ->title($title)
            ->icon('/images/logo-akp.png')
            ->body($body)
            ->data(['url' => route('peminjaman.index')])
            ->tag('peminjaman-reminder-' . $this->request->id)
            ->action('Lihat Peminjaman', 'view');
    }
}
