<?php

namespace Tests\Qa;

use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Filament\Resources\Departments\Pages\ManageDepartments;
use App\Filament\Resources\Locations\Pages\ManageLocations;
use App\Filament\Resources\Vendors\Pages\ManageVendors;
use App\Models\Department;
use App\Models\Location;
use App\Models\Vendor;
use Livewire\Livewire;
use Tests\QaTestCase;

/** Perbaikan yang ditemukan lewat uji menyeluruh di browser. */
class Qa37_EndToEndFixesTest extends QaTestCase
{
    public function test_master_names_must_be_unique(): void
    {
        $this->as($this->admin());
        foreach ([[ManageDepartments::class, Department::class], [ManageLocations::class, Location::class], [ManageVendors::class, Vendor::class]] as [$page, $model]) {
            Livewire::test($page)->callAction('create', ['name' => 'Nama Kembar Uji'])->assertHasNoActionErrors();
            Livewire::test($page)->callAction('create', ['name' => 'Nama Kembar Uji'])->assertHasActionErrors(['name']);
            $this->assertSame(1, $model::where('name', 'Nama Kembar Uji')->count(), $model);
        }
    }

    public function test_filament_screen_reader_texts_are_translated_to_indonesian(): void
    {
        app()->setLocale('id');
        foreach (['filament-panels::layout.skip_to_content.label', 'filament-panels::global-search.field.instructions', 'filament-tables::table.loading',
                  'filament-forms::components.select.required_description', 'filament-tables::table.columns.icon.boolean.true'] as $key) {
            $this->assertNotSame($key, __($key), "terjemahan hilang: {$key}");
        }
        $this->assertSame('3 hasil', trans_choice('filament-tables::table.result_count', 3, ['count' => 3]));
    }

    public function test_asset_list_has_a_single_add_button(): void
    {
        $this->as($this->staff());
        // satu-satunya tombol tambah ada di toolbar tabel; header tidak menduplikasinya
        $page = Livewire::test(ListAssets::class);
        $this->assertCount(0, $page->instance()->getCachedHeaderActions());
        $page->assertTableActionExists('toolbar_add', null, null);
    }

    public function test_code_prefixes_are_configurable_in_settings(): void
    {
        $this->as($this->admin());
        $acc = \App\Models\Accessory::create(['name' => 'Mouse Uji', 'qty_total' => 1, 'min_qty' => 0]);
        $this->assertSame('ACC-' . str_pad((string) $acc->id, 4, '0', STR_PAD_LEFT), $acc->code);

        Livewire::test(\App\Filament\Pages\Settings::class)
            ->set('codes', ['accessory' => 'akp-acc', 'consumable' => 'AKP-CON', 'component' => 'AKP-CMP'])
            ->call('saveCodes')->assertHasNoErrors();
        $acc->refresh();
        $this->assertSame('AKP-ACC-' . str_pad((string) $acc->id, 4, '0', STR_PAD_LEFT), $acc->code);
        $this->assertSame($acc->id, \App\Services\LabelService::resolve($acc->code)[1]->id);
        $this->assertSame($acc->id, \App\Services\LabelService::resolve('ACC-' . $acc->id)[1]->id); // label lama tetap terbaca

        Livewire::test(\App\Filament\Pages\Settings::class)
            ->set('codes', ['accessory' => 'X', 'consumable' => 'X', 'component' => 'Y'])->call('saveCodes')->assertHasErrors();
        Livewire::test(\App\Filament\Pages\Settings::class)
            ->set('codes', ['accessory' => '', 'consumable' => 'A', 'component' => 'B'])->call('saveCodes')->assertHasErrors(['codes.accessory']);
    }

    public function test_handover_lives_in_each_stock_history_not_in_the_side_menu(): void
    {
        $this->assertFalse(\App\Filament\Resources\AccessoryHandovers\AccessoryHandoverResource::shouldRegisterNavigation());
        $this->as($this->admin());
        $emp = \App\Models\Employee::where('status', 'active')->firstOrFail();
        $acc = \App\Models\Accessory::create(['name' => 'Mouse BAST', 'qty_total' => 5, 'min_qty' => 0]);
        $h = app(\App\Services\AccessoryHandoverService::class)->checkout($emp, [['item' => 'accessory:' . $acc->id, 'qty' => 1]]);
        $row = \App\Models\StockMovement::where('stockable_type', \App\Models\Accessory::class)->where('type', 'out')->latest('id')->first();
        $page = Livewire::test(\App\Filament\Pages\StockHistory\AccessoryStockHistory::class);
        $page->assertCanSeeTableRecords([$row])->assertTableActionVisible('bast_print', $row)->assertTableActionVisible('bast_download', $row)
            ->searchTable($h->bast_number)->assertCanSeeTableRecords([$row]);
        $plain = \App\Models\StockMovement::where('stockable_type', \App\Models\Accessory::class)->where('type', 'in')->latest('id')->first();
        Livewire::test(\App\Filament\Pages\StockHistory\AccessoryStockHistory::class)->assertTableActionHidden('bast_print', $plain)
            ->filterTable('origin', 'bast')->assertCanSeeTableRecords([$row])->assertCanNotSeeTableRecords([$plain]);
    }
}
