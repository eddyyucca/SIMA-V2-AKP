<?php

namespace App\Filament\Support;

use App\Models\Category;
use App\Models\Department;
use App\Models\Location;
use App\Models\Vendor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Validation\Rules\Unique;

/**
 * Tombol (+) "tambah baru" di dalam dropdown form, supaya master data tidak perlu dibuat lewat menu lain.
 * Mengikuti izin: hanya yang boleh membuat master tersebut (Admin) melihat tombolnya; yang lain tetap memilih dari daftar.
 */
class QuickAdd
{
    private static function nameInput(string $label, string $table, ?string $type = null): TextInput
    {
        return TextInput::make('name')->label($label)->required()->maxLength(150)
            ->unique($table, 'name', modifyRuleUsing: function (Unique $rule) use ($type) {
                if ($type) $rule->where('type', $type);
                return $rule->whereNull('deleted_at');
            })
            ->validationMessages(['unique' => 'Nama ini sudah ada.']);
    }

    private static function wire(Select $select, string $model, array $form, \Closure $create, string $heading): Select
    {
        return $select->searchable()
            ->createOptionForm($form)
            ->createOptionUsing(function (array $data) use ($create, $model) {
                abort_unless(auth()->user()?->can('create', $model), 403);
                return $create($data);
            })
            ->createOptionAction(fn ($action) => $action->modalHeading($heading)->modalSubmitActionLabel('Tambah')->tooltip($heading)
                ->visible(fn () => auth()->user()?->can('create', $model) ?? false));
    }

    public static function category(Select $select, string $type): Select
    {
        return self::wire($select, Category::class, [self::nameInput('Nama kategori', 'categories', $type)],
            fn (array $d) => Category::create(['name' => trim($d['name']), 'type' => $type])->id, 'Tambah kategori baru');
    }

    public static function vendor(Select $select): Select
    {
        return self::wire($select, Vendor::class, [self::nameInput('Nama vendor', 'vendors'), TextInput::make('contact_person')->label('Kontak'), TextInput::make('phone')->label('Telepon')->tel()],
            fn (array $d) => Vendor::create(['name' => trim($d['name']), 'contact_person' => $d['contact_person'] ?? null, 'phone' => $d['phone'] ?? null])->id, 'Tambah vendor baru');
    }

    public static function location(Select $select): Select
    {
        return self::wire($select, Location::class, [self::nameInput('Nama lokasi', 'locations'), TextInput::make('branch')->label('Cabang'), Textarea::make('address')->label('Alamat')->rows(2)],
            fn (array $d) => Location::create(['name' => trim($d['name']), 'branch' => $d['branch'] ?? null, 'address' => $d['address'] ?? null])->id, 'Tambah lokasi baru');
    }

    public static function department(Select $select): Select
    {
        if (\App\Support\Hris::managed()) return $select->helperText('Department dari HRIS (tidak bisa ditambah manual).');
        return self::wire($select, Department::class, [self::nameInput('Nama department', 'departments')],
            fn (array $d) => Department::create(['name' => trim($d['name'])])->id, 'Tambah department baru');
    }
}
