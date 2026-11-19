<?php

namespace App\Filament\Resources\AgentAnnouncements\Pages;

use App\Filament\Resources\AgentAnnouncements\AgentAnnouncementResource;
use App\Services\AgentAdminService;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageAgentAnnouncements extends ManageRecords
{
    protected static string $resource = AgentAnnouncementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Buat Pengumuman')->using(function (array $data) {
                try {
                    return app(AgentAdminService::class)->createAnnouncement($data, auth()->user());
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $this->halt();
                }
            })->successNotificationTitle('Pengumuman dibuat'),
        ];
    }
}
