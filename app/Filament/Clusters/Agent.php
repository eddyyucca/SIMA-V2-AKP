<?php

namespace App\Filament\Clusters;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Menu "SIMA Agent": ringkasan armada, rollout, kepatuhan, laporan peringatan, dan pengumuman, seperti menu Agent Monitor di SIMA lama. */
class Agent extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;
    protected static string|UnitEnum|null $navigationGroup = 'Inventaris';
    protected static ?int $navigationSort = 8;
    protected static ?string $navigationLabel = 'SIMA Agent';
    protected static ?string $slug = 'agent';
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function canAccess(): bool { return (bool) auth()->user()?->can('manage inventory'); }
}
