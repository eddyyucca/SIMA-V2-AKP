<?php

namespace Tests\Qa;

use App\Filament\Pages\ImportAssets;
use App\Filament\Resources\AssetModels\Pages\ManageAssetModels;
use App\Filament\Resources\Assets\Pages\CreateAsset;
use App\Filament\Resources\Assets\Pages\EditAsset;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Filament\Resources\Brands\Pages\ManageBrands;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Brand;
use App\Models\Category;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\QaTestCase;

class Qa09_BrandModelMasterTest extends QaTestCase
{
    public function test_every_asset_in_demo_data_is_linked_to_master_brand_and_model(): void
    {
        $this->assertGreaterThanOrEqual(12, Brand::count());
        $this->assertGreaterThanOrEqual(30, AssetModel::count());
        foreach (Asset::with(['brandRef', 'modelRef'])->get() as $a) {
            $this->assertNotNull($a->brand_id, "{$a->asset_tag} tanpa master merek");
            $this->assertNotNull($a->asset_model_id, "{$a->asset_tag} tanpa master model");
            $this->assertSame($a->brandRef->name, $a->brand);
            $this->assertSame($a->modelRef->name, $a->model);
            $this->assertSame($a->brand_id, $a->modelRef->brand_id, "{$a->asset_tag}: model bukan milik merek aset");
        }
        $names = Brand::all()->map(fn ($b) => mb_strtolower($b->name));
        $this->assertSame($names->count(), $names->unique()->count(), 'tidak ada merek ganda');
        $this->assertGreaterThan(20, AssetModel::whereNotNull('default_specs')->count());      // spesifikasi bawaan terisi
        $this->assertSame(Brand::count(), Brand::has('models')->count(), 'setiap merek punya minimal satu model');
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_staff_can_manage_brands_and_models_with_validation(): void
    {
        $this->as($this->staff());
        Livewire::test(ManageBrands::class)->callAction('create', ['name' => ''])->assertHasActionErrors(['name' => 'required']);
        Livewire::test(ManageBrands::class)->callAction('create', ['name' => 'Merek QA'])->assertHasNoActionErrors();
        Livewire::test(ManageBrands::class)->callAction('create', ['name' => 'Merek QA'])->assertHasActionErrors(['name']);      // unik
        $brand = Brand::where('name', 'Merek QA')->firstOrFail();

        Livewire::test(ManageAssetModels::class)->callAction('create', ['brand_id' => $brand->id, 'name' => ''])->assertHasActionErrors(['name' => 'required']);
        Livewire::test(ManageAssetModels::class)->callAction('create', ['brand_id' => null, 'name' => 'X'])->assertHasActionErrors(['brand_id' => 'required']);
        Livewire::test(ManageAssetModels::class)->callAction('create', ['brand_id' => $brand->id, 'name' => 'Model QA 1', 'default_specs' => 'RAM 8GB'])->assertHasNoActionErrors();
        // nama model unik per merek, tetapi boleh sama pada merek lain
        Livewire::test(ManageAssetModels::class)->callAction('create', ['brand_id' => $brand->id, 'name' => 'Model QA 1'])->assertHasActionErrors(['name']);
        $other = Brand::where('id', '!=', $brand->id)->first();
        Livewire::test(ManageAssetModels::class)->callAction('create', ['brand_id' => $other->id, 'name' => 'Model QA 1'])->assertHasNoActionErrors();
        $this->assertSame(2, AssetModel::where('name', 'Model QA 1')->count());
    }

    public function test_permissions_staff_manages_admin_deletes_management_reads(): void
    {
        $brand = Brand::first();
        $this->as($this->staff());
        Livewire::test(ManageBrands::class)->assertActionVisible('create')->assertActionVisible(TestAction::make('edit')->table($brand))->assertActionHidden(TestAction::make('delete')->table($brand));
        $this->as($this->mgmt());
        Livewire::test(ManageBrands::class)->assertActionHidden('create');
        $this->get('/admin/brands')->assertOk();
        $this->get('/admin/asset-models')->assertOk();
        $this->as($this->plain())->get('/admin/brands')->assertForbidden();
        $this->as($this->admin())->get('/admin/brands')->assertOk()->assertSee('Merek');
    }

    public function test_renaming_brand_or_model_updates_every_asset(): void
    {
        $this->as($this->staff());
        $a = Asset::where('brand', 'Lenovo')->firstOrFail();
        $brand = $a->brandRef;
        $count = Asset::where('brand_id', $brand->id)->count();
        $this->assertGreaterThan(1, $count);
        Livewire::test(ManageBrands::class)->callAction(TestAction::make('edit')->table($brand), ['name' => 'Lenovo Indonesia'])->assertHasNoActionErrors();
        $this->assertSame($count, Asset::where('brand', 'Lenovo Indonesia')->count());
        $this->assertSame(0, Asset::where('brand', 'Lenovo')->count());

        $model = $a->fresh()->modelRef;
        Livewire::test(ManageAssetModels::class)->callAction(TestAction::make('edit')->table($model), ['name' => $model->name . ' Rev2', 'brand_id' => $brand->id])->assertHasNoActionErrors();
        $this->assertSame($model->name . ' Rev2', $a->fresh()->model);
        $this->artisan('inventory:audit')->assertSuccessful();

        // memindahkan model ke merek lain → merek pada asetnya ikut pindah
        $newBrand = Brand::where('id', '!=', $brand->id)->first();
        Livewire::test(ManageAssetModels::class)->callAction(TestAction::make('edit')->table($model->fresh()), ['brand_id' => $newBrand->id, 'name' => $model->fresh()->name])->assertHasNoActionErrors();
        $this->assertSame($newBrand->id, $a->fresh()->brand_id);
        $this->assertSame($newBrand->name, $a->fresh()->brand);
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_brand_or_model_in_use_cannot_be_deleted_but_unused_can_by_admin(): void
    {
        $this->as($this->admin());
        $used = Asset::first()->brandRef;
        Livewire::test(ManageBrands::class)->callAction(TestAction::make('delete')->table($used))->assertNotified('Merek masih dipakai');
        $this->assertNull($used->fresh()->deleted_at);
        $usedModel = Asset::first()->modelRef;
        Livewire::test(ManageAssetModels::class)->callAction(TestAction::make('delete')->table($usedModel))->assertNotified('Model masih dipakai');
        $this->assertNull($usedModel->fresh()->deleted_at);

        $b = Brand::create(['name' => 'Merek Tanpa Aset']);
        $m = AssetModel::create(['brand_id' => $b->id, 'name' => 'Model Kosong']);
        Livewire::test(ManageAssetModels::class)->callAction(TestAction::make('delete')->table($m))->assertHasNoActionErrors();
        Livewire::test(ManageBrands::class)->callAction(TestAction::make('delete')->table($b))->assertHasNoActionErrors();
        $this->assertSoftDeleted($b);                                                         // soft delete, data tetap ada
    }

    public function test_asset_form_uses_master_selects_and_syncs_text_columns(): void
    {
        $this->as($this->staff());
        $cat = Category::where('code_prefix', 'NB')->firstOrFail();
        $brand = Brand::where('name', 'Lenovo')->firstOrFail();
        $model = AssetModel::where('brand_id', $brand->id)->firstOrFail();
        $tag = Asset::generateTag($cat);
        Livewire::test(CreateAsset::class)->fillForm(['category_id' => $cat->id, 'asset_tag' => $tag, 'brand_id' => $brand->id, 'asset_model_id' => $model->id, 'condition' => 'good'])
            ->call('create')->assertHasNoFormErrors();
        $a = Asset::where('asset_tag', $tag)->firstOrFail();
        $this->assertSame([$brand->id, $model->id, $brand->name, $model->name], [$a->brand_id, $a->asset_model_id, $a->brand, $a->model]);
        // label dan detail memakai teks hasil sinkronisasi
        $this->get(\App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $a]))->assertOk()->assertSee($model->name);

        // form: model milik merek lain ditolak
        $other = Brand::where('id', '!=', $brand->id)->first();
        Livewire::test(EditAsset::class, ['record' => $a->getKey()])->fillForm(['brand_id' => $other->id, 'asset_model_id' => $model->id])->call('save')->assertHasFormErrors(['asset_model_id']);
        $this->assertSame($brand->id, $a->fresh()->brand_id);
        // kode non-form: model milik merek lain otomatis dilepas
        $a->update(['brand_id' => $other->id, 'asset_model_id' => $model->id]);
        $a->refresh();
        $this->assertSame($other->id, $a->brand_id);
        $this->assertSame($other->name, $a->brand);
        $this->assertNull($a->asset_model_id);
        $this->assertNull($a->model);

        // mengosongkan merek mengosongkan teksnya (tidak ditautkan ulang)
        Livewire::test(EditAsset::class, ['record' => $a->getKey()])->fillForm(['brand_id' => null, 'asset_model_id' => null])->call('save')->assertHasNoFormErrors();
        $a->refresh();
        $this->assertNull($a->brand_id); $this->assertNull($a->brand); $this->assertNull($a->model);
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_model_options_follow_the_selected_brand_and_default_specs_autofill(): void
    {
        $this->as($this->staff());
        $cat = Category::where('code_prefix', 'NB')->firstOrFail();
        $brand = Brand::where('name', 'Lenovo')->firstOrFail();
        $model = AssetModel::where('brand_id', $brand->id)->whereNotNull('default_specs')->firstOrFail();
        $t = Livewire::test(CreateAsset::class)->fillForm(['category_id' => $cat->id, 'brand_id' => $brand->id]);
        $t->set('data.asset_model_id', $model->id);
        $this->assertSame($model->default_specs, $t->get('data.specs'));                       // spesifikasi bawaan terisi
        $t->set('data.brand_id', Brand::where('id', '!=', $brand->id)->value('id'));
        $this->assertNull($t->get('data.asset_model_id'));                                    // ganti merek → model dikosongkan
    }

    public function test_text_only_creation_reuses_master_case_insensitively_and_restores_deleted(): void
    {
        $this->as($this->staff());
        $cat = Category::where('code_prefix', 'NB')->firstOrFail();
        $before = [Brand::count(), AssetModel::count()];
        $a = Asset::create(['asset_tag' => Asset::generateTag($cat), 'category_id' => $cat->id, 'brand' => 'lenovo', 'model' => strtolower(Asset::where('brand', 'Lenovo')->value('model')), 'status' => 'available', 'condition' => 'good']);
        $this->assertSame($before, [Brand::count(), AssetModel::count()], 'tidak membuat merek/model ganda');
        $this->assertSame('Lenovo', $a->fresh()->brand);                                      // memakai penulisan master

        $b = Asset::create(['asset_tag' => Asset::generateTag($cat), 'category_id' => $cat->id, 'brand' => 'Merek Baru Otomatis', 'model' => 'Seri X1', 'status' => 'available', 'condition' => 'good']);
        $this->assertSame($before[0] + 1, Brand::count());
        $this->assertSame($before[1] + 1, AssetModel::count());
        $this->assertSame($cat->id, $b->modelRef->category_id);                               // kategori model terisi dari aset

        $brand = $b->brandRef;
        $b->update(['brand_id' => null, 'asset_model_id' => null, 'brand' => null, 'model' => null]);
        $brand->models()->delete();
        $brand->delete();
        Asset::create(['asset_tag' => Asset::generateTag($cat), 'category_id' => $cat->id, 'brand' => 'MEREK BARU OTOMATIS', 'status' => 'available', 'condition' => 'good']);
        $this->assertNull($brand->fresh()->deleted_at, 'merek yang pernah dihapus dipulihkan saat dipakai lagi');
    }

    public function test_asset_list_filters_by_brand_and_searches_brand_and_model_text(): void
    {
        $this->as($this->staff());
        $brand = Brand::where('name', 'Dell')->firstOrFail();
        $expected = Asset::where('ownership', 'owned')->where('brand_id', $brand->id)->count();
        $this->assertGreaterThan(1, $expected);
        Livewire::test(ListAssets::class)->filterTable('brand_id', $brand->id)->assertCountTableRecords($expected);
        $model = Asset::where('brand_id', $brand->id)->value('model');
        Livewire::test(ListAssets::class)->searchTable($model)->assertCanSeeTableRecords(Asset::where('model', $model)->get());
    }

    public function test_import_creates_missing_masters_and_reuses_existing_ones(): void
    {
        $this->as($this->staff());
        $existing = Asset::where('brand', 'HP')->first();
        $csv = "kategori,merek,model,serial_number\n"
            . "Laptop,hp," . strtolower($existing->model) . ",IMP-BM-1\n"                // merek & model sudah ada (huruf kecil)
            . "Laptop,Merek Impor Baru,Seri Impor 1,IMP-BM-2\n"                            // baru
            . "Laptop,MEREK IMPOR BARU,Seri Impor 1,IMP-BM-3\n"                            // sama dengan baris sebelumnya
            . "Laptop,Merek Impor Baru,Seri Impor 2,IMP-BM-4\n";                           // merek sama, model baru
        [$b, $m] = [Brand::count(), AssetModel::count()];
        $t = Livewire::test(ImportAssets::class)->set('file', UploadedFile::fake()->createWithContent('bm.csv', $csv))->call('import');
        $this->assertSame(4, $t->get('imported'));
        $this->assertSame($b + 1, Brand::count());
        $this->assertSame($m + 2, AssetModel::count());
        $x = Asset::where('serial_number', 'IMP-BM-1')->first();
        $this->assertSame('HP', $x->brand);
        $this->assertSame($existing->asset_model_id, $x->asset_model_id);
        $this->assertSame(Asset::where('serial_number', 'IMP-BM-2')->value('asset_model_id'), Asset::where('serial_number', 'IMP-BM-3')->value('asset_model_id'));
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_labels_and_reports_still_show_brand_and_model_text(): void
    {
        $this->as($this->staff());
        $a = Asset::orderBy('id')->first();
        $rows = \App\Reports\ReportCatalog::build('assets', ['category_id' => $a->category_id])[2];
        $this->assertTrue(collect($rows)->contains(fn ($r) => $r[0] === $a->asset_tag && $r[2] === $a->brand && $r[3] === $a->model));
        $this->get(route('labels.print', ['kind' => 'asset', 'ids' => (string) $a->id]))->assertOk();
    }
}
