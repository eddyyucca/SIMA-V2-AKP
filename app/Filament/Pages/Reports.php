<?php

namespace App\Filament\Pages;

use App\Reports\ReportCatalog;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Reports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;
    protected static string|UnitEnum|null $navigationGroup = 'Laporan';
    protected static ?string $navigationLabel = 'Laporan';
    protected static ?string $title = 'Laporan';
    protected static ?string $slug = 'laporan';
    protected string $view = 'filament.pages.reports';

    public ?int $category_id = null;
    public ?int $location_id = null;
    public ?int $department_id = null;
    public ?string $status = null;
    public ?string $condition = null;
    public ?string $from = null;
    public ?string $to = null;
    public bool $low = false;
    public ?int $year = null;

    public static function canAccess(): bool { return auth()->user()?->can('view reports') ?? false; }

    public function query(): array
    {
        return array_filter([
            'category_id' => $this->category_id, 'location_id' => $this->location_id, 'department_id' => $this->department_id,
            'status' => $this->status, 'condition' => $this->condition, 'from' => $this->from, 'to' => $this->to, 'low' => $this->low ? 1 : null, 'year' => $this->year,
        ]);
    }

    public function catalog(): array { return ReportCatalog::titles(); }
}
