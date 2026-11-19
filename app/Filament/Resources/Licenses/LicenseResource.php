<?php

namespace App\Filament\Resources\Licenses;

use App\Filament\Resources\Licenses\Pages\ManageLicenses;
use App\Filament\Support\Ui;
use App\Filament\Support\WithTrashed;
use App\Models\Asset;
use App\Models\Employee;
use App\Models\License;
use App\Models\LicenseSeat;
use App\Models\Vendor;
use App\Services\LicenseService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use UnitEnum;

class LicenseResource extends Resource
{
    use WithTrashed;

    protected static ?string $model = License::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;
    protected static string|UnitEnum|null $navigationGroup = 'Inventaris';
    protected static ?int $navigationSort = 6;
    protected static ?string $modelLabel = 'Lisensi';
    protected static ?string $pluralModelLabel = 'Lisensi & Langganan';
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nama')->required(),
            Select::make('type')->label('Jenis')->options(License::TYPES)->required(),
            TextInput::make('license_key')->label('License key')->password()->revealable()->helperText('Disimpan terenkripsi.'),
            TextInput::make('seats')->label('Jumlah seat')->numeric()->integer()->minValue(1)->maxValue(100000)->default(1)->required(),
            DatePicker::make('purchase_date')->label('Tanggal beli'),
            DatePicker::make('expires_at')->label('Tanggal expired'),
            TextInput::make('price')->label('Harga')->numeric()->minValue(0)->maxValue(100000000000)->prefix('Rp')->default(0)->dehydrateStateUsing(fn ($state) => filled($state) ? $state : 0)
                ->helperText('Perpetual: harga beli. Langganan / domain / hosting / VPS: harga per periode perpanjangan.'),
            \App\Filament\Support\QuickAdd::vendor(Select::make('vendor_id')->label('Vendor')->options(fn () => Vendor::pluck('name', 'id'))),
            Textarea::make('notes')->label('Catatan'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Nama')->searchable()->sortable(),
            TextColumn::make('type')->label('Jenis')->badge()->formatStateUsing(fn ($state) => License::TYPES[$state] ?? $state),
            TextColumn::make('seats')->label('Seat'),
            TextColumn::make('seats_used')->label('Terpakai')->badge()
                ->color(fn ($record) => $record->seats_used > $record->seats ? 'danger' : 'gray')
                ->formatStateUsing(fn ($record) => $record->seats_used . ($record->seats_used > $record->seats ? ' ⚠ melebihi' : '')),
            TextColumn::make('seats_free')->label('Sisa'),
            TextColumn::make('expires_at')->label('Expired')->date('d/m/Y')->sortable()
                ->color(fn ($state) => $state && $state->lte(now()->addDays(30)) ? 'danger' : null),
            TextColumn::make('price')->label('Harga')->money('IDR', locale: 'id', decimalPlaces: 0)->sortable()
                ->summarize(Sum::make()->label('Total harga')->money('IDR', locale: 'id', decimalPlaces: 0)),
            TextColumn::make('vendor.name')->label('Vendor')->toggleable(),
        ])->filters([
            SelectFilter::make('type')->options(License::TYPES),
            Filter::make('expiring')->label('Expired ≤ 30 hari')->query(fn ($query) => $query->whereBetween('expires_at', [now()->toDateString(), now()->addDays(30)->toDateString()])),
            TrashedFilter::make(),
        ])->recordActions([
            Action::make('assign')->label('Alokasi Seat')->icon(Heroicon::OutlinedUserPlus)
                ->visible(fn () => auth()->user()->can('manage inventory'))
                ->schema([
                    Select::make('employee_id')->label('Karyawan')->options(fn () => Employee::where('status', 'active')->pluck('name', 'id'))->searchable()->requiredWithout('asset_id'),
                    Select::make('asset_id')->label('atau Aset')->options(fn () => Asset::get()->mapWithKeys(fn ($a) => [$a->id => "{$a->asset_tag} — {$a->brand} {$a->model}"]))->searchable()->requiredWithout('employee_id'),
                ])
                ->action(fn (array $data, License $record, Action $action) => Ui::run(
                    fn () => app(LicenseService::class)->assign($record, ! empty($data['employee_id']) ? Employee::find($data['employee_id']) : null, ! empty($data['asset_id']) ? Asset::find($data['asset_id']) : null),
                    'Seat dialokasikan', $action)),
            Action::make('revoke')->label('Cabut Seat')->icon(Heroicon::OutlinedUserMinus)->color('warning')
                ->visible(fn () => auth()->user()->can('manage inventory'))
                ->schema([
                    Select::make('seat_id')->label('Seat aktif')->required()
                        ->options(fn (License $record) => $record->activeSeats()->with(['employee', 'asset'])->get()->mapWithKeys(fn ($s) => [$s->id => $s->employee?->name ?? $s->asset?->asset_tag ?? '-'])),
                ])
                ->action(fn (array $data, License $record, Action $action) => Ui::run(
                    fn () => app(LicenseService::class)->revoke(LicenseSeat::where('license_id', $record->id)->findOrFail($data['seat_id'])),
                    'Seat dicabut', $action)),
            Action::make('holders')->label('Pemegang Seat')->icon(Heroicon::OutlinedUsers)
                ->modalHeading(fn ($record) => 'Seat aktif: ' . $record->name)
                ->modalContent(fn ($record) => view('filament.holders', ['rows' => $record->activeSeats()->with(['employee', 'asset'])->get()
                    ->map(fn ($s) => ['name' => $s->employee?->name ?? $s->asset?->asset_tag ?? '-', 'detail' => 'sejak ' . $s->assigned_at->format('d/m/Y')])]))
                ->modalSubmitAction(false)->modalCancelActionLabel('Tutup'),
            EditAction::make(), DeleteAction::make(), RestoreAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageLicenses::route('/')];
    }
}
