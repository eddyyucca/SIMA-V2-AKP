<?php

namespace App\Filament\Resources\ArrivalPlans\Pages;

use App\Filament\Resources\ArrivalPlans\ArrivalPlanResource;
use App\Models\ArrivalPlan;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListArrivalPlans extends ListRecords
{
    protected static string $resource = ArrivalPlanResource::class;
    protected static ?string $title = 'Rencana Kedatangan Karyawan';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Buat Rencana')];
    }

    public function getSubheading(): string|Htmlable|null
    {
        $active = ArrivalPlan::active()->get();
        $week = $active->filter(fn ($p) => $p->days_left <= 7)->count();
        $notReady = $active->whereIn('status', ['planned', 'prepared'])->filter(fn ($p) => $p->days_left <= 7)->count();
        return $active->count() . ' rencana berjalan · ' . $week . ' tiba ≤ 7 hari' . ($notReady ? " · {$notReady} belum siap" : '');
    }
}
