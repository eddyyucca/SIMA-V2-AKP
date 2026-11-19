<?php

namespace App\Filament\Pages;

use App\Filament\Support\Ui;
use App\Models\Asset;
use App\Models\Employee;
use App\Services\AssetService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class MyItems extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;
    protected static ?string $navigationLabel = 'Barang Saya';
    protected static ?string $title = 'Barang Saya';
    protected static ?string $slug = 'barang-saya';
    protected static ?int $navigationSort = -1;
    protected string $view = 'filament.pages.my-items';

    public static function canAccess(): bool { return auth()->check(); }

    public function employee(): ?Employee { return Employee::where('user_id', auth()->id())->first(); }

    public function table(Table $table): Table
    {
        return $table->query(fn () => Asset::query()->where('employee_id', $this->employee()?->id ?? 0))
            ->columns([
                TextColumn::make('asset_tag')->label('Kode')->weight('bold'),
                TextColumn::make('brand')->label('Perangkat')->formatStateUsing(fn ($state, $record) => trim("{$record->brand} {$record->model}")),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => Asset::STATUSES[$state] ?? $state),
                TextColumn::make('condition')->label('Kondisi')->formatStateUsing(fn ($state) => Asset::CONDITIONS[$state] ?? $state),
            ])
            ->recordActions([
                Action::make('report')->label('Laporkan Kerusakan')->icon(Heroicon::OutlinedExclamationTriangle)->color('warning')
                    ->schema([Textarea::make('issue')->label('Apa kerusakannya?')->required()])
                    ->action(fn (array $data, Asset $record, Action $action) => Ui::run(
                        fn () => app(AssetService::class)->reportDamage($record, $this->employee(), $data['issue']), 'Laporan terkirim ke tim IT', $action)),
            ])->emptyStateHeading('Belum ada aset yang Anda pegang');
    }
}
