<?php

namespace Tests\Qa;

use App\Filament\Pages\Settings;
use App\Filament\Resources\Assets\Pages\CreateAsset;
use App\Filament\Resources\Assets\Pages\EditAsset;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Setting;
use Livewire\Livewire;
use Tests\QaTestCase;

/** CPU, RAM, HDD, VGA berupa pilihan (dropdown) yang daftarnya diatur di Pengaturan, dengan isi manual di form aset. */
class Qa39_SpecSelectOptionsTest extends QaTestCase
{
    private function base(string $prefix = 'NB'): array
    {
        $cat = Category::where('code_prefix', $prefix)->firstOrFail();
        return ['category_id' => $cat->id, 'asset_tag' => Asset::generateTag($cat), 'condition' => 'good'];
    }

    public function test_cpu_ram_hdd_vga_are_select_with_default_options_and_others_stay_text(): void
    {
        $f = Asset::specFields();
        foreach (['cpu', 'ram', 'hdd', 'vga'] as $k) {
            $this->assertSame('select', $f[$k][2], $k);
            $this->assertNotEmpty($f[$k][3], $k);
            $this->assertSame(array_values(array_unique($f[$k][3])), $f[$k][3]);
        }
        foreach (['name', 'motherboard', 'os'] as $k) { $this->assertSame('text', $f[$k][2], $k); $this->assertSame([], $f[$k][3], $k); }
        $this->assertContains('16 GB DDR4', $f['ram'][3]);
        $this->assertContains('SSD NVMe 512 GB', $f['hdd'][3]);
    }

    public function test_old_saved_setting_without_type_still_gets_select_for_the_four_fields(): void
    {
        Setting::put('spec_fields', json_encode([['key' => 'cpu', 'label' => 'Prosesor', 'hint' => ''], ['key' => 'name', 'label' => 'Nama', 'hint' => ''], ['key' => 'x', 'label' => 'Lain', 'hint' => '']]));
        $f = Asset::specFields();
        $this->assertSame(['Prosesor', '', 'select'], array_slice($f['cpu'], 0, 3));
        $this->assertContains('Apple M1', $f['cpu'][3]);
        $this->assertSame('text', $f['name'][2]);
        $this->assertSame('text', $f['x'][2]);
    }

    public function test_form_shows_dropdowns_for_laptop_and_pc_and_saves_a_preset_value(): void
    {
        $this->as($this->staff());
        foreach (['NB', 'PC'] as $prefix) {
            $data = $this->base($prefix) + ['spec_data' => ['name' => "HOST-$prefix", 'cpu' => 'Intel Core i5-1235U', 'ram' => '16 GB DDR4', 'hdd' => 'SSD NVMe 512 GB', 'vga' => 'Intel Iris Xe Graphics', 'os' => 'Windows 11 Pro']];
            $c = Livewire::test(CreateAsset::class)->fillForm($data);
            foreach (['cpu', 'ram', 'hdd', 'vga'] as $k) $c->assertFormFieldVisible("spec_data.$k");
            $c->call('create')->assertHasNoFormErrors();
            $a = Asset::where('asset_tag', $data['asset_tag'])->firstOrFail();
            $this->assertSame('16 GB DDR4', $a->spec_data['ram']);
            $this->assertSame('SSD NVMe 512 GB', $a->spec_data['hdd']);
        }
        $this->assertSame('Select', class_basename(\App\Filament\Resources\Assets\AssetResource::specComponent('cpu', Asset::specFields()['cpu'])));
        $this->assertSame('TextInput', class_basename(\App\Filament\Resources\Assets\AssetResource::specComponent('os', Asset::specFields()['os'])));
    }

