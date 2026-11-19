<?php

namespace Tests\Qa;

use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Assets\Pages\CreateAsset;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Filament\Resources\RentalAssets\Pages\CreateRentalAsset;
use App\Filament\Resources\RentalAssets\Pages\ListRentalAssets;
use App\Filament\Resources\RentalAssets\RentalAssetResource;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Vendor;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\QaTestCase;

class Qa10_RentalMenuTest extends QaTestCase
{
    public function test_rental_has_its_own_menu_button_in_inventaris_next_to_assets(): void
    {
        $this->assertSame('Inventaris', (string) RentalAssetResource::getNavigationGroup());
        $this->assertSame('Aset Sewa (Rental)', RentalAssetResource::getNavigationLabel());
        $this->assertSame('Aset', AssetResource::getNavigationLabel());
        $this->assertLessThan(\App\Filament\Resources\Accessories\AccessoryResource::getNavigationSort(), RentalAssetResource::getNavigationSort());
        $this->as($this->staff());
        $this->assertSame('/admin/aset-sewa', parse_url(RentalAssetResource::getUrl(), PHP_URL_PATH));
        $this->get('/admin/aset-sewa')->assertOk()->assertSee('Aset Sewa (Rental)')->assertSee('Tambah Aset Sewa');
        // tombol menu tampil di sidebar semua halaman bagi staff
        $this->get('/admin/assets')->assertOk()->assertSee('Aset Sewa (Rental)');
        $this->get('/admin')->assertOk()->assertSee('aset-sewa');                               // tombol di dashboard juga
    }

    public function test_main_asset_list_has_only_owned_assets_and_rental_list_only_rentals(): void
    {
        $this->as($this->staff());
        $owned = Asset::where('ownership', 'owned')->get();
        $rent = Asset::where('ownership', 'rental')->get();
        $this->assertGreaterThan(0, $rent->count());

        Livewire::test(ListAssets::class)->assertCanNotSeeTableRecords($rent)->assertCountTableRecords($owned->count());
        $active = $rent->where('status', '!=', 'returned');
        Livewire::test(ListRentalAssets::class)->assertCanSeeTableRecords($active)->assertCanNotSeeTableRecords($owned->take(10))->assertCountTableRecords($active->count());
        // aset yang sudah dikembalikan muncul saat filter "masih berjalan" dimatikan
        Livewire::test(ListRentalAssets::class)->removeTableFilter('active')->assertCountTableRecords($rent->count());
    }

    public function test_rental_list_filters_search_and_sorting(): void
    {
        $this->as($this->staff());
        $vendor = Vendor::where('name', 'PT Rental Teknologi Nusantara')->firstOrFail();
        $mine = Asset::where('ownership', 'rental')->where('vendor_id', $vendor->id)->where('status', '!=', 'returned')->get();
        Livewire::test(ListRentalAssets::class)->filterTable('vendor_id', $vendor->id)->assertCountTableRecords($mine->count());

        $overdue = Asset::where('ownership', 'rental')->where('status', '!=', 'returned')->whereDate('rental_end', '<', now()->toDateString())->get();
        $this->assertGreaterThanOrEqual(1, $overdue->count());
        Livewire::test(ListRentalAssets::class)->filterTable('overdue', true)->assertCanSeeTableRecords($overdue)->assertCountTableRecords($overdue->count());

        $soon = Asset::where('ownership', 'rental')->where('status', '!=', 'returned')->whereDate('rental_end', '<=', now()->addDays(30)->toDateString())->get();
        Livewire::test(ListRentalAssets::class)->filterTable('expiring', true)->assertCountTableRecords($soon->count());

        $a = $mine->first();
        Livewire::test(ListRentalAssets::class)->searchTable($a->rental_contract_no)->assertCanSeeTableRecords([$a]);
        Livewire::test(ListRentalAssets::class)->searchTable($a->serial_number)->assertCanSeeTableRecords([$a]);

        $sorted = Asset::where('ownership', 'rental')->where('status', '!=', 'returned')->orderBy('rental_end')->get();
        Livewire::test(ListRentalAssets::class)->sortTable('rental_end')->assertCanSeeTableRecords($sorted, inOrder: true);
    }

    public function test_rental_list_shows_total_monthly_cost_and_summary_header(): void
    {
        $this->as($this->staff());
        $q = Asset::where('ownership', 'rental')->where('status', '!=', 'returned');
        $page = Livewire::test(ListRentalAssets::class);
        $sub = $page->instance()->getSubheading();
        $this->assertStringContainsString($q->count() . ' aset sewa berjalan', $sub);
        $this->assertStringContainsString(number_format((float) $q->sum('rental_monthly_cost'), 0, ',', '.'), $sub);
        $this->assertStringContainsString('segera berakhir', $sub);
        $this->get('/admin/aset-sewa')->assertOk()->assertSee('Total');                        // ringkasan kolom biaya
    }

