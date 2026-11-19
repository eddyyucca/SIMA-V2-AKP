<?php

namespace App\Filament\Resources\RentalAssets\Pages;

use App\Filament\Resources\RentalAssets\RentalAssetResource;
use App\Models\Asset;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListRentalAssets extends ListRecords
{
    protected static string $resource = RentalAssetResource::class;
    protected static ?string $title = 'Aset Sewa (Rental)';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Tambah Aset Sewa')];
    }

    public function getSubheading(): string|Htmlable|null
    {
        $q = Asset::where('ownership', 'rental')->where('status', '!=', 'returned');
        $soon = (clone $q)->whereDate('rental_end', '<=', now()->addDays(30)->toDateString())->count();
        return (clone $q)->count() . ' aset sewa berjalan · Rp ' . number_format((float) (clone $q)->sum('rental_monthly_cost'), 0, ',', '.') . ' per bulan'
            . ($soon ? " · {$soon} segera berakhir / lewat jatuh tempo" : '');
    }
}
