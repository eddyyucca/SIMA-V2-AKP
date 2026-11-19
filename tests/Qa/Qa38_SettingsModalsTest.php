<?php

namespace Tests\Qa;

use App\Filament\Pages\Settings;
use App\Models\Asset;
use App\Models\Setting;
use Livewire\Livewire;
use Tests\QaTestCase;

/** Halaman Pengaturan: kartu per bagian, tiap bagian diedit di pop-up. */
class Qa38_SettingsModalsTest extends QaTestCase
{
    public function test_page_shows_a_card_and_a_modal_for_every_section(): void
    {
        $this->as($this->admin());
        $html = $this->get(Settings::getUrl())->assertOk()->getContent();
        foreach (['Kode aset', 'Penomoran Accessories, Consumable', 'Dokumen BAST', 'Kolom spesifikasi Laptop'] as $t) $this->assertStringContainsString($t, $html);
        foreach (['set-kode', 'set-penomoran', 'set-umur', 'set-bast', 'set-spesifikasi'] as $id) $this->assertStringContainsString($id, $html);
        $this->assertSame(['kode', 'penomoran', 'umur', 'bast', 'spesifikasi'], array_column(Livewire::test(Settings::class)->instance()->sections(), 'id'));
        $this->assertSame(substr_count($html, 'class="set-card"'), 5);
        $this->assertStringContainsString('Atur', $html);
    }

    public function test_every_section_has_its_view_and_save_method(): void
    {
        $this->as($this->admin());
        $page = Livewire::test(Settings::class)->instance();
        foreach ($page->sections() as $s) {
            $this->assertTrue(view()->exists('filament.pages.settings.' . $s['view']), $s['id']);
            $this->assertTrue(method_exists($page, $s['save']), $s['id']);
            if (! empty($s['reset'])) $this->assertTrue(method_exists($page, $s['reset']), $s['id']);
            $this->assertNotSame('', $s['summary']);
        }
    }

    public function test_saving_closes_only_its_own_modal_and_errors_keep_it_open(): void
    {
        $this->as($this->admin());
        Livewire::test(Settings::class)->set('prefix', 'akp-x')->call('save')->assertDispatched('close-modal', id: 'set-kode')->assertNotDispatched('close-modal', id: 'set-bast');
        Livewire::test(Settings::class)->set('prefix', 'tidak valid!')->call('save')->assertHasErrors(['prefix'])->assertNotDispatched('close-modal');
        Livewire::test(Settings::class)->set('codes.accessory', 'XA')->set('codes.consumable', 'XC')->set('codes.component', 'XK')->call('saveCodes')->assertDispatched('close-modal', id: 'set-penomoran');
        Livewire::test(Settings::class)->set('bast.bast_company', '')->call('saveBast')->assertHasErrors()->assertNotDispatched('close-modal');
        Livewire::test(Settings::class)->set('bast.bast_acc_known', 'NAMA BARU')->call('saveBast')->assertDispatched('close-modal', id: 'set-bast');
        $this->assertSame('NAMA BARU', Setting::get('bast_acc_known'));
        $c = Livewire::test(Settings::class);
        $f = $c->get('specFields'); $f[] = ['key' => '', 'label' => 'Serial BIOS', 'hint' => ''];
        $c->set('specFields', $f)->call('saveSpecFields')->assertDispatched('close-modal', id: 'set-spesifikasi');
        $c->set('specFields', [])->call('saveSpecFields')->assertHasErrors()->assertNotDispatched('close-modal', id: 'set-spesifikasi');
        $this->assertArrayHasKey('serial_bios', Asset::specFields());
    }

    public function test_closing_a_modal_without_saving_restores_saved_values(): void
    {
        $this->as($this->admin());
        $saved = Asset::tagPrefix();
        Livewire::test(Settings::class)->set('prefix', 'BELUM-DISIMPAN')->set('bast.bast_company', 'XXX')->set('specFields', [])->set('codes.accessory', 'ZZ')
            ->call('reloadSettings')->assertSet('prefix', $saved)->assertSet('bast.bast_company', Setting::get('bast_company', 'PT. ADHI KARTIKO PRATAMA TBK'))
            ->assertSet('codes.accessory', \App\Support\CodePrefix::get('accessory'))->assertCount('specFields', count(Asset::specFields()));
        $this->assertSame($saved, Asset::tagPrefix());                                   // tidak ada yang tersimpan
    }

    public function test_only_users_with_master_permission_can_open_settings(): void
    {
        $this->as($this->staff())->get(Settings::getUrl())->assertForbidden();
        $this->as($this->mgmt())->get(Settings::getUrl())->assertForbidden();
        $this->as($this->admin())->get(Settings::getUrl())->assertOk();
    }
}
