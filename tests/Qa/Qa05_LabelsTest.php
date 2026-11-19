<?php

namespace Tests\Qa;

use App\Filament\Pages\PrintLabels;
use App\Filament\Pages\ScanPage;
use App\Models\Accessory;
use App\Models\Asset;
use App\Models\Component;
use App\Models\Consumable;
use App\Services\LabelService;
use Livewire\Livewire;
use Tests\QaTestCase;

class Qa05_LabelsTest extends QaTestCase
{
    public static function kinds(): array
    {
        return [['asset'], ['accessory'], ['component']];
    }

    private function ids(string $kind, int $n = 3): string
    {
        return LabelService::model($kind)::orderBy('id')->limit($n)->pluck('id')->implode(',');
    }

    /** Ukuran halaman PDF dalam mm dari /MediaBox. */
    private function mediaBox(string $pdf): array
    {
        preg_match('#/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+([\d.]+)\s+([\d.]+)\s*\]#', $pdf, $m);
        $this->assertNotEmpty($m, 'MediaBox tidak ditemukan');
        return [round($m[1] / 2.834645669, 1), round($m[2] / 2.834645669, 1)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('kinds')]
    public function test_label_pdf_has_exact_tape_size_and_one_page_per_label(string $kind): void
    {
        $this->as($this->staff());
        foreach ([[24, 60], [18, 50], [12, 40], [9, 30], [6, 25]] as [$tape, $len]) {
            $res = $this->get(route('labels.print', ['kind' => $kind, 'ids' => $this->ids($kind, 3), 'tape' => $tape, 'length' => $len, 'copies' => 2]));
            $res->assertOk()->assertHeader('content-type', 'application/pdf');
            $pdf = $res->getContent();
            $this->assertStringStartsWith('%PDF', $pdf);
            [$w, $h] = $this->mediaBox($pdf);
            $this->assertEqualsWithDelta($len, $w, 0.3, "{$kind} {$tape}mm: panjang");
            $this->assertEqualsWithDelta($tape, $h, 0.3, "{$kind} {$tape}mm: lebar pita");
            $this->assertSame(6, preg_match_all('#/Type\s*/Page\b#', $pdf), "{$kind} {$tape}mm: 3 barang × 2 salinan = 6 halaman");
        }
    }

    public function test_codes_are_stable_unique_and_scannable_for_every_kind(): void
    {
        $this->as($this->staff());
        $a = Accessory::first(); $c = Consumable::first(); $p = Component::first();
        $this->assertMatchesRegularExpression('/^ACC-\d{4}$/', $a->code);
        $this->assertMatchesRegularExpression('/^CSM-\d{4}$/', $c->code);
        $this->assertMatchesRegularExpression('/^CMP-\d{4}$/', $p->code);
        $this->assertSame(Accessory::count(), Accessory::all()->pluck('code')->unique()->count());

        $this->assertSame('accessory', LabelService::resolve($a->code)[0]);
        $this->assertSame('consumable', LabelService::resolve(strtolower($c->code))[0]);     // tidak peka huruf besar/kecil
        $this->assertSame('component', LabelService::resolve($p->code)[0]);
        $asset = Asset::first();
        $this->assertSame('asset', LabelService::resolve($asset->asset_tag)[0]);
        $this->assertSame($asset->id, LabelService::resolve($asset->serial_number)[1]->id);
        $this->assertNull(LabelService::resolve('ACC-9999'));
        $this->assertNull(LabelService::resolve('NGAWUR'));
        $this->assertNull(LabelService::resolve(''));

        // halaman scan: aset → redirect, barang stok → notifikasi dengan stok
        Livewire::test(ScanPage::class)->call('onScan', $a->code)->assertNoRedirect()->assertNotified();
        $this->assertSame($asset->asset_tag, Livewire::test(ScanPage::class)->call('onScan', $asset->asset_tag)->assertNoRedirect()->get('card')['tag']);   // aset: kartu data pemegang
        Livewire::test(ScanPage::class)->call('onScan', 'ACC-9999')->assertNotified();
    }

    public function test_label_rejects_invalid_requests_and_unauthorized_users(): void
    {
        $this->as($this->staff());
        $this->get(route('labels.print', ['kind' => 'asset', 'ids' => '']))->assertStatus(422);
        $this->get(route('labels.print', ['kind' => 'asset', 'ids' => '999999']))->assertNotFound();
        $this->get(route('labels.print', ['kind' => 'asset', 'ids' => '1', 'tape' => 7]))->assertStatus(422);     // pita 7 mm tidak ada
        $this->get('/labels/barang-aneh?ids=1')->assertNotFound();
        $this->as($this->plain())->get(route('labels.print', ['kind' => 'asset', 'ids' => '1']))->assertForbidden();
        $this->as($this->mgmt())->get(route('labels.print', ['kind' => 'asset', 'ids' => '1']))->assertOk();
    }

    public function test_long_text_is_truncated_and_does_not_break_the_label(): void
    {
        $this->as($this->staff());
        $c = Accessory::first();
        $c->update(['name' => str_repeat('Nama Sangat Panjang ', 12)]);
        foreach ([24, 12, 6] as $tape) {
            $this->get(route('labels.print', ['kind' => 'accessory', 'ids' => (string) $c->id, 'tape' => $tape, 'length' => 40]))->assertOk();
        }
        $G = LabelService::compactGeometry(9, 45);
        $cut = LabelService::truncateToFit(str_repeat('NAMA PANJANG ', 20), $G['textW'], 5.0);
        $this->assertLessThanOrEqual($G['textW'], LabelService::textWidthMm($cut, 5.0));
        $this->assertStringEndsWith('…', $cut);
    }

    public function test_print_labels_page_selection_and_url(): void
    {
        $this->as($this->staff());
        foreach (array_keys(LabelService::KINDS) as $kind) {
            $ids = array_map('intval', explode(',', $this->ids($kind, 2)));
            $t = Livewire::withQueryParams(['kind' => $kind, 'ids' => implode(',', $ids)])->test(PrintLabels::class);
            $t->assertSet('kind', $kind)->assertSet('tape', LabelService::defaults($kind)['tape'])->assertSet('length', LabelService::defaults($kind)['length']);
            $this->assertStringContainsString("labels/{$kind}", $t->instance()->url());
            $t->set('tape', 12)->set('length', 45)->set('copies', 3);
            $this->assertStringContainsString('tape=12', $t->instance()->url());
            $this->assertStringContainsString('copies=3', $t->instance()->url());
            $t->call('selectAll');
            $this->assertCount(min(300, LabelService::model($kind)::count()), $t->get('selected'));
            $t->call('clear');
            $this->assertNull($t->instance()->url());
        }
        $this->get('/admin/cetak-label')->assertOk()->assertSee('Brother PT-D610BT');
        $this->as($this->plain())->get('/admin/cetak-label')->assertForbidden();
    }

    public function test_label_buttons_exist_on_every_inventory_screen(): void
    {
        $this->as($this->staff());
        $acc = Accessory::first();
        Livewire::test(\App\Filament\Resources\Accessories\Pages\ManageAccessories::class)->assertActionVisible(\Filament\Actions\Testing\TestAction::make('label')->table($acc));
        Livewire::test(\App\Filament\Resources\Consumables\Pages\ManageConsumables::class)->assertActionDoesNotExist(\Filament\Actions\Testing\TestAction::make('label')->table(Consumable::first()));   // consumable tidak dilabeli
        Livewire::test(\App\Filament\Resources\Components\Pages\ManageComponents::class)->assertActionVisible(\Filament\Actions\Testing\TestAction::make('label')->table(Component::first()));
        Livewire::test(\App\Filament\Resources\Assets\Pages\ViewAsset::class, ['record' => Asset::first()->getKey()])->assertActionExists('label');
        Livewire::test(\App\Filament\Resources\Assets\Pages\ListAssets::class)
            ->selectTableRecords(Asset::orderBy('id')->limit(3)->pluck('id')->all())
            ->callAction(\Filament\Actions\Testing\TestAction::make('labels_tape')->table()->bulk())->assertHasNoActionErrors();
    }
}
