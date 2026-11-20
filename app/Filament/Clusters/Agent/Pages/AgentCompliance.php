<?php

namespace App\Filament\Clusters\Agent\Pages;

use App\Filament\Clusters\Agent;
use App\Services\AgentComplianceReport;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class AgentCompliance extends Page
{
    protected static ?string $cluster = Agent::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;
    protected static ?string $navigationLabel = 'Kepatuhan';
    protected static ?int $navigationSort = 3;
    protected static ?string $slug = 'kepatuhan';
    protected string $view = 'filament.agent.compliance';

    public function getTitle(): string { return trans('agent.compliance_title'); }
    public static function canAccess(): bool { return (bool) auth()->user()?->can('manage inventory'); }

    protected function getViewData(): array { return app(\App\Services\AgentComplianceReport::class)->build(); }
}
