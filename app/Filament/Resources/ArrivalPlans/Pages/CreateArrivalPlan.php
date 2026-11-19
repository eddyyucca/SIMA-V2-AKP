<?php

namespace App\Filament\Resources\ArrivalPlans\Pages;

use App\Filament\Resources\ArrivalPlans\ArrivalPlanResource;
use App\Services\ArrivalPlanService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateArrivalPlan extends CreateRecord
{
    protected static string $resource = ArrivalPlanResource::class;
    protected static ?string $title = 'Buat Rencana Kedatangan';

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(ArrivalPlanService::class)->create($data);
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title('Gagal menyimpan rencana kedatangan')->body($e->getMessage())->danger()->send();
            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return ArrivalPlanResource::getUrl('view', ['record' => $this->record]);
    }
}
