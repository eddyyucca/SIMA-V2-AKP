<?php

namespace Tests\Qa;

use App\Filament\Resources\Accessories\Pages\ManageAccessories;
use App\Filament\Resources\AccessoryHandovers\Pages\CreateAccessoryHandover;
use App\Filament\Resources\AccessoryHandovers\Pages\ListAccessoryHandovers;
use App\Filament\Resources\Components\Pages\ManageComponents;
use App\Filament\Resources\Consumables\Pages\ManageConsumables;
use App\Filament\Pages\StockHistory\ConsumableStockHistory;
use App\Models\Accessory;
use App\Models\AccessoryHandover;
use App\Models\Asset;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Location;
use App\Models\StockMovement;
use App\Support\AccessoryBastDocument;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\QaTestCase;

/** Stok keluar Accessories / Consumable / Komponen: tujuan karyawan, department, lokasi (+ perangkat untuk consumable), dan selalu ada dokumen BAST. */
class Qa40_StockOutTargetsAndDocumentsTest extends QaTestCase
{
    public static function kinds(): array
    {
        return ['accessory' => ['accessory', ManageAccessories::class], 'consumable' => ['consumable', ManageConsumables::class], 'component' => ['component', ManageComponents::class]];
    }

    private function make(string $kind, int $stock = 20): Accessory|Consumable|Component
    {
        return match ($kind) {
            'accessory' => Accessory::create(['name' => 'Mouse T40', 'qty_total' => $stock, 'min_qty' => 0]),
            'consumable' => Consumable::create(['name' => 'Tinta T40', 'unit' => 'rim', 'qty' => $stock, 'min_qty' => 0]),
            'component' => Component::create(['name' => 'SSD T40', 'qty_total' => $stock, 'min_qty' => 0, 'serial_number' => 'T40-' . random_int(1000, 9999)]),
        };
    }

    private function left($item, string $kind): float { return (float) ($kind === 'consumable' ? $item->fresh()->qty : $item->fresh()->qty_available); }

    #[\PHPUnit\Framework\Attributes\DataProvider('kinds')]
    public function test_header_stock_out_to_every_target_makes_a_printable_document(string $kind, string $page): void
    {
        $this->as($this->staff());
        $item = $this->make($kind);
        $emp = Employee::where('status', 'active')->firstOrFail();
        $pic = Employee::where('status', 'active')->where('id', '!=', $emp->id)->firstOrFail();
        $dept = Department::firstOrFail(); $loc = Location::firstOrFail();
        $code = ['consumable' => 'BPB', 'accessory' => 'BSA', 'component' => 'BSK'][$kind];

        // karyawan
        Livewire::test($page)->callAction('stock_out_header', ['item_id' => $item->id, 'qty' => 2, 'target' => 'employee', 'employee_id' => $emp->id, 'notes' => 'ke karyawan'])->assertHasNoActionErrors()->assertNotified();
        $h1 = AccessoryHandover::latest('id')->first();
        $this->assertSame(['employee', $emp->id, $code], [$h1->target_type, $h1->employee_id, explode('/', $h1->bast_number)[2]]);
        // department (tanpa PIC)
        Livewire::test($page)->callAction('stock_out_header', ['item_id' => $item->id, 'qty' => 3, 'target' => 'department', 'department_id' => $dept->id])->assertHasNoActionErrors();
        $h2 = AccessoryHandover::latest('id')->first();
        $this->assertSame(['department', null, $dept->id, null], [$h2->target_type, $h2->employee_id, $h2->department_id, $h2->pic_id]);
        // lokasi (dengan PIC)
        Livewire::test($page)->callAction('stock_out_header', ['item_id' => $item->id, 'qty' => 1, 'target' => 'location', 'location_id' => $loc->id, 'employee_id' => $pic->id])->assertHasNoActionErrors();
        $h3 = AccessoryHandover::latest('id')->first();
        $this->assertSame(['location', null, $loc->id, $pic->id], [$h3->target_type, $h3->employee_id, $h3->location_id, $h3->pic_id]);
        $this->assertEquals(14, $this->left($item, $kind));                                          // 20 - 2 - 3 - 1

        // pergerakan stok mencatat tujuannya
        $m = fn ($h) => StockMovement::findOrFail($h->items->first()->stock_movement_id);
        $this->assertSame($emp->id, $m($h1)->employee_id);
        $this->assertSame([$dept->id, null], [$m($h2)->department_id, $m($h2)->employee_id]);
        $this->assertSame([$loc->id, null], [$m($h3)->location_id, $m($h3)->employee_id]);

        // dokumen: setiap tujuan tersusun, bisa dicetak & diunduh, memuat tujuan/PIC yang benar
        foreach ([[$h1, [$emp->name]], [$h2, [$dept->name]], [$h3, [$loc->name, $pic->name]]] as [$h, $needles]) {
            $d = AccessoryBastDocument::build($h->fresh());
            $html = view('pdf.accessory-bast', ['d' => $d])->render();
            foreach ($needles as $n) $this->assertStringContainsString(strtoupper($n) === $n ? $n : htmlspecialchars($n, ENT_QUOTES), $html, $h->target_type);
            $this->get(route('accessory-bast.view', $h))->assertOk();
            $this->get(route('accessory-bast.download', $h))->assertOk();
            $this->assertNotEmpty(AccessoryBastDocument::pdf($h->fresh())->output());
        }
        $this->assertSame('', AccessoryBastDocument::build($h2->fresh())['receiver']['name']);        // tanpa PIC: tanda tangan manual
        $this->assertSame($pic->name, AccessoryBastDocument::build($h3->fresh())['receiver']['name']);
        $this->assertStringContainsString('Department: ' . $dept->name, $h2->fresh()->recipientLabel());
        $this->assertStringContainsString('Lokasi: ' . $loc->name, $h3->fresh()->recipientLabel());

        // hanya karyawan yang tercatat memegang (accessories); department/lokasi/PIC tidak
        if ($kind === 'accessory') {
            $this->assertSame([2], $emp->heldAccessories()->filter(fn ($x) => $x['accessory']->id === $item->id)->pluck('qty')->all());
            $this->assertSame(0, $pic->heldAccessories()->filter(fn ($x) => $x['accessory']->id === $item->id)->count());
        }
        // riwayat stok menampilkan lokasi
        $this->assertSame($loc->name, $m($h3)->location->name);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('kinds')]
    public function test_row_action_stock_out_also_creates_a_document(string $kind, string $page): void
    {
        $this->as($this->staff());
        $item = $this->make($kind);
        $dept = Department::firstOrFail();
        $action = ['accessory' => 'checkout', 'consumable' => 'stock_out', 'component' => 'stock_out'][$kind];
        Livewire::test($page)->callAction(TestAction::make($action)->table($item), ['qty' => 4, 'target' => 'department', 'department_id' => $dept->id])->assertHasNoActionErrors()->assertNotified();
        $h = AccessoryHandover::latest('id')->firstOrFail();
        $this->assertSame([$dept->id, 'department'], [$h->department_id, $h->target_type]);
        $this->assertEquals(16, $this->left($item, $kind));
        // default tujuan = karyawan (aksi lama "Serahkan" tetap jalan hanya dengan karyawan + jumlah)
        $emp = Employee::where('status', 'active')->firstOrFail();
        Livewire::test($page)->callAction(TestAction::make($action)->table($item), ['employee_id' => $emp->id, 'qty' => 1])->assertHasNoActionErrors();
        $this->assertSame('employee', AccessoryHandover::latest('id')->first()->target_type);
    }

