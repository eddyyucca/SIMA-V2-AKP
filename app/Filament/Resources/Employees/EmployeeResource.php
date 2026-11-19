<?php

namespace App\Filament\Resources\Employees;

use App\Filament\Resources\Employees\Pages\ManageEmployees;
use App\Filament\Support\WithTrashed;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use App\Services\EmployeeAccountService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use UnitEnum;

class EmployeeResource extends Resource
{
    use WithTrashed;

    protected static ?string $model = Employee::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;
    protected static string|UnitEnum|null $navigationGroup = 'Master Data';
    protected static ?int $navigationSort = 5;
    protected static ?string $modelLabel = 'Karyawan';
    protected static ?string $pluralModelLabel = 'Karyawan';
    protected static ?string $recordTitleAttribute = 'name';

    // HRIS terhubung = data karyawan hanya dari sinkronisasi HRIS (tidak ada tambah/ubah/hapus/impor manual).
    public static function canCreate(): bool { return ! \App\Support\Hris::managed() && parent::canCreate(); }
    public static function canEdit($record): bool { return ! \App\Support\Hris::managed() && parent::canEdit($record); }
    public static function canDelete($record): bool { return ! \App\Support\Hris::managed() && parent::canDelete($record); }
    public static function canDeleteAny(): bool { return ! \App\Support\Hris::managed() && parent::canDeleteAny(); }
    public static function canRestore($record): bool { return ! \App\Support\Hris::managed() && parent::canRestore($record); }
    public static function canForceDelete($record): bool { return false; }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nik')->label('NIK')->inputMode('numeric')->maxLength(50)->unique(ignoreRecord: true)
                ->required(fn (Get $get) => (bool) $get('can_login'))->minLength(fn (Get $get) => $get('can_login') ? 6 : null)
                ->regex('/^[0-9]+$/')
                ->validationMessages(['unique' => 'NIK ini sudah dipakai karyawan lain.', 'regex' => 'NIK hanya boleh berisi angka (tanpa huruf, spasi, atau tanda baca).', 'required' => 'NIK wajib diisi bila karyawan boleh login (NIK = username dan password awal).', 'min' => 'NIK minimal 6 karakter agar password awal tidak mudah ditebak.'])
                ->helperText('Nomor induk karyawan, hanya angka, tidak boleh sama dengan karyawan lain. Dipakai sebagai username login.'),
            TextInput::make('name')->label('Nama')->required(),
            TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
            TextInput::make('position')->label('Jabatan')->maxLength(100),
            \App\Filament\Support\QuickAdd::department(Select::make('department_id')->label('Department')->options(fn () => Department::options())),
            TextInput::make('phone')->label('Telepon')->tel(),
            Select::make('status')->options(['active' => 'Aktif', 'resigned' => 'Resigned'])->default('active')->required(),
            Section::make('Akses login')->columnSpanFull()->description('IT menentukan siapa yang boleh masuk ke aplikasi. Karyawan tanpa akses login tetap tercatat dan bisa memegang aset.')->schema([
                Toggle::make('can_login')->label('Boleh login ke aplikasi')->default(false)->live()
                    ->disabled(fn (?Employee $record) => $record?->user && ! app(EmployeeAccountService::class)->mayManage(auth()->user(), $record->user))
                    ->helperText('Username login = NIK, password awal = NIK (karyawan wajib menggantinya saat login pertama). NIK wajib diisi, minimal 6 karakter. Tidak ada email; lupa password diatasi dengan "Reset password" oleh IT. Mematikannya menonaktifkan akun (tidak dihapus).'),
                Select::make('login_role')->label('Peran')->native(false)->default('User')
                    ->options(fn () => app(EmployeeAccountService::class)->assignableRoles(auth()->user()))
                    ->visible(fn (Get $get) => (bool) $get('can_login'))->required(fn (Get $get) => (bool) $get('can_login'))
                    ->helperText('Karyawan biasa cukup "User". Peran lain hanya bisa diberikan oleh Admin.'),
            ]),
        ]);
    }

    /** Isi formulir ubah: keadaan akun login saat ini (kolom ini bukan bagian tabel karyawan). */
    public static function accountFormData(Employee $record): array
    {
        $s = app(EmployeeAccountService::class)->state($record->loadMissing('user.roles'));
        return ['can_login' => $s['active'], 'login_role' => $s['role']];
    }

    /** Simpan karyawan + akses loginnya dalam satu transaksi; bila akses ditolak aturan, karyawan juga tidak tersimpan. */
    public static function saveWithAccount(?Employee $record, array $data, $action): Employee
    {
        $canLogin = (bool) ($data['can_login'] ?? false);
        $role = (string) ($data['login_role'] ?? 'User');
        unset($data['can_login'], $data['login_role'], $data['user_id']);
        try {
            $res = DB::transaction(function () use (&$record, $data, $canLogin, $role) {
                $svc = app(EmployeeAccountService::class);
                // punya akses login sebelum disimpan? (menandai resign otomatis mencabutnya; itu bukan permintaan "beri akses")
                $hadLogin = $record ? $svc->state($record->loadMissing('user.roles'))['active'] : false;
                $record = $record ? tap($record)->update($data) : Employee::create($data);
                if ($hadLogin && $record->status === 'resigned') $canLogin = false;
                return $svc->apply($record->refresh(), $canLogin, $role);
            });
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Akses login tidak disimpan')->body($e->getMessage())->send();
            $action->halt();
        }
        if ($res['changed']) {
            $n = Notification::make();
            if ($canLogin) {
                $n->success()->title(($res['created'] ? 'Akun login dibuat' : 'Akses login diaktifkan') . " untuk {$record->name}")
                    ->body('Username: ' . $record->nik . ' · password awal: NIK yang sama. Karyawan wajib menggantinya saat login pertama.');
            } else {
                $n->success()->title('Akses login dinonaktifkan')->body('Akun tidak dihapus dan bisa diaktifkan lagi kapan saja.');
            }
            $n->send();
        }
        return $record;
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('user.roles'))->columns([
            TextColumn::make('nik')->label('NIK')->searchable()->sortable()->placeholder('-'),
            TextColumn::make('name')->label('Nama')->searchable()->sortable(),
            TextColumn::make('position')->label('Jabatan')->searchable()->placeholder('-'),
            TextColumn::make('department.name')->label('Department')->placeholder('-'),
            TextColumn::make('section')->label('Section')->searchable()->placeholder('-')->toggleable(),
            TextColumn::make('business_unit')->label('Business Unit')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('hris_source')->label('Sumber')->badge()->toggleable()
                ->state(fn (Employee $r) => $r->hris_missing_at ? 'Tidak ada di HRIS' : ($r->hris_synced_at ? 'HRIS' : 'Belum sinkron'))
                ->color(fn ($state) => match ($state) { 'HRIS' => 'success', 'Tidak ada di HRIS' => 'danger', default => 'gray' }),
            TextColumn::make('email')->searchable()->toggleable(),
            TextColumn::make('status')->badge()->color(fn ($state) => $state === 'active' ? 'success' : 'danger'),
            TextColumn::make('login')->label('Login')->badge()
                ->state(fn (Employee $r) => ! $r->user ? 'Tidak' : ($r->user->is_active ? 'Bisa login' : 'Nonaktif'))
                ->color(fn ($state) => match ($state) { 'Bisa login' => 'success', 'Nonaktif' => 'warning', default => 'gray' }),
            TextColumn::make('assets_count')->counts('assets')->label('Aset'),
        ])->filters([
            SelectFilter::make('status')->options(['active' => 'Aktif', 'resigned' => 'Resigned']),
            SelectFilter::make('login')->label('Akses login')->options(['yes' => 'Bisa login', 'inactive' => 'Akun nonaktif', 'no' => 'Tanpa akun'])
                ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                    'yes' => $query->whereHas('user', fn ($u) => $u->where('is_active', true)),
                    'inactive' => $query->whereHas('user', fn ($u) => $u->where('is_active', false)),
                    'no' => $query->whereNull('user_id'),
                    default => $query,
                }),
            SelectFilter::make('department_id')->label('Department')->relationship('department', 'name'),
            SelectFilter::make('hris_state')->label('Sumber data')->options(['hris' => 'Dari HRIS', 'missing' => 'Tidak ada di HRIS (perlu diputuskan)'])
                ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                    'hris' => $query->whereNotNull('hris_synced_at')->whereNull('hris_missing_at'),
                    'missing' => $query->whereNotNull('hris_missing_at'),
                    default => $query,
                }),
            TrashedFilter::make(),
        ])->recordActions([
            Action::make('items')->label('Barang')->icon(Heroicon::OutlinedInboxStack)
                ->modalHeading(fn ($record) => 'Barang dipegang: ' . $record->name)
                ->modalContent(fn ($record) => view('filament.employee-items', ['employee' => $record->fresh()]))
                ->modalSubmitAction(false)->modalCancelActionLabel('Tutup'),
            Action::make('offboarding')->label('Offboarding')->icon(Heroicon::OutlinedArrowRightStartOnRectangle)->color('warning')
                ->visible(fn ($record) => $record->status === 'active' && auth()->user()->can('manage inventory'))
                ->url(fn ($record) => \App\Filament\Pages\Offboarding::getUrl(['employee' => $record->id])),
            Action::make('reset_password')->label('Reset password ke NIK')->icon(Heroicon::OutlinedKey)->color('warning')
                ->visible(fn (Employee $r) => $r->user?->is_active && auth()->user()->can('manage inventory') && app(EmployeeAccountService::class)->mayManage(auth()->user(), $r->user))
                ->requiresConfirmation()->modalHeading('Reset password ke NIK?')
                ->modalDescription(fn (Employee $r) => "Password {$r->name} dikembalikan ke NIK ({$r->nik}) dan wajib diganti saat login berikutnya. Sesi yang sedang berjalan berakhir.")
                ->action(function (Employee $r) {
                    try {
                        app(EmployeeAccountService::class)->resetPassword($r);
                        Notification::make()->success()->title('Password direset ke NIK')->body("{$r->name} login dengan username dan password {$r->nik}, lalu wajib menggantinya.")->send();
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title('Gagal mereset password')->body($e->getMessage())->send();
                    }
                }),
            EditAction::make()->mutateRecordDataUsing(fn (array $data, Employee $record) => $data + static::accountFormData($record))
                ->using(fn (Employee $record, array $data, $action) => static::saveWithAccount($record, $data, $action)),
            DeleteAction::make(), RestoreAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageEmployees::route('/')];
    }
}
