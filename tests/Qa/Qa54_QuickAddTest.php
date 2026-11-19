<?php

namespace Tests\Qa;

use App\Filament\Resources\Accessories\Pages\ManageAccessories;
use App\Filament\Resources\Components\Pages\ManageComponents;
use App\Filament\Resources\Consumables\Pages\ManageConsumables;
use App\Models\Category;
use App\Models\Location;
use App\Models\Vendor;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\QaTestCase;

/** Tombol (+) di dropdown form: Admin bisa menambah master langsung; Staff IT tidak (izin master tetap dijaga). */
class Qa54_QuickAddTest extends QaTestCase
{
    public static function pages(): array
    {
        return [[ManageAccessories::class, 'accessory'], [ManageConsumables::class, 'consumable'], [ManageComponents::class, 'component']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_admin_can_add_category_location_vendor_from_the_form(string $page, string $type): void
    {
        $this->as($this->admin());
        $t = Livewire::test($page)->mountAction(CreateAction::class);
        foreach (['category_id' => ['Kategori Baru QA54', Category::class, $type], 'location_id' => ['Lokasi Baru QA54', Location::class, null], 'vendor_id' => ['Vendor Baru QA54', Vendor::class, null]] as $field => [$name, $model]) {
            $t->callAction(TestAction::make('createOption')->schemaComponent("$field"), ['name' => $name])->assertHasNoActionErrors();
            $this->assertTrue($model::where('name', $name)->exists(), "$field tersimpan");
        }
        $this->assertSame($type, Category::where('name', 'Kategori Baru QA54')->value('type'));
    }

    public function test_duplicate_is_rejected_and_staff_does_not_get_the_button(): void
    {
        $this->as($this->admin());
        Category::create(['name' => 'Sudah Ada QA54', 'type' => 'accessory']);
        Livewire::test(ManageAccessories::class)->mountAction(CreateAction::class)
            ->callAction(TestAction::make('createOption')->schemaComponent('category_id'), ['name' => 'Sudah Ada QA54'])->assertHasActionErrors(['name']);
        $this->assertSame(1, Category::where('name', 'Sudah Ada QA54')->count());

        $this->as($this->staff());
        $this->assertFalse(auth()->user()->can('create', Category::class));
        Livewire::test(ManageAccessories::class)->mountAction(CreateAction::class)
            ->assertActionHidden(TestAction::make('createOption')->schemaComponent('category_id'));
    }
}
