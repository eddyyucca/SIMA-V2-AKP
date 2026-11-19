<?php

namespace Tests\Qa;

use App\Filament\Resources\Assets\Pages\CreateAsset;
use App\Filament\Resources\Assets\Pages\EditAsset;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Models\Asset;
use App\Models\Category;
use Livewire\Livewire;
use Tests\QaTestCase;

/** Format spesifikasi laptop: name, cpu, ram, hdd, vga, motherboard, os (tanpa maceth & macwifi). */
class Qa33_LaptopSpecsTest extends QaTestCase
{
    private function newAsset(string $prefix, array $extra = []): array
    {
        $cat = Category::where('code_prefix', $prefix)->firstOrFail();
        return ['category_id' => $cat->id, 'asset_tag' => Asset::generateTag($cat), 'condition' => 'good'] + $extra;
    }

    public function test_the_laptop_spec_format_has_exactly_the_seven_requested_fields(): void
    {
        $this->assertSame(['name', 'cpu', 'ram', 'hdd', 'vga', 'motherboard', 'os'], array_keys(Asset::SPEC_FIELDS));
        $this->assertArrayNotHasKey('maceth', Asset::SPEC_FIELDS);
        $this->assertArrayNotHasKey('macwifi', Asset::SPEC_FIELDS);
        $this->assertSame(['Name', 'CPU', 'RAM', 'HDD', 'VGA', 'Motherboard', 'OS'], array_column(Asset::SPEC_FIELDS, 0));
    }

    public function test_spec_fields_appear_only_for_laptop_and_desktop_category(): void
    {
        $this->as($this->staff());
        $laptop = Livewire::test(CreateAsset::class)->fillForm($this->newAsset('NB'));
        foreach (array_keys(Asset::SPEC_FIELDS) as $k) $laptop->assertFormFieldVisible("spec_data.$k");
        $laptop->assertFormFieldDoesNotExist('spec_data.maceth')->assertFormFieldDoesNotExist('spec_data.macwifi');

        $pc = Livewire::test(CreateAsset::class)->fillForm($this->newAsset('PC'));
        foreach (array_keys(Asset::SPEC_FIELDS) as $k) $pc->assertFormFieldVisible("spec_data.$k");

        $monitor = Livewire::test(CreateAsset::class)->fillForm($this->newAsset('MNT'));
        foreach (array_keys(Asset::SPEC_FIELDS) as $k) $monitor->assertFormFieldHidden("spec_data.$k");
    }

