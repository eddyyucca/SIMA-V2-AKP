<?php

namespace App\Filament\Resources\AssetModels;

use App\Filament\Resources\AssetModels\Pages\ManageAssetModels;
use App\Filament\Support\WithTrashed;
use App\Models\AssetModel;
use App\Models\Brand;
use App\Models\Category;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class AssetModelResource extends Resource
{
    use WithTrashed;

    protected static ?string $model = AssetModel::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquare3Stack3d;
    protected static string|UnitEnum|null $navigationGroup = 'Master Data';
    protected static ?int $navigationSort = 7;
    protected static ?string $modelLabel = 'Model';
    protected static ?string $pluralModelLabel = 'Model';
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('brand_id')->label('Merek')->required()->searchable()->preload()->live()
                ->options(fn () => Brand::orderBy('name')->pluck('name', 'id'))
                ->createOptionForm([TextInput::make('name')->label('Nama merek')->required()->unique('brands', 'name')])
                ->createOptionUsing(fn (array $data) => Brand::create($data)->id),
            Select::make('category_id')->label('Kategori aset')->searchable()->options(fn () => Category::where('type', 'asset')->pluck('name', 'id')),
            TextInput::make('name')->label('Nama model')->required()->maxLength(150)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('brand_id', $get('brand_id')))
                ->validationMessages(['unique' => 'Model ini sudah ada pada merek tersebut.']),
            Textarea::make('default_specs')->label('Spesifikasi bawaan')->helperText('Terisi otomatis ke spesifikasi aset baru yang memakai model ini.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['brand', 'category'])->withCount('assets'))->columns([
            TextColumn::make('brand.name')->label('Merek')->searchable()->sortable(),
            TextColumn::make('name')->label('Model')->searchable()->sortable()->weight('bold'),
            TextColumn::make('category.name')->label('Kategori'),
            TextColumn::make('default_specs')->label('Spesifikasi bawaan')->limit(50)->toggleable(),
            TextColumn::make('assets_count')->label('Jumlah aset')->sortable(),
        ])->defaultSort('name')->filters([
            SelectFilter::make('brand_id')->label('Merek')->options(fn () => Brand::orderBy('name')->pluck('name', 'id')),
            SelectFilter::make('category_id')->label('Kategori')->options(fn () => Category::where('type', 'asset')->pluck('name', 'id')),
            TrashedFilter::make(),
        ])->recordActions([
            EditAction::make(),
            DeleteAction::make()->before(function (AssetModel $record, Action $action) {
                if ($record->assets()->exists()) {
                    Notification::make()->title('Model masih dipakai')->body('Ada aset yang memakai model ini.')->danger()->send();
                    $action->halt();
                }
            }),
            RestoreAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAssetModels::route('/')];
    }
}
