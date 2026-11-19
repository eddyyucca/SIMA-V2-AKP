<?php

namespace App\Filament\Resources\StockReceipts\Pages;

use App\Filament\Resources\StockReceipts\StockReceiptResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageStockReceipts extends ManageRecords
{
    protected static string $resource = StockReceiptResource::class;

    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make('Semua')];
        foreach (StockReceiptResource::KINDS as $class => $label) {
            $tabs[$label] = Tab::make($label)->modifyQueryUsing(fn (Builder $query) => $query->where('stockable_type', $class));
        }
        return $tabs;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
