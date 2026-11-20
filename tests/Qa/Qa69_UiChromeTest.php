<?php

namespace Tests\Qa;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Employees\EmployeeResource;
use Tests\QaTestCase;

/** Elemen UI global: tombol "Kembali" (kecuali Dashboard) dan bilah loading. */
class Qa69_UiChromeTest extends QaTestCase
{
    public function test_back_button_is_on_every_inner_page_but_not_on_the_dashboard(): void
    {
        $this->as($this->admin());
        $asset = \App\Models\Asset::firstOrFail();
        foreach ([AssetResource::getUrl(), AssetResource::getUrl('view', ['record' => $asset]), EmployeeResource::getUrl()] as $url) {
            $this->get($url)->assertOk()->assertSee('class="akp-back"', false)->assertSee('akpBack(', false);
        }
        $this->get(Dashboard::getUrl())->assertOk()->assertDontSee('class="akp-back"', false);
    }

    public function test_loading_bar_is_rendered_on_panel_pages(): void
    {
        $this->as($this->admin());
        $this->get(AssetResource::getUrl())->assertOk()->assertSee('id="akp-loading"', false)->assertSee("Livewire.hook('commit'", false);
        $this->get(Dashboard::getUrl())->assertOk()->assertSee('id="akp-loading"', false);
    }
}
