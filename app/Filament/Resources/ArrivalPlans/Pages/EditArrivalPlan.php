<?php

namespace App\Filament\Resources\ArrivalPlans\Pages;

use App\Filament\Resources\ArrivalPlans\ArrivalPlanResource;
use Filament\Resources\Pages\EditRecord;

class EditArrivalPlan extends EditRecord
{
    protected static string $resource = ArrivalPlanResource::class;

    protected function getRedirectUrl(): ?string
    {
        return ArrivalPlanResource::getUrl('view', ['record' => $this->record]);
    }
}
