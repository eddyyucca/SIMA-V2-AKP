<?php

namespace App\Filament\Resources\Departments;

use App\Filament\Resources\Departments\Pages\ManageDepartments;
use App\Filament\Support\WithTrashed;
use App\Models\Department;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use UnitEnum;

class DepartmentResource extends Resource
{
    use WithTrashed;

    protected static ?string $model = Department::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;
    protected static string|UnitEnum|null $navigationGroup = 'Master Data';
    protected static ?int $navigationSort = 2;
    protected static ?string $modelLabel = 'Department';
    protected static ?string $pluralModelLabel = 'Department';
    protected static ?string $recordTitleAttribute = 'name';

    // Department dikelola HRIS: tidak ada tambah/ubah/hapus manual saat HRIS terhubung.
    public static function canCreate(): bool { return ! \App\Support\Hris::managed() && parent::canCreate(); }
    public static function canEdit($record): bool { return ! \App\Support\Hris::managed() && parent::canEdit($record); }
    public static function canDelete($record): bool { return ! \App\Support\Hris::managed() && parent::canDelete($record); }
    public static function canDeleteAny(): bool { return ! \App\Support\Hris::managed() && parent::canDeleteAny(); }
    public static function canRestore($record): bool { return ! \App\Support\Hris::managed() && parent::canRestore($record); }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->label('Nama department')->required()->maxLength(150)->unique(ignoreRecord: true, modifyRuleUsing: fn (\Illuminate\Validation\Rules\Unique $rule) => $rule->whereNull('deleted_at'))->validationMessages(['unique' => 'Department ini sudah ada.'])]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
                TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                TextColumn::make('employees_count')->counts('employees')->label('Karyawan'),
                TextColumn::make('hris_source')->label('Sumber')->badge()
                    ->state(fn (Department $r) => $r->hris_synced_at ? 'HRIS' : 'Bukan dari HRIS')
                    ->color(fn ($state) => $state === 'HRIS' ? 'success' : 'gray')
                    ->tooltip('Department yang bukan dari HRIS adalah sisa input manual / SIMA: tersimpan demi riwayat, tidak muncul di pilihan formulir.'),
            ])
            ->filters([TrashedFilter::make()])
            ->recordActions([EditAction::make(), DeleteAction::make(), RestoreAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageDepartments::route('/')];
    }
}
