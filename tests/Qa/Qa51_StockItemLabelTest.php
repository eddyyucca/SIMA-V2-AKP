<?php

namespace Tests\Qa;

use App\Filament\Pages\PrintLabels;
use App\Models\Accessory;
use App\Models\Component;
use App\Models\Consumable;
use App\Services\LabelService;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Tests\QaTestCase;

/** Label Accessories / Komponen: QR + "PT. AKP" di atas + nomor kode di bawahnya. Consumable tidak dilabeli. */
class Qa51_StockItemLabelTest extends QaTestCase
{
    public static function kinds(): array
    {
        return [['accessory'], ['component']];
    }

    private function captured(string $kind, int $tape = 9, int $length = 45): array
    {
        $seen = null;
        View::composer('pdf.label-compact', function ($v) use (&$seen) { $seen = $v->getData(); });
        $m = LabelService::model($kind)::orderBy('id')->firstOrFail();
        app(LabelService::class)->pdf($kind, collect([$m]), $tape, $length)->output();
        return [$seen, $m];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('kinds')]
    public function test_label_shows_company_above_the_code_and_no_item_name(string $kind): void
    {
        $this->as($this->staff());
        [$seen, $m] = $this->captured($kind);
        $l = $seen['labels'][0];
        $this->assertSame('PT. AKP', $l['c1']);
        $this->assertSame(strtoupper($m->code), $l['c2']);
        $this->assertMatchesRegularExpression('/^(ACC|CMP)-\d{4}$/', $l['c2']);
        $this->assertSame($l['f1'], $l['f2'], 'kedua baris memakai ukuran huruf yang sama');
        $this->assertGreaterThanOrEqual(6.0, $l['f1'], 'tetap terbaca');
        $this->assertStringNotContainsString(strtoupper($m->name), $l['c1'] . ' ' . $l['c2'], 'nama barang tidak ikut dicetak');

        $html = view('pdf.label-compact', $seen)->render();
        $this->assertStringContainsString('PT. AKP', $html);
        $this->assertStringContainsString(strtoupper($m->code), $html);
        $G = $seen['G'];
        $this->assertLessThanOrEqual($G['textW'], LabelService::textWidthMm($l['c1'], $l['f1']) + 0.001);
        $this->assertLessThanOrEqual($G['textW'], LabelService::textWidthMm($l['c2'], $l['f2']) + 0.001);
    }

    public function test_company_text_is_configurable_and_very_narrow_tape_prints_only_the_code(): void
    {
        $this->as($this->staff());
        config(['labels.other.company' => 'pt. contoh']);
        [$seen, $m] = $this->captured('accessory');
        $this->assertSame('PT. CONTOH', $seen['labels'][0]['c1']);

        [$seen] = $this->captured('accessory', 6, 30);                       // pita 6 mm: satu baris saja
        $this->assertSame(strtoupper($m->code), $seen['labels'][0]['c1']);
        $this->assertSame('', $seen['labels'][0]['c2']);
    }

    public function test_assets_on_small_tape_keep_their_own_code_and_serial_layout(): void
    {
        $this->as($this->staff());
        [$seen, $a] = $this->captured('asset', 12, 45);
        $this->assertSame(strtoupper($a->asset_tag), $seen['labels'][0]['c1']);
        $this->assertNotSame('PT. AKP', $seen['labels'][0]['c1']);
    }

    public function test_consumables_have_no_label_anywhere(): void
    {
        $this->as($this->staff());
        $c = Consumable::firstOrFail();
        $this->get('/labels/consumable?ids=' . $c->id)->assertNotFound();
        $this->get(route('labels.print', ['kind' => 'accessory', 'ids' => (string) Accessory::value('id')]))->assertOk();   // jenis lain tetap jalan
        $this->assertArrayNotHasKey('consumable', LabelService::KINDS);
        $this->assertArrayHasKey('component', LabelService::KINDS);

        $page = Livewire::withQueryParams(['kind' => 'consumable'])->test(PrintLabels::class);
        $page->assertSet('kind', 'asset');                                   // ?kind=consumable diabaikan
        $this->get('/admin/cetak-label')->assertOk()->assertDontSee('<option value="consumable"', false)->assertSee('<option value="accessory"', false)->assertSee('<option value="component"', false);

        // kode consumable di label lama tetap bisa discan
        $this->assertSame('consumable', LabelService::resolve($c->code)[0]);
        Livewire::test(\App\Filament\Pages\ScanPage::class)->call('onScan', $c->code)->assertNotified();
    }

    public function test_label_buttons_exist_for_accessories_and_components_but_not_consumables(): void
    {
        $this->as($this->staff());
        $label = \Filament\Actions\Testing\TestAction::make('label');
        Livewire::test(\App\Filament\Resources\Accessories\Pages\ManageAccessories::class)->assertActionVisible($label->table(Accessory::first()));
        Livewire::test(\App\Filament\Resources\Components\Pages\ManageComponents::class)->assertActionVisible(\Filament\Actions\Testing\TestAction::make('label')->table(Component::first()));
        Livewire::test(\App\Filament\Resources\Consumables\Pages\ManageConsumables::class)->assertActionDoesNotExist(\Filament\Actions\Testing\TestAction::make('label')->table(Consumable::first()));
    }
}