    public function test_laptop_specs_are_saved_cleaned_and_shown_on_the_detail_page(): void
    {
        $this->as($this->staff());
        $spec = ['name' => '  LPT-AKP-014 ', 'cpu' => 'Intel Core i5-1235U', 'ram' => '16 GB DDR4', 'hdd' => 'SSD 512 GB', 'vga' => '', 'motherboard' => 'Lenovo 21DM', 'os' => 'Windows 11 Pro'];
        $data = $this->newAsset('NB', ['spec_data' => $spec]);
        Livewire::test(CreateAsset::class)->fillForm($data)->call('create')->assertHasNoFormErrors();
        $a = Asset::where('asset_tag', $data['asset_tag'])->firstOrFail();
        $this->assertSame(['name' => 'LPT-AKP-014', 'cpu' => 'Intel Core i5-1235U', 'ram' => '16 GB DDR4', 'hdd' => 'SSD 512 GB', 'motherboard' => 'Lenovo 21DM', 'os' => 'Windows 11 Pro'], $a->spec_data);   // dirapikan, kosong dibuang

        $html = $this->get(\App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $a]))->assertOk()->getContent();
        foreach (['Spesifikasi', 'Intel Core i5-1235U', '16 GB DDR4', 'Lenovo 21DM', 'Windows 11 Pro', 'LPT-AKP-014'] as $needle) $this->assertStringContainsString($needle, $html);
        $this->assertStringNotContainsString('maceth', strtolower($html));
        $this->assertStringNotContainsString('macwifi', strtolower($html));
    }

    public function test_unknown_keys_are_dropped_and_all_empty_becomes_null(): void
    {
        $this->as($this->staff());
        $cat = Category::where('code_prefix', 'NB')->firstOrFail();
        $a = Asset::create(['asset_tag' => Asset::generateTag($cat), 'category_id' => $cat->id, 'status' => 'available', 'condition' => 'good',
            'spec_data' => ['cpu' => 'i7', 'maceth' => 'AA:BB', 'macwifi' => 'CC:DD', 'ram' => str_repeat('x', 400)]]);
        $this->assertSame(['cpu', 'ram'], array_keys($a->fresh()->spec_data));
        $this->assertSame(255, mb_strlen($a->fresh()->spec_data['ram']));
        $a->update(['spec_data' => ['cpu' => '  ', 'ram' => '']]);
        $this->assertNull($a->fresh()->spec_data);
    }

    public function test_editing_keeps_specs_text_and_component_upgrade_history_untouched(): void
    {
        $this->as($this->staff());
        $a = Asset::whereHas('category', fn ($q) => $q->where('code_prefix', 'NB'))->firstOrFail();
        $a->update(['specs' => "Catatan lama: RAM ditambah\n[01/11/2026] Upgrade: pasang SSD", 'spec_data' => null]);
        Livewire::test(EditAsset::class, ['record' => $a->id])->fillForm(['spec_data' => ['cpu' => 'Ryzen 5', 'os' => 'Windows 11']])->call('save')->assertHasNoFormErrors();
        $a->refresh();
        $this->assertSame(['cpu' => 'Ryzen 5', 'os' => 'Windows 11'], $a->spec_data);
        $this->assertStringContainsString('[01/11/2026] Upgrade: pasang SSD', $a->specs);          // riwayat upgrade tidak tertimpa
        // isian terstruktur terisi kembali saat form ubah dibuka
        Livewire::test(EditAsset::class, ['record' => $a->id])->assertFormSet(['spec_data.cpu' => 'Ryzen 5', 'spec_data.os' => 'Windows 11']);
    }

    public function test_rental_laptop_form_has_the_same_spec_fields(): void
    {
        $this->as($this->staff());
        $t = Livewire::test(\App\Filament\Resources\RentalAssets\Pages\CreateRentalAsset::class)->fillForm($this->newAsset('NB'));
        $t->assertFormFieldVisible('spec_data.cpu')->assertFormFieldVisible('spec_data.os');
    }

    public function test_admin_can_rename_add_remove_and_reset_spec_columns_in_settings(): void
    {
        $this->as($this->admin());
        $c = Livewire::test(\App\Filament\Pages\Settings::class);
        $fields = $c->get('specFields');
        $this->assertSame(['name', 'cpu', 'ram', 'hdd', 'vga', 'motherboard', 'os'], array_column($fields, 'key'));

        $fields[2]['label'] = 'Memori';            // ganti nama: kunci tetap
        $fields = array_values(array_filter($fields, fn ($f) => $f['key'] !== 'vga'));
        $fields[] = ['key' => '', 'label' => 'Serial Number BIOS', 'hint' => ''];
        $c->set('specFields', $fields)->call('saveSpecFields')->assertHasNoErrors();

        $keys = array_keys(Asset::specFields());
        $this->assertSame(['name', 'cpu', 'ram', 'hdd', 'motherboard', 'os', 'serial_number_bios'], $keys);
        $this->assertSame('Memori', Asset::specFields()['ram'][0]);

        $cat = Category::where('code_prefix', 'PC')->firstOrFail();
        $form = Livewire::test(CreateAsset::class)->fillForm(['category_id' => $cat->id, 'asset_tag' => Asset::generateTag($cat), 'condition' => 'good']);
        $form->assertFormFieldVisible('spec_data.serial_number_bios')->assertFormFieldDoesNotExist('spec_data.vga');
        $this->assertSame(['ram' => '8 GB', 'serial_number_bios' => 'X1'], Asset::cleanSpecData(['ram' => '8 GB', 'vga' => 'x', 'serial_number_bios' => 'X1']));

        Livewire::test(\App\Filament\Pages\Settings::class)->set('specFields', [['key' => 'a', 'label' => 'Sama', 'hint' => ''], ['key' => 'b', 'label' => 'sama', 'hint' => '']])
            ->call('saveSpecFields')->assertHasErrors();
        Livewire::test(\App\Filament\Pages\Settings::class)->set('specFields', [])->call('saveSpecFields')->assertHasErrors();

        Livewire::test(\App\Filament\Pages\Settings::class)->call('resetSpecFields');
        $this->assertSame(array_keys(Asset::SPEC_FIELDS), array_keys(Asset::specFields()));
    }
}
