<?php

namespace Tests\Qa;

use App\Filament\Resources\Assets\Pages\CreateAsset;
use App\Filament\Resources\Assets\Pages\EditAsset;
use App\Models\Asset;
use App\Models\Category;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\QaTestCase;

class Qa14_PoPrNumberTest extends QaTestCase
{
    public function test_asset_purchase_document_number_is_po_pr_not_invoice(): void
    {
        $this->assertTrue(Schema::hasColumn('assets', 'po_pr_no'));
        $this->assertFalse(Schema::hasColumn('assets', 'invoice_no'));
        $this->as($this->staff());
        $this->get('/admin/assets/create')->assertOk()->assertSee('No. PO / PR')->assertDontSee('No. invoice');
        $a = Asset::where('ownership', 'owned')->orderBy('id')->firstOrFail();
        $this->get(\App\Filament\Resources\Assets\AssetResource::getUrl('edit', ['record' => $a]))->assertOk()->assertSee('No. PO / PR')->assertDontSee('No. invoice');
    }

    public function test_po_pr_number_is_saved_edited_and_shown_on_the_detail_page(): void
    {
        $this->as($this->staff());
        $cat = Category::where('code_prefix', 'NB')->firstOrFail();
        $tag = Asset::generateTag($cat);
        Livewire::test(CreateAsset::class)->fillForm(['category_id' => $cat->id, 'asset_tag' => $tag, 'condition' => 'good', 'purchase_price' => 5000000, 'po_pr_no' => 'PO-2026/IT/045'])
            ->call('create')->assertHasNoFormErrors();
        $a = Asset::where('asset_tag', $tag)->firstOrFail();
        $this->assertSame('PO-2026/IT/045', $a->po_pr_no);
        $this->get(\App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $a]))->assertOk()->assertSee('PO-2026/IT/045')->assertSee('No. PO/PR')->assertDontSee('Vendor / Invoice');

        Livewire::test(EditAsset::class, ['record' => $a->getKey()])->fillForm(['po_pr_no' => 'PR-2026/IT/012'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('PR-2026/IT/012', $a->fresh()->po_pr_no);
        // batas panjang 100 karakter
        Livewire::test(EditAsset::class, ['record' => $a->getKey()])->fillForm(['po_pr_no' => str_repeat('X', 101)])->call('save')->assertHasFormErrors(['po_pr_no']);
        // boleh dikosongkan
        Livewire::test(EditAsset::class, ['record' => $a->getKey()])->fillForm(['po_pr_no' => ''])->call('save')->assertHasNoFormErrors();
    }

    public function test_demo_assets_use_po_numbers_and_the_change_is_audited(): void
    {
        $a = Asset::where('ownership', 'owned')->whereNotNull('po_pr_no')->get();
        $this->assertGreaterThan(20, $a->count());
        foreach ($a as $x) $this->assertStringStartsWith('PO/', $x->po_pr_no);
        $this->as($this->staff());
        $asset = $a->first();
        Livewire::test(EditAsset::class, ['record' => $asset->getKey()])->fillForm(['po_pr_no' => 'PO/2026/99/9999'])->call('save');
        $log = \Spatie\Activitylog\Models\Activity::where('subject_type', Asset::class)->where('subject_id', $asset->id)->latest('id')->first();
        $this->assertSame('PO/2026/99/9999', $log->attribute_changes['attributes']['po_pr_no'] ?? $log->properties['attributes']['po_pr_no'] ?? null);
    }
}
