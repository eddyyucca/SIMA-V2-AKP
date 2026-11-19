<?php

namespace App\Notifications;

use App\Models\ITRequest;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class NewRequestSubmitted extends Notification
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
            ->title('Request Baru: ' . $typeLabel)
            ->icon('/images/logo-akp.png')
            ->body(($this->request->requester_name ?? 'N/A') . ' — ' . $this->request->title)
            ->data(['url' => route('dashboard')])
            ->tag('new-request-' . $this->request->id)
            ->action('Lihat Dashboard', 'view');
    }
}