    public function test_rows_link_to_the_shared_detail_and_edit_pages(): void
    {
        $this->as($this->staff());
        $a = Asset::where('ownership', 'rental')->where('status', '!=', 'returned')->firstOrFail();
        $page = Livewire::test(ListRentalAssets::class);
        $page->assertActionVisible(TestAction::make('view')->table($a))->assertActionVisible(TestAction::make('edit')->table($a));
        $this->get(AssetResource::getUrl('view', ['record' => $a]))->assertOk()->assertSee('Aset Sewa (Rental)');
        $this->get(AssetResource::getUrl('edit', ['record' => $a]))->assertOk()->assertSee('Sewa (Rental)')->assertSee('Kepemilikan');
    }

    public function test_add_rental_button_shows_only_rental_fields_and_forces_rental(): void
    {
        $this->as($this->staff());
        $this->get('/admin/aset-sewa/create')->assertOk()->assertSee('Mulai sewa')->assertSee('Sewa berakhir')->assertSee('Biaya sewa per bulan')
            ->assertDontSee('Harga beli')->assertDontSee('Tanggal beli');
        $this->get('/admin/assets/create')->assertOk()->assertSee('Harga beli')->assertDontSee('Mulai sewa')->assertDontSee('Biaya sewa per bulan')
            ->assertDontSee('Kepemilikan');                                                      // pilihan kepemilikan tidak ada saat tambah

        $cat = Category::where('code_prefix', 'NB')->firstOrFail();
        $vendor = Vendor::where('name', 'PT Rental Teknologi Nusantara')->firstOrFail();
        $tag = Asset::generateTag($cat);
        $t = Livewire::test(CreateRentalAsset::class)->fillForm([
            'category_id' => $cat->id, 'asset_tag' => $tag, 'condition' => 'good', 'vendor_id' => $vendor->id, 'rental_contract_no' => 'KTR-MENU-1',
            'rental_start' => '2026-02-01', 'rental_end' => '2028-01-31', 'rental_monthly_cost' => 900000,
        ])->call('create')->assertHasNoFormErrors();
        $a = Asset::where('asset_tag', $tag)->firstOrFail();
        $this->assertSame('rental', $a->ownership);                                              // otomatis sewa, tanpa memilih kepemilikan
        $t->assertRedirect(\App\Filament\Resources\RentalAssets\RentalAssetResource::getUrl('view', ['record' => $a]));

        // tombol Aset biasa selalu membuat aset milik sendiri
        $tag2 = Asset::generateTag($cat);
        Livewire::test(CreateAsset::class)->fillForm(['category_id' => $cat->id, 'asset_tag' => $tag2, 'condition' => 'good', 'purchase_price' => 1000000])->call('create')->assertHasNoFormErrors();
        $this->assertSame('owned', Asset::where('asset_tag', $tag2)->value('ownership'));
        // aset sewa baru tampil di daftar sewa dan tidak di daftar aset
        Livewire::test(ListRentalAssets::class)->assertCanSeeTableRecords([$a]);
        Livewire::test(ListAssets::class)->assertCanNotSeeTableRecords([$a]);
    }

    public function test_permissions_for_the_rental_menu(): void
    {
        $this->as($this->mgmt());
        $this->get('/admin/aset-sewa')->assertOk();
        Livewire::test(ListRentalAssets::class)->assertActionHidden('create');
        $this->get('/admin/aset-sewa/create')->assertForbidden();
        $this->as($this->plain())->get('/admin/aset-sewa')->assertForbidden();
        $this->as($this->admin())->get('/admin/aset-sewa')->assertOk();
    }

    public function test_rental_assets_are_not_in_global_search_resource_but_still_findable_by_scan(): void
    {
        $this->assertFalse(RentalAssetResource::canGloballySearch());
        $this->as($this->staff());
        $a = Asset::where('ownership', 'rental')->firstOrFail();
        \Livewire\Livewire::test(\App\Filament\Pages\ScanPage::class)->call('onScan', $a->asset_tag)->assertNoRedirect()->assertSet('card.url', AssetResource::getUrl('view', ['record' => $a]));
    }

    public function test_returning_a_rental_to_the_vendor_moves_it_out_of_the_running_list(): void
    {
        $this->as($this->staff());
        $a = Asset::where('ownership', 'rental')->where('status', 'available')->firstOrFail();
        Livewire::test(\App\Filament\Resources\Assets\Pages\ViewAsset::class, ['record' => $a->getKey()])
            ->callAction('return_vendor', ['returned_at' => now()->toDateString(), 'condition' => 'good', 'data_wiped' => true])->assertHasNoActionErrors();
        Livewire::test(ListRentalAssets::class)->assertCanNotSeeTableRecords([$a]);
        Livewire::test(ListRentalAssets::class)->removeTableFilter('active')->assertCanSeeTableRecords([$a]);
        $this->artisan('inventory:audit')->assertSuccessful();
    }
}
