<?php

namespace App\Filament\Resources\Repairs\Pages;

use App\Filament\Resources\Repairs\RepairResource;
use App\Models\Asset;
use App\Models\Employee;
use App\Services\AssetService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageRepairs extends ManageRecords
{
    protected static string $resource = RepairResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Catat Kerusakan')->using(function (array $data, \Filament\Actions\Action $action) {
                try {
                    return app(AssetService::class)->reportDamage(
                        Asset::findOrFail($data['asset_id']), ! empty($data['reported_by']) ? Employee::find($data['reported_by']) : null, $data['issue']);
                } catch (\InvalidArgumentException $e) {
                    \Filament\Notifications\Notification::make()->title('Gagal mencatat kerusakan')->body($e->getMessage())->danger()->send();
                    $action->halt();
                }
            }),
        ];
    }
}