    public function test_manual_value_is_accepted_and_admin_can_remember_it_in_the_list(): void
    {
        $this->as($this->admin());
        $manual = 'Intel Xeon W-2295 (manual)';
        $this->assertNotContains($manual, Asset::specFields()['cpu'][3]);
        $data = $this->base('PC');
        // 1. nilai manual langsung disimpan walau tidak ada di daftar (tidak ditolak validasi)
        Livewire::test(CreateAsset::class)->fillForm($data + ['spec_data' => ['cpu' => $manual, 'ram' => '128 GB ECC']])->call('create')->assertHasNoFormErrors();
        $a = Asset::where('asset_tag', $data['asset_tag'])->firstOrFail();
        $this->assertSame($manual, $a->spec_data['cpu']);
        $this->assertSame('128 GB ECC', $a->spec_data['ram']);
        $this->assertNotContains($manual, Asset::specFields()['cpu'][3]);                      // manual saja tidak menambah daftar

        // 2. tombol "isi manual" di dropdown: + simpan ke daftar (admin berhak mengelola master)
        $remember = 'AMD Threadripper 3970X';
        $c = Livewire::test(CreateAsset::class)->fillForm($this->base('PC'));
        $c->callFormComponentAction('spec_data.cpu', 'createOption', ['value' => '  ' . $remember . '  ', 'remember' => true]);
        $this->assertContains($remember, Asset::specFields()['cpu'][3]);
        $this->assertSame(1, count(array_keys(Asset::specFields()['cpu'][3], $remember)));
        $c->callFormComponentAction('spec_data.cpu', 'createOption', ['value' => strtolower($remember), 'remember' => true]);   // ganda (beda huruf) tidak ditambah
        $this->assertSame(1, count(array_filter(Asset::specFields()['cpu'][3], fn ($o) => mb_strtolower($o) === mb_strtolower($remember))));
        // kolom lain tidak ikut berubah
        $this->assertSame(Asset::SPEC_OPTIONS['ram'], Asset::specFields()['ram'][3]);
    }

    public function test_staff_manual_entry_is_used_but_never_changes_the_shared_list(): void
    {
        $this->as($this->staff());
        $before = Asset::specFields()['vga'][3];
        $c = Livewire::test(CreateAsset::class)->fillForm($this->base('NB'));
        $c->callFormComponentAction('spec_data.vga', 'createOption', ['value' => 'Matrox G200 (manual)', 'remember' => true]);
        $this->assertSame($before, Asset::specFields()['vga'][3]);                              // staf tanpa hak master: tidak menyimpan ke daftar
    }

