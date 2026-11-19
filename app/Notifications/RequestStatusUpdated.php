<?php

namespace App\Notifications;

use App\Models\ITRequest;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class RequestStatusUpdated extends Notification
{
    public function __construct(private ITRequest $request)
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
        $typeLabel = match ($this->request->type) {
            'TICKET_SISTEM' => 'Ticket Sistem',
            default => $this->request->type,
        };

        return (new WebPushMessage())
            ->title('Status Request Diperbarui: ' . $typeLabel)
            ->icon('/images/logo-akp.png')
            ->body(($this->request->title ?? 'Request Anda') . ' — Status: ' . $this->request->status)
            ->data(['url' => route('requests.create')])
            ->tag('status-update-' . $this->request->id)
            ->action('Lihat Detail', 'view');
    }
}
