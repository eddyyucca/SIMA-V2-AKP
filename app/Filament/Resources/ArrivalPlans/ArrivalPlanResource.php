<?php

namespace App\Filament\Resources\ArrivalPlans;

use App\Filament\Resources\ArrivalPlans\Pages\CreateArrivalPlan;
use App\Filament\Resources\ArrivalPlans\Pages\EditArrivalPlan;
use App\Filament\Resources\ArrivalPlans\Pages\ListArrivalPlans;
use App\Filament\Resources\ArrivalPlans\Pages\ViewArrivalPlan;
use App\Models\ArrivalPlan;
use App\Models\Department;
use App\Models\Location;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use UnitEnum;

class ArrivalPlanResource extends Resource
{
    protected static ?string $model = ArrivalPlan::class;
    protected static ?string $slug = 'rencana-kedatangan';
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;
    protected static string|UnitEnum|null $navigationGroup = 'Transaksi';
    protected static ?int $navigationSort = 0;
    protected static ?string $modelLabel = 'Rencana Kedatangan';
    protected static ?string $pluralModelLabel = 'Rencana Kedatangan';
    protected static ?string $recordTitleAttribute = 'plan_no';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nik')->label('NIK (boleh diisi nanti)')->maxLength(50)
                ->regex('/^[0-9]+$/')
                ->rules([Rule::unique('employees', 'nik')])
                ->validationMessages(['unique' => 'NIK ini sudah dipakai karyawan terdaftar.', 'regex' => 'NIK hanya boleh berisi angka (tanpa huruf, spasi, atau tanda baca).']),
            TextInput::make('name')->label('Nama karyawan')->required()->maxLength(150),
            TextInput::make('email')->label('Email (boleh diisi nanti saat serah terima)')->email()->maxLength(150)
                ->unique(table: 'arrival_plans', column: 'email', ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->whereIn('status', ArrivalPlan::ACTIVE))
                ->rules([Rule::unique('employees', 'email')])
                ->validationMessages(['unique' => 'Email ini sudah dipakai karyawan atau rencana lain.']),
            TextInput::make('phone')->label('Telepon')->tel()->maxLength(50),
            \App\Filament\Support\QuickAdd::department(Select::make('department_id')->label('Department')->options(fn () => Department::orderBy('name')->pluck('name', 'id'))),
            TextInput::make('position')->label('Jabatan')->maxLength(100),
            \App\Filament\Support\QuickAdd::location(Select::make('location_id')->label('Lokasi onsite')->required()->options(fn () => Location::orderBy('name')->pluck('name', 'id'))),
            DatePicker::make('arrival_date')->label('Tanggal tiba')->required()->minDate(fn (string $operation) => $operation === 'create' ? today() : null),
            Select::make('pic_id')->label('PIC IT yang menyiapkan')->default(fn () => auth()->id())->searchable()
                ->options(fn () => User::role(['Admin', 'Staff IT'])->orderBy('name')->pluck('name', 'id')),
            Textarea::make('notes')->label('Catatan')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['items', 'location', 'department', 'pic']))
            ->recordUrl(fn ($record) => static::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('plan_no')->label('No. Rencana')->searchable()->sortable(),
                TextColumn::make('name')->label('Karyawan')->searchable()->weight('bold'),
                TextColumn::make('department.name')->label('Department')->toggleable(),
                TextColumn::make('location.name')->label('Lokasi onsite'),
                TextColumn::make('arrival_date')->label('Tanggal tiba')->date('d/m/Y')->sortable(),
                TextColumn::make('days_left')->label('Tiba')->badge()
                    ->state(fn ($record) => ! $record->isActive() ? '-' : ($record->days_left < 0 ? 'Lewat ' . abs($record->days_left) . ' hr' : ($record->days_left === 0 ? 'Hari ini' : $record->days_left . ' hari lagi')))
                    ->color(fn ($record) => ! $record->isActive() ? 'gray' : ($record->days_left < 0 ? 'danger' : ($record->days_left <= 3 ? 'warning' : 'info'))),
                TextColumn::make('progress')->label('Barang siap')
                    ->state(fn ($record) => $record->progress()['ready'] . ' / ' . $record->progress()['total']),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn ($state) => ArrivalPlan::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) { 'planned' => 'gray', 'prepared' => 'warning', 'ready' => 'success', 'handed_over' => 'info', default => 'danger' }),
                TextColumn::make('pic.name')->label('PIC IT')->toggleable(),
            ])->defaultSort('arrival_date')
            ->filters([
                Filter::make('active')->label('Hanya yang masih berjalan')->default()->query(fn ($query) => $query->whereIn('status', ArrivalPlan::ACTIVE)),
                SelectFilter::make('status')->label('Status')->options(ArrivalPlan::STATUSES),
                SelectFilter::make('location_id')->label('Lokasi onsite')->options(fn () => Location::orderBy('name')->pluck('name', 'id')),
                Filter::make('this_week')->label('Tiba ≤ 7 hari')->query(fn ($query) => $query->whereIn('status', ArrivalPlan::ACTIVE)->whereDate('arrival_date', '<=', now()->addDays(7)->toDateString())),
                Filter::make('not_ready')->label('Belum siap')->query(fn ($query) => $query->whereIn('status', ['planned', 'prepared'])),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListArrivalPlans::route('/'),
            'create' => CreateArrivalPlan::route('/create'),
            'view' => ViewArrivalPlan::route('/{record}'),
            'edit' => EditArrivalPlan::route('/{record}/edit'),
        ];
    }
}
