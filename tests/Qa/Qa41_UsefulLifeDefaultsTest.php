<?php

namespace Tests\Qa;

use App\Filament\Pages\Settings;
use App\Filament\Resources\Assets\Pages\CreateAsset;
use App\Filament\Resources\Assets\Pages\EditAsset;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Setting;
use App\Services\DepreciationService;
use Livewire\Livewire;
use Tests\QaTestCase;

/** Umur ekonomis per kategori diatur di Pengaturan dan terisi otomatis di form aset (mis. Laptop 4 tahun). */
class Qa41_UsefulLifeDefaultsTest extends QaTestCase
{
    private function cat(string $prefix): Category { return Category::where('code_prefix', $prefix)->firstOrFail(); }

    private function base(Category $c, array $extra = []): array
    {
        return ['category_id' => $c->id, 'asset_tag' => Asset::generateTag($c), 'condition' => 'good'] + $extra;
    }

    public function test_settings_card_lists_asset_categories_and_saves_years_and_residual(): void
    {
        $this->as($this->admin());
        $c = Livewire::test(Settings::class);
        $rows = collect($c->get('lifeRows'));
        $this->assertGreaterThanOrEqual(5, $rows->count());
        $lpt = $rows->firstWhere('id', $this->cat('NB')->id);
        $this->assertSame('4', $lpt['years']);
        $this->assertNotNull($c->get('lifeDefault'));

        $all = $c->get('lifeRows');
        $i = collect($all)->search(fn ($r) => $r['id'] === $this->cat('NB')->id);
        $all[$i]['years'] = '5'; $all[$i]['residual'] = '10';
        $j = collect($all)->search(fn ($r) => $r['id'] === $this->cat('MNT')->id);
        $all[$j]['years'] = '';                                   // kosong = ikut bawaan
        $c->set('lifeRows', $all)->set('lifeDefault', '6')->call('saveLife')->assertHasNoErrors()->assertDispatched('close-modal', id: 'set-umur');
        $this->assertSame([5, 10.0], [$this->cat('NB')->useful_life_years, (float) $this->cat('NB')->residual_percent]);
        $this->assertNull($this->cat('MNT')->useful_life_years);
        $this->assertSame(6, Category::defaultLifeYears());
        $this->assertSame(6, $this->cat('MNT')->effectiveLifeYears());
        $this->assertSame(5, $this->cat('NB')->effectiveLifeYears());
    }

    public function test_settings_validation(): void
    {
        $this->as($this->admin());
        $rows = Livewire::test(Settings::class)->get('lifeRows');
        foreach ([['years', '0'], ['years', '51'], ['years', '2.5'], ['years', 'abc'], ['residual', '101'], ['residual', '-1'], ['residual', 'x']] as [$f, $v]) {
            $bad = $rows; $bad[0][$f] = $v;
            Livewire::test(Settings::class)->set('lifeRows', $bad)->call('saveLife')->assertHasErrors(["lifeRows.0.$f"])->assertNotDispatched('close-modal');
        }
        foreach (['0', '', '99'] as $v) Livewire::test(Settings::class)->set('lifeDefault', $v)->call('saveLife')->assertHasErrors(['lifeDefault']);
        $this->assertSame(4, $this->cat('NB')->useful_life_years);                                // gagal: tidak ada yang tersimpan
    }

    public function test_new_laptop_form_is_prefilled_with_the_category_years_and_residual(): void
    {
        $this->as($this->staff());
        $lpt = $this->cat('NB');
        Livewire::test(CreateAsset::class)->fillForm(['category_id' => $lpt->id])->assertFormSet(['category_id' => $lpt->id]);
        // memilih kategori lewat interaksi (afterStateUpdated) mengisi umur & residu
        $c = Livewire::test(CreateAsset::class)->set('data.category_id', $lpt->id);
        $c->assertSet('data.useful_life_years', 4)->assertSet('data.residual_percent', 0.0);
        // ganti ke kategori lain (Server 5 tahun): ikut berubah selama belum diubah manual
        $srv = $this->cat('SRV');
        $c->set('data.category_id', $srv->id)->assertSet('data.useful_life_years', $srv->effectiveLifeYears());
        // sudah diubah manual -> tidak ditimpa saat ganti kategori
        $c2 = Livewire::test(CreateAsset::class)->set('data.category_id', $lpt->id)->set('data.useful_life_years', 7)->set('data.category_id', $this->cat('MNT')->id);
        $c2->assertSet('data.useful_life_years', 7);
    }

