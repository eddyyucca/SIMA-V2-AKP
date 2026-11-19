<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Filament\Support\WithTrashed;
use App\Models\Category;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use UnitEnum;

class CategoryResource extends Resource
{
    use WithTrashed;

    protected static ?string $model = Category::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;
    protected static string|UnitEnum|null $navigationGroup = 'Master Data';
    protected static ?int $navigationSort = 1;
    protected static ?string $modelLabel = 'Kategori';
    protected static ?string $pluralModelLabel = 'Kategori';
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nama')->required()->maxLength(150)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (\Illuminate\Validation\Rules\Unique $rule, \Filament\Schemas\Components\Utilities\Get $get) => $rule->where('type', $get('type'))->whereNull('deleted_at'))
                ->validationMessages(['unique' => 'Kategori dengan nama ini sudah ada pada jenis yang sama.']),
            Select::make('type')->label('Jenis')->options(['asset' => 'Aset', 'accessory' => 'Accessories', 'consumable' => 'Consumable', 'component' => 'Komponen'])->required()->live(),
            TextInput::make('code_prefix')->label('Prefix kode')->helperText('Contoh: NB, MNT (dipakai untuk kode aset otomatis)')->maxLength(10)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (\Illuminate\Validation\Rules\Unique $rule) => $rule->whereNull('deleted_at'))
                ->validationMessages(['unique' => 'Prefix kode ini sudah dipakai kategori lain.']),
            TextInput::make('useful_life_years')->label('Umur ekonomis (tahun)')->numeric()->integer()->minValue(1)->maxValue(50)
                ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('type') === 'asset')
                ->helperText('Dipakai untuk menyusutkan aset kategori ini (garis lurus). Kosong = ' . config('depreciation.default_life_years') . ' tahun.'),
            TextInput::make('residual_percent')->label('Nilai residu (% dari harga beli)')->numeric()->minValue(0)->maxValue(100)->suffix('%')->default(0)->dehydrateStateUsing(fn ($state) => filled($state) ? $state : 0)
                ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('type') === 'asset'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Nama')->searchable()->sortable(),
            TextColumn::make('type')->label('Jenis')->badge(),
            TextColumn::make('code_prefix')->label('Prefix'),
            TextColumn::make('useful_life_years')->label('Umur (tahun)')->placeholder('-'),
            TextColumn::make('residual_percent')->label('Residu')->suffix('%')->placeholder('-'),
        ])->filters([
            SelectFilter::make('type')->label('Jenis')->options(['asset' => 'Aset', 'accessory' => 'Accessories', 'consumable' => 'Consumable', 'component' => 'Komponen']),
            TrashedFilter::make(),
        ])->recordActions([EditAction::make(), DeleteAction::make(), RestoreAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCategories::route('/')];
    }
}
