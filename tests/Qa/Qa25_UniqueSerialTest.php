<?php

namespace Tests\Qa;

use App\Filament\Resources\Assets\Pages\CreateAsset;
use App\Filament\Resources\Assets\Pages\EditAsset;
use App\Filament\Resources\Components\Pages\ManageComponents;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Component;
use Livewire\Livewire;
use Tests\QaTestCase;

class Qa25_UniqueSerialTest extends QaTestCase
{
    private function newAsset(array $d = []): array
    {
        $cat = Category::where('code_prefix', 'NB')->firstOrFail();
        return ['category_id' => $cat->id, 'asset_tag' => Asset::generateTag($cat), 'condition' => 'good'] + $d;
    }

    public function test_duplicate_asset_serial_is_rejected_with_notification_naming_the_owner(): void
    {
        $this->as($this->staff());
        $first = Asset::whereNotNull('serial_number')->firstOrFail();

        Livewire::test(CreateAsset::class)->fillForm($this->newAsset(['serial_number' => $first->serial_number]))
            ->call('create')->assertHasFormErrors(['serial_number'])->assertNotified('Serial number duplikat');
        // huruf kecil dan spasi di ujung dianggap sama
        Livewire::test(CreateAsset::class)->fillForm($this->newAsset(['serial_number' => '  ' . strtolower($first->serial_number) . ' ']))
            ->call('create')->assertHasFormErrors(['serial_number']);
        $this->assertSame(1, Asset::withTrashed()->where('serial_number', $first->serial_number)->count());
        $this->assertStringContainsString($first->asset_tag, \App\Support\SerialNumber::holder($first->serial_number));
    }

    public function test_editing_an_asset_keeps_its_own_serial_but_cannot_take_another(): void
    {
        $this->as($this->staff());
        [$a, $b] = Asset::whereNotNull('serial_number')->orderBy('id')->take(2)->get()->all();
        Livewire::test(EditAsset::class, ['record' => $a->id])->fillForm(['serial_number' => $a->serial_number, 'notes' => 'tetap'])->call('save')->assertHasNoFormErrors();
        Livewire::test(EditAsset::class, ['record' => $a->id])->fillForm(['serial_number' => $b->serial_number])->call('save')->assertHasFormErrors(['serial_number'])->assertNotified('Serial number duplikat');
        $this->assertNotSame($b->serial_number, $a->fresh()->serial_number);
    }

    public function test_serial_is_unique_across_assets_and_components_in_both_directions(): void
    {
        $this->as($this->staff());
        $asset = Asset::whereNotNull('serial_number')->firstOrFail();

        // komponen memakai serial aset -> ditolak
        Livewire::test(ManageComponents::class)->callAction('create', ['name' => 'RAM QA', 'serial_number' => $asset->serial_number, 'qty_total' => 1, 'min_qty' => 0])
            ->assertHasActionErrors(['serial_number'])->assertNotified('Serial number duplikat');
        $this->assertSame(0, Component::where('name', 'RAM QA')->count());

        // komponen baru dengan serial bebas -> boleh
        Livewire::test(ManageComponents::class)->callAction('create', ['name' => 'SSD QA', 'serial_number' => 'CMP-SN-QA-1', 'qty_total' => 1, 'min_qty' => 0])->assertHasNoActionErrors();
        $this->assertSame('CMP-SN-QA-1', Component::where('name', 'SSD QA')->firstOrFail()->serial_number);
        // komponen kedua dengan serial sama -> ditolak
        Livewire::test(ManageComponents::class)->callAction('create', ['name' => 'SSD QA 2', 'serial_number' => 'cmp-sn-qa-1', 'qty_total' => 1, 'min_qty' => 0])->assertHasActionErrors(['serial_number']);
        // aset memakai serial komponen -> ditolak
        Livewire::test(CreateAsset::class)->fillForm($this->newAsset(['serial_number' => 'CMP-SN-QA-1']))->call('create')->assertHasFormErrors(['serial_number']);
    }

    public function test_deleted_asset_still_reserves_its_serial(): void
    {
        $this->as($this->staff());
        $a = Asset::whereNotNull('serial_number')->whereDoesntHave('assignments')->orderBy('id')->firstOrFail();
        $sn = $a->serial_number;
        $a->delete();
        $this->assertStringContainsString('sudah dihapus', \App\Support\SerialNumber::holder($sn));
        Livewire::test(CreateAsset::class)->fillForm($this->newAsset(['serial_number' => $sn]))->call('create')->assertHasFormErrors(['serial_number']);
    }

    public function test_model_layer_blocks_duplicates_from_other_code_paths_and_blank_becomes_null(): void
    {
        $a = Asset::whereNotNull('serial_number')->firstOrFail();
        $cat = Category::where('code_prefix', 'NB')->firstOrFail();
        try {
            Asset::create(['asset_tag' => Asset::generateTag($cat), 'category_id' => $cat->id, 'serial_number' => ' ' . $a->serial_number, 'status' => 'available', 'condition' => 'good']);
            $this->fail('Duplikat seharusnya ditolak');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('sudah dipakai', $e->getMessage());
        }
        // dua aset tanpa serial (kosong) tetap boleh
        $x = Asset::create(['asset_tag' => Asset::generateTag($cat), 'category_id' => $cat->id, 'serial_number' => '  ', 'status' => 'available', 'condition' => 'good']);
        $y = Asset::create(['asset_tag' => Asset::generateTag($cat), 'category_id' => $cat->id, 'serial_number' => '', 'status' => 'available', 'condition' => 'good']);
        $this->assertNull($x->fresh()->serial_number);
        $this->assertNull($y->fresh()->serial_number);
    }
}
