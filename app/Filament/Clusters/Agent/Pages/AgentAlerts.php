<?php

namespace App\Filament\Clusters\Agent\Pages;

use App\Filament\Clusters\Agent;
use App\Services\AgentAlertReport;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/** Laporan peringatan armada per tingkat (Kritis / Sedang / Rendah) dengan ekspor CSV. */
class AgentAlerts extends Page
{
    protected static ?string $cluster = Agent::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;
    protected static ?string $navigationLabel = 'Laporan';
    protected static ?int $navigationSort = 4;
    protected static ?string $slug = 'laporan-peringatan';
    protected string $view = 'filament.agent.alerts';

    #[Url(as: 'tingkat')]
    public ?string $severity = null;

    public function getTitle(): string { return trans('agent.reports_title'); }
    public static function canAccess(): bool { return (bool) auth()->user()?->can('manage inventory'); }

    protected function getViewData(): array
    {
        $data = app(AgentAlertReport::class)->build();
        $severity = in_array($this->severity, AgentAlertReport::SEVERITIES, true)
            ? $this->severity
            // tanpa pilihan: buka tingkat tertinggi yang punya peringatan (bukan tab kosong)
            : collect(AgentAlertReport::SEVERITIES)->first(fn ($s) => $data['summary'][$s]['items'] > 0, 'critical');

        return $data + ['current' => $severity];   // bukan 'severity': nama itu dipakai properti Livewire dan akan menimpanya di tampilan
    }
}
