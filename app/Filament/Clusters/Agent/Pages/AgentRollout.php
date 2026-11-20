<?php

namespace App\Filament\Clusters\Agent\Pages;

use App\Filament\Clusters\Agent;
use App\Services\AgentRolloutReport;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class AgentRollout extends Page
{
    protected static ?string $cluster = Agent::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;
    protected static ?string $navigationLabel = 'Rollout';
    protected static ?int $navigationSort = 2;
    protected static ?string $slug = 'rollout';
    protected string $view = 'filament.agent.rollout';

    public function getTitle(): string { return trans('agent.rollout_title'); }
    public static function canAccess(): bool { return (bool) auth()->user()?->can('manage inventory'); }

    protected function getViewData(): array { return app(\App\Services\AgentRolloutReport::class)->build(); }
}
