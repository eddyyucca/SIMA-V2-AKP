<?php

namespace App\Filament\Resources\Repairs;

use App\Filament\Resources\Repairs\Pages\ManageRepairs;
use App\Filament\Support\Ui;
use App\Models\Asset;
use App\Models\Employee;
use App\Models\Repair;
use App\Services\AssetService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class RepairResource extends Resource
{
    protected static ?string $model = Repair::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrench;
    protected static string|UnitEnum|null $navigationGroup = 'Transaksi';
    protected static ?int $navigationSort = 2;
    protected static ?string $modelLabel = 'Perbaikan';
    protected static ?string $pluralModelLabel = 'Perbaikan';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('asset_id')->label('Aset')->required()->searchable()
                ->options(fn () => Asset::active()->get()->mapWithKeys(fn ($a) => [$a->id => "{$a->asset_tag} — {$a->brand} {$a->model}"])),
            Select::make('reported_by')->label('Dilaporkan oleh')->searchable()->options(fn () => Employee::pluck('name', 'id')),
            Textarea::make('issue')->label('Kerusakan')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['asset', 'reporter']))->columns([
            TextColumn::make('asset.asset_tag')->label('Aset')->searchable(),
            TextColumn::make('reported_at')->label('Dilaporkan')->date('d/m/Y')->sortable(),
            TextColumn::make('reporter.name')->label('Pelapor'),
            TextColumn::make('issue')->label('Kerusakan')->limit(40)->wrap(),
            TextColumn::make('action')->label('Tindakan')->limit(40)->toggleable(),
            TextColumn::make('cost')->label('Biaya')->money('IDR', locale: 'id'),
            IconColumn::make('warranty_claim')->label('Klaim garansi')->boolean(),
            TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => ['reported' => 'Dilaporkan', 'in_progress' => 'Diproses', 'done' => 'Selesai'][$state])
                ->color(fn ($state) => ['reported' => 'danger', 'in_progress' => 'warning', 'done' => 'success'][$state]),
        ])->defaultSort('reported_at', 'desc')
            ->filters([SelectFilter::make('status')->options(['reported' => 'Dilaporkan', 'in_progress' => 'Diproses', 'done' => 'Selesai'])])
            ->recordActions([
                Action::make('process')->label('Proses')->icon(Heroicon::OutlinedPlay)
                    ->visible(fn ($record) => $record->status === 'reported' && auth()->user()->can('manage inventory'))
                    ->action(fn ($record) => $record->update(['status' => 'in_progress'])),
                Action::make('finish')->label('Selesai')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                    ->visible(fn ($record) => $record->status !== 'done' && auth()->user()->can('manage inventory'))
                    ->fillForm(fn ($record) => ['action' => $record->action, 'cost' => $record->cost, 'technician_vendor' => $record->technician_vendor, 'warranty_claim' => $record->warranty_claim])
                    ->schema([
                        Textarea::make('action')->label('Tindakan')->required(),
                        TextInput::make('cost')->label('Biaya')->numeric()->minValue(0)->maxValue(100000000000)->prefix('Rp')->default(0)->dehydrateStateUsing(fn ($state) => filled($state) ? $state : 0),
                        TextInput::make('technician_vendor')->label('Teknisi / Vendor'),
                        Toggle::make('warranty_claim')->label('Klaim garansi'),
                    ])
                    ->action(fn (array $data, Repair $record, Action $action) => Ui::run(fn () => app(AssetService::class)->finishRepair($record, $data), 'Perbaikan selesai, status aset dikembalikan', $action)),
            ]);
    }

    public static function canCreate(): bool { return auth()->user()?->can('manage inventory') ?? false; }

    public static function getPages(): array
    {
        return ['index' => ManageRepairs::route('/')];
    }
}
