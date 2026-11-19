<?php

namespace App\Filament\Resources\IpAddresses;

use App\Filament\Resources\IpAddresses\Pages\ManageIpAddresses;
use App\Filament\Support\WithTrashed;
use App\Models\IpAddress;
use App\Models\Location;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use UnitEnum;

class IpAddressResource extends Resource
{
    use WithTrashed;

    protected static ?string $model = IpAddress::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;
    protected static string|UnitEnum|null $navigationGroup = 'Inventaris';
    protected static ?int $navigationSort = 7;
    protected static ?string $modelLabel = 'IP Address';
    protected static ?string $pluralModelLabel = 'List IP Device';
    protected static ?string $navigationLabel = 'List IP Device';
    protected static ?string $slug = 'ip-address';
    protected static ?string $recordTitleAttribute = 'device_name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('device_name')->label('Nama perangkat')->required()->maxLength(150)
                ->placeholder('mis. Switch Lantai 2, CCTV Gudang, Router Utama'),
            TextInput::make('ip_address')->label('IP Address')->required()->maxLength(45)->placeholder('192.168.1.10')
                ->rules(['ip'])
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->whereNull('deleted_at'))
                ->validationMessages(['ip' => 'Format IP tidak valid (contoh 192.168.1.10).', 'unique' => 'IP ini sudah dipakai perangkat lain.'])
                ->dehydrateStateUsing(fn ($state) => trim((string) $state)),
            TextInput::make('username')->label('Username')->maxLength(150)->autocomplete(false),
            TextInput::make('password')->label('Password')->password()->revealable()->maxLength(255)->autocomplete('new-password')
                ->helperText('Disimpan terenkripsi di database.'),
            Select::make('location_id')->label('Lokasi')->searchable()->preload()
                ->options(fn () => Location::orderBy('name')->pluck('name', 'id')),
            Textarea::make('notes')->label('Catatan (opsional)')->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('location'))->columns([
            TextColumn::make('device_name')->label('Nama perangkat')->searchable()->sortable()->weight('bold'),
            TextColumn::make('ip_address')->label('IP Address')->searchable()->sortable()->copyable()->fontFamily('mono'),
            TextColumn::make('username')->label('Username')->searchable()->copyable()->placeholder('-'),
            TextColumn::make('password')->label('Password')->state(fn ($record) => filled($record->password) ? '••••••••' : '-')
                ->tooltip('Klik "Lihat Password" untuk menampilkan'),
            TextColumn::make('location.name')->label('Lokasi')->placeholder('-')->sortable(),
            TextColumn::make('notes')->label('Catatan')->limit(40)->toggleable(isToggledHiddenByDefault: true),
        ])->defaultSort('device_name')->filters([
            SelectFilter::make('location_id')->label('Lokasi')->relationship('location', 'name'),
            TrashedFilter::make(),
        ])->recordActions([
            Action::make('reveal')->label('Lihat Password')->icon(Heroicon::OutlinedEye)
                ->visible(fn ($record) => ! $record->trashed() && (filled($record->password) || filled($record->username)))
                ->modalHeading(fn ($record) => 'Kredensial: ' . $record->device_name)
                ->modalContent(function ($record) {
                    activity()->performedOn($record)->causedBy(auth()->user())->log('Melihat kredensial perangkat');
                    return view('filament.ip-credentials', ['record' => $record]);
                })
                ->modalSubmitAction(false)->modalCancelActionLabel('Tutup'),
            EditAction::make(), DeleteAction::make(), RestoreAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageIpAddresses::route('/')];
    }
}