    public function test_changing_settings_changes_the_prefill_and_saved_value_follows_the_category_unless_overridden(): void
    {
        $this->as($this->admin());
        $lpt = $this->cat('NB');
        $lpt->update(['useful_life_years' => 5]);
        Livewire::test(CreateAsset::class)->set('data.category_id', $lpt->id)->assertSet('data.useful_life_years', 5);

        // tanpa mengisi apa pun: tersimpan sebagai "ikut kategori" (kosong), penyusutan memakai 5 tahun
        $d = $this->base($lpt, ['purchase_price' => 12000000, 'purchase_date' => '2026-01-10']);
        Livewire::test(CreateAsset::class)->fillForm($d + ['useful_life_years' => 5, 'residual_percent' => 0])->call('create')->assertHasNoFormErrors();
        $a = Asset::where('asset_tag', $d['asset_tag'])->firstOrFail();
        $this->assertNull($a->useful_life_years);
        $this->assertSame(5, app(DepreciationService::class)->profile($a)['years']);
        $lpt->update(['useful_life_years' => 3]);                                            // kategori diubah -> aset yang mengikuti ikut berubah
        $this->assertSame(3, app(DepreciationService::class)->profile($a->fresh())['years']);

        // diubah manual untuk aset ini -> tersimpan sendiri dan tidak ikut kategori
        $d2 = $this->base($lpt, ['purchase_price' => 12000000, 'purchase_date' => '2026-01-10']);
        Livewire::test(CreateAsset::class)->fillForm($d2 + ['useful_life_years' => 8])->call('create')->assertHasNoFormErrors();
        $b = Asset::where('asset_tag', $d2['asset_tag'])->firstOrFail();
        $this->assertSame(8, (int) $b->useful_life_years);
        $this->assertSame(8, app(DepreciationService::class)->profile($b)['years']);
    }

    public function test_edit_form_shows_the_effective_value_and_keeps_following_the_category(): void
    {
        $this->as($this->staff());
        $lpt = $this->cat('NB');
        $d = $this->base($lpt);
        Livewire::test(CreateAsset::class)->fillForm($d)->call('create')->assertHasNoFormErrors();
        $a = Asset::where('asset_tag', $d['asset_tag'])->firstOrFail();
        $this->assertNull($a->useful_life_years);
        Livewire::test(EditAsset::class, ['record' => $a->getKey()])->assertFormSet(['useful_life_years' => 4, 'residual_percent' => 0.0])
            ->fillForm(['notes' => 'ubah catatan'])->call('save')->assertHasNoFormErrors();
        $this->assertNull($a->fresh()->useful_life_years);                                      // menyimpan ulang tidak "membekukan" nilai kategori
        $this->assertSame('ubah catatan', $a->fresh()->notes);
    }

    public function test_default_years_applies_to_categories_without_their_own_value(): void
    {
        $this->as($this->admin());
        $mnt = $this->cat('MNT'); $mnt->update(['useful_life_years' => null]);
        $this->assertSame((int) config('depreciation.default_life_years'), $mnt->fresh()->effectiveLifeYears());
        Setting::put('depreciation_default_years', '7');
        $this->assertSame(7, $mnt->fresh()->effectiveLifeYears());
        Livewire::test(CreateAsset::class)->set('data.category_id', $mnt->id)->assertSet('data.useful_life_years', 7);
        $a = new Asset(['category_id' => $mnt->id, 'purchase_price' => 5000000, 'purchase_date' => '2026-01-01', 'useful_life_years' => null, 'status' => 'available']);
        $a->setRelation('category', $mnt->fresh());
        $this->assertSame(7, app(DepreciationService::class)->profile($a)['years']);
    }
}