    public function test_validation_failures_leave_no_trace(): void
    {
        $this->as($this->staff());
        $item = $this->make('accessory', 5);
        $emp = Employee::where('status', 'active')->firstOrFail();
        $before = [AccessoryHandover::count(), StockMovement::count()];
        $svc = app(\App\Services\AccessoryHandoverService::class);
        $line = [['item' => 'accessory:' . $item->id, 'qty' => 1]];
        foreach ([
            [fn () => $svc->checkout(null, $line), 'Pilih karyawan'],
            [fn () => $svc->checkout(null, $line, null, null, ['type' => 'department']), 'Pilih department'],
            [fn () => $svc->checkout(null, $line, null, null, ['type' => 'department', 'department_id' => 999999]), 'Pilih department'],
            [fn () => $svc->checkout(null, $line, null, null, ['type' => 'location']), 'Pilih lokasi'],
            [fn () => $svc->checkout($emp, [['item' => 'component:' . \App\Models\Component::value('id'), 'qty' => 1]], null, null, ['type' => 'asset', 'asset_id' => Asset::value('id')]), 'hanya untuk accessories dan consumable'],
            [fn () => $svc->checkout(null, $line, null, null, ['type' => 'ruang-angkasa']), 'tidak dikenal'],
            [fn () => $svc->checkout($emp, [['item' => 'accessory:' . $item->id, 'qty' => 99]], null, null, ['type' => 'location', 'location_id' => Location::value('id')]), 'melebihi'],
        ] as [$fn, $msg]) {
            try { $fn(); $this->fail("harus gagal: $msg"); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString($msg, $e->getMessage()); }
        }
        $resigned = Employee::create(['name' => 'PIC Resign T40', 'email' => 'picr@t40.test', 'status' => 'resigned']);
        try { $svc->checkout($resigned, $line, null, null, ['type' => 'department', 'department_id' => Department::value('id')]); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('resigned', $e->getMessage()); }
        $this->assertSame($before, [AccessoryHandover::count(), StockMovement::count()]);
        $this->assertSame(5, $item->fresh()->qty_available);
    }

