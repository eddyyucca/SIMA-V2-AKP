<?php

namespace App\Filament\Resources\Disposals\Pages;

use App\Filament\Resources\Disposals\DisposalResource;
use App\Services\DisposalService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDisposals extends ManageRecords
{
    protected static string $resource = DisposalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Ajukan Disposal')->using(function (array $data, \Filament\Actions\Action $action) {
                try {
                    return app(DisposalService::class)->request($data['asset_id'], $data['reason'], (bool) $data['data_wiped']);
                } catch (\InvalidArgumentException $e) {
                    \Filament\Notifications\Notification::make()->title('Gagal mengajukan disposal')->body($e->getMessage())->danger()->send();
                    $action->halt();
                }
            }),
        ];
    }
}