    public function test_editing_keeps_a_stored_value_that_is_not_in_the_list(): void
    {
        $this->as($this->staff());
        $data = $this->base('NB');
        Livewire::test(CreateAsset::class)->fillForm($data + ['spec_data' => ['hdd' => 'HDD 320 GB Lama']])->call('create');
        $a = Asset::where('asset_tag', $data['asset_tag'])->firstOrFail();
        Livewire::test(EditAsset::class, ['record' => $a->getKey()])->assertFormSet(['spec_data.hdd' => 'HDD 320 GB Lama'])
            ->fillForm(['spec_data.ram' => '8 GB DDR4'])->call('save')->assertHasNoFormErrors();
        $a->refresh();
        $this->assertSame('HDD 320 GB Lama', $a->spec_data['hdd']);
        $this->assertSame('8 GB DDR4', $a->spec_data['ram']);
        $this->get(\App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $a]))->assertOk()->assertSee('HDD 320 GB Lama');
    }

    public function test_settings_edit_options_type_and_validation(): void
    {
        $this->as($this->admin());
        $c = Livewire::test(Settings::class);
        $rows = $c->get('specFields');
        $this->assertSame('select', $rows[1]['type']);
        $this->assertStringContainsString("16 GB DDR4", $rows[2]['options']);                    // textarea: satu per baris

        // tambah pilihan, hapus yang ganda/kosong, ubah OS jadi pilihan
        $rows[2]['options'] = "8 GB DDR4\n  16 GB DDR4  \n\n16 gb ddr4\n24 GB LPDDR5\n";
        $rows[6]['type'] = 'select'; $rows[6]['options'] = "Windows 10 Pro\nWindows 11 Pro";
        $c->set('specFields', $rows)->call('saveSpecFields')->assertHasNoErrors()->assertDispatched('close-modal', id: 'set-spesifikasi');
        $f = Asset::specFields();
        $this->assertSame(['8 GB DDR4', '16 GB DDR4', '24 GB LPDDR5'], $f['ram'][3]);
        $this->assertSame(['select', ['Windows 10 Pro', 'Windows 11 Pro']], [$f['os'][2], $f['os'][3]]);
        $this->assertSame('Select', class_basename(\App\Filament\Resources\Assets\AssetResource::specComponent('os', $f['os'])));

        // ubah CPU kembali jadi teks; daftar tetap tersimpan kalau dijadikan pilihan lagi
        $rows = Livewire::test(Settings::class)->get('specFields');
        $rows[1]['type'] = 'text';
        Livewire::test(Settings::class)->set('specFields', $rows)->call('saveSpecFields');
        $this->assertSame('text', Asset::specFields()['cpu'][2]);

        // validasi
        $bad = Livewire::test(Settings::class)->get('specFields');
        $bad[2]['options'] = str_repeat('x', 101);
        Livewire::test(Settings::class)->set('specFields', $bad)->call('saveSpecFields')->assertHasErrors(['specFields.2.options'])->assertNotDispatched('close-modal');
        $bad[2]['options'] = implode("\n", array_map(fn ($i) => "opsi $i", range(1, 201)));
        Livewire::test(Settings::class)->set('specFields', $bad)->call('saveSpecFields')->assertHasErrors(['specFields.2.options']);
        $bad[2]['options'] = "ok"; $bad[2]['type'] = 'bebas';
        Livewire::test(Settings::class)->set('specFields', $bad)->call('saveSpecFields')->assertHasNoErrors();   // jenis tak dikenal dirapikan jadi Teks
        $this->assertSame('text', Asset::specFields()['ram'][2]);

        // kembalikan bawaan -> kembali ke pilihan awal
        Livewire::test(Settings::class)->call('resetSpecFields');
        $f = Asset::specFields();
        $this->assertSame(Asset::SPEC_OPTIONS['ram'], $f['ram'][3]);
        $this->assertSame('select', $f['cpu'][2]);
        $this->assertSame('text', $f['os'][2]);
    }

    public function test_adding_a_new_select_column_in_settings_shows_it_as_a_dropdown_in_the_form(): void
    {
        $this->as($this->admin());
        $c = Livewire::test(Settings::class);
        $rows = $c->get('specFields');
        $rows[] = ['key' => '', 'label' => 'Ukuran Layar', 'hint' => '', 'type' => 'select', 'options' => "13 inch\n14 inch\n15.6 inch"];
        $c->set('specFields', $rows)->call('saveSpecFields')->assertHasNoErrors();
        $this->assertSame(['13 inch', '14 inch', '15.6 inch'], Asset::specFields()['ukuran_layar'][3]);
        $data = $this->base('NB');
        Livewire::test(CreateAsset::class)->fillForm($data + ['spec_data' => ['ukuran_layar' => '14 inch']])->assertFormFieldVisible('spec_data.ukuran_layar')->call('create')->assertHasNoFormErrors();
        $this->assertSame('14 inch', Asset::where('asset_tag', $data['asset_tag'])->first()->spec_data['ukuran_layar']);
    }

    public function test_add_spec_option_helper_rules(): void
    {
        $this->assertTrue(Asset::addSpecOption('ram', '48 GB DDR5'));
        $this->assertFalse(Asset::addSpecOption('ram', '48 gb ddr5'));       // ganda
        $this->assertFalse(Asset::addSpecOption('ram', '   '));              // kosong
        $this->assertFalse(Asset::addSpecOption('os', 'Windows 12'));        // kolom teks
        $this->assertFalse(Asset::addSpecOption('tidak_ada', 'x'));          // kolom tidak ada
        $this->assertContains('48 GB DDR5', Asset::specFields()['ram'][3]);
        $this->assertSame(['name', 'cpu', 'ram', 'hdd', 'vga', 'motherboard', 'os'], array_keys(Asset::specFields()));   // kolom lain utuh
    }
}