    public function test_consumable_decimals_and_asset_target(): void
    {
        $this->as($this->staff());
        $c = $this->make('consumable', 10);
        $asset = Asset::firstOrFail();
        Livewire::test(ManageConsumables::class)->callAction('stock_out_header', ['item_id' => $c->id, 'qty' => 1.5, 'target' => 'asset', 'asset_id' => $asset->id])->assertHasNoActionErrors();
        $h = AccessoryHandover::latest('id')->first();
        $this->assertEquals(8.5, $c->fresh()->qty);
        $this->assertSame(['asset', $asset->id], [$h->target_type, $h->asset_id]);
        $this->assertSame(1.5, $h->items->first()->qty);
        $this->assertStringContainsString($asset->asset_tag, view('pdf.accessory-bast', ['d' => AccessoryBastDocument::build($h)])->render());
        $d40 = AccessoryBastDocument::build($h);
        $this->assertSame(['1.5', 'Satuan: rim'], [$d40['rows'][0]['qty'], $d40['rows'][0]['remark']]);                   // Qty hanya angka, satuan di Catatan
        $this->assertStringContainsString('Satuan: rim', view('pdf.accessory-bast', ['d' => $d40])->render());
        $this->get(route('accessory-bast.view', $h))->assertOk();
        // perangkat ditawarkan untuk accessories dan consumable, tidak untuk komponen
        $targets = fn (string $kind) => collect(\App\Filament\Support\StockHeaderActions::outFields($kind))->first(fn ($f) => $f->getName() === 'target')->getOptions();
        $this->assertArrayHasKey('asset', $targets('consumable'));
        $this->assertArrayHasKey('asset', $targets('accessory'));
        $this->assertArrayNotHasKey('asset', $targets('component'));
        $this->assertSame(['employee', 'department', 'location'], array_keys($targets('component')));
    }

    public function test_create_form_supports_targets_and_pic_and_lists_them(): void
    {
        $this->as($this->staff());
        $a = $this->make('accessory'); $k = $this->make('component');
        $dept = Department::create(['name' => 'Dept Unik Zq40']); $loc = Location::create(['name' => 'Lokasi Unik Yx40']);
        $pic = Employee::where('status', 'active')->firstOrFail();
        Livewire::test(CreateAccessoryHandover::class)->fillForm(['type' => 'checkout', 'target_type' => 'department', 'department_id' => $dept->id,
            'lines' => [['item' => 'accessory:' . $a->id, 'qty' => 2], ['item' => 'component:' . $k->id, 'qty' => 1]]])->call('create')->assertHasNoFormErrors();
        $h = AccessoryHandover::latest('id')->first();
        $this->assertSame(['department', null, 'BSP'], [$h->target_type, $h->employee_id, explode('/', $h->bast_number)[2]]);
        Livewire::test(CreateAccessoryHandover::class)->fillForm(['type' => 'checkout', 'target_type' => 'location', 'location_id' => $loc->id, 'employee_id' => $pic->id,
            'lines' => [['item' => 'accessory:' . $a->id, 'qty' => 1]]])->call('create')->assertHasNoFormErrors();
        $h2 = AccessoryHandover::latest('id')->first();
        $this->assertSame([$loc->id, $pic->id], [$h2->location_id, $h2->pic_id]);
        // wajib mengisi tujuan
        Livewire::test(CreateAccessoryHandover::class)->fillForm(['type' => 'checkout', 'target_type' => 'department', 'lines' => [['item' => 'accessory:' . $a->id, 'qty' => 1]]])->call('create')->assertHasFormErrors(['department_id']);
        Livewire::test(CreateAccessoryHandover::class)->fillForm(['type' => 'checkout', 'target_type' => 'employee', 'lines' => [['item' => 'accessory:' . $a->id, 'qty' => 1]]])->call('create')->assertHasFormErrors(['employee_id']);

        // daftar BAST: kolom tujuan, pencarian dan filter tujuan
        $list = Livewire::test(ListAccessoryHandovers::class);
        $list->assertCanSeeTableRecords([$h, $h2])->searchTable($dept->name)->assertCanSeeTableRecords([$h])->assertCanNotSeeTableRecords([$h2]);
        Livewire::test(ListAccessoryHandovers::class)->filterTable('target_type', 'location')->assertCanSeeTableRecords([$h2])->assertCanNotSeeTableRecords([$h]);
        // PIC boleh membuka dokumennya, orang lain tidak
        $u = $this->plain();
        $u->employee?->update(['user_id' => null]);
        Employee::whereKey($pic->id)->update(['user_id' => $u->id]);
        $this->as($u->fresh())->get(route('accessory-bast.view', $h2))->assertOk();
        $this->get(route('accessory-bast.view', $h))->assertForbidden();
    }

    public function test_history_shows_location_column_and_old_handovers_are_unchanged(): void
    {
        $this->as($this->staff());
        Livewire::test(ConsumableStockHistory::class)->assertTableColumnExists('location.name');
        // BAST lama (karyawan) tetap berlaku: target_type bawaan 'employee' dan recipientLabel = nama karyawan
        $old = AccessoryHandover::whereNotNull('employee_id')->first();
        if ($old) { $this->assertSame('employee', $old->target_type); $this->assertSame($old->employee->name, $old->recipientLabel()); }
        $this->assertTrue(true);
    }
}
