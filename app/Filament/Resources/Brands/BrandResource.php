<?php

namespace App\Filament\Resources\Brands;

use App\Filament\Resources\Brands\Pages\ManageBrands;
use App\Filament\Support\WithTrashed;
use App\Models\Brand;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use UnitEnum;

class BrandResource extends Resource
{
    use WithTrashed;

    protected static ?string $model = Brand::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookmarkSquare;
    protected static string|UnitEnum|null $navigationGroup = 'Master Data';
    protected static ?int $navigationSort = 6;
    protected static ?string $modelLabel = 'Merek';
    protected static ?string $pluralModelLabel = 'Merek';
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nama merek')->required()->maxLength(100)->unique(ignoreRecord: true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->withCount(['models', 'assets']))->columns([
            TextColumn::make('name')->label('Merek')->searchable()->sortable()->weight('bold'),
            TextColumn::make('models_count')->label('Jumlah model')->sortable(),
            TextColumn::make('assets_count')->label('Jumlah aset')->sortable(),
        ])->defaultSort('name')->filters([TrashedFilter::make()])->recordActions([
            EditAction::make(),
            DeleteAction::make()->before(function (Brand $record, Action $action) {
                if ($record->assets()->exists() || $record->models()->exists()) {
                    Notification::make()->title('Merek masih dipakai')->body('Hapus/pindahkan model dan aset yang memakai merek ini terlebih dulu.')->danger()->send();
                    $action->halt();
                }
            }),
            RestoreAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageBrands::route('/')];
    }
}
