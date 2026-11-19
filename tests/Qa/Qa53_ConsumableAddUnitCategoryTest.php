<?php

namespace Tests\Qa;

use App\Filament\Resources\Consumables\Pages\ManageConsumables;
use App\Models\Category;
use App\Models\Consumable;
use Filament\Actions\CreateAction;
use Livewire\Livewire;
use Tests\QaTestCase;

/** Form Consumable: satuan dan kategori baru bisa ditambah langsung dari form. */
class Qa53_ConsumableAddUnitCategoryTest extends QaTestCase
{
    public function test_add_unit_is_deduplicated_and_persisted(): void
    {
        $this->as($this->staff());
        $this->assertSame('botol', Consumable::addUnit('  Botol '));
        $this->assertSame('botol', Consumable::addUnit('BOTOL'));
        $this->assertSame('pcs', Consumable::addUnit('PCS'));
        $this->assertArrayHasKey('botol', Consumable::unitOptions());
        $this->assertSame(1, count(array_keys(Consumable::unitOptions(), 'botol')));
    }

    public function test_create_consumable_with_new_unit_and_category(): void
    {
        $this->as($this->staff());
        $cat = Category::create(['name' => 'Cairan QA53', 'type' => 'consumable']);
        Livewire::test(ManageConsumables::class)
            ->callAction(CreateAction::class, ['name' => 'Tinta Botol QA53', 'unit' => Consumable::addUnit('botol'), 'category_id' => $cat->id, 'qty' => 0, 'min_qty' => 0, 'unit_price' => 0])
            ->assertHasNoActionErrors();
        $c = Consumable::where('name', 'Tinta Botol QA53')->firstOrFail();
        $this->assertSame('botol', $c->unit);
        $this->assertSame($cat->id, $c->category_id);
    }
}
