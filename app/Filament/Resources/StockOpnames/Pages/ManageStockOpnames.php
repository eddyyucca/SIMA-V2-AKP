<?php

namespace App\Filament\Resources\StockOpnames\Pages;

use App\Filament\Resources\StockOpnames\StockOpnameResource;
use App\Models\StockOpname;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageStockOpnames extends ManageRecords
{
    protected static string $resource = StockOpnameResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Sesi Opname Baru')->using(fn (array $data) => StockOpname::create([
                'location_id' => $data['location_id'], 'started_at' => now(), 'officer_id' => auth()->id(), 'status' => 'running',
            ])),
        ];
    }
}
