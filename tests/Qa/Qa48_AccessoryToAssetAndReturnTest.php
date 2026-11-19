<?php

namespace Tests\Qa;

use App\Models\Accessory;
use App\Models\Asset;
use App\Models\Component;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Location;
use App\Services\AccessoryHandoverService;
use App\Support\AccessoryBastDocument;
use Tests\QaTestCase;

/** Accessories sebagai kelengkapan perangkat, dan pengembalian dari tujuan non-karyawan (department / lokasi / perangkat). */
class Qa48_AccessoryToAssetAndReturnTest extends QaTestCase
{
    private function svc(): AccessoryHandoverService { return app(AccessoryHandoverService::class); }

    public function test_accessory_can_go_to_asset_and_come_back(): void
    {
        $this->as($this->staff());
        $acc = Accessory::create(['name' => 'Charger T48', 'qty_total' => 10, 'min_qty' => 0]);
        $asset = Asset::firstOrFail();

        $out = $this->svc()->checkout(null, [['item' => 'accessory:' . $acc->id, 'qty' => 3]], null, null, ['type' => 'asset', 'asset_id' => $asset->id]);
        $this->assertSame('asset', $out->target_type);
        $this->assertSame(7, $acc->fresh()->qty_available);
        $this->assertSame(3, $this->svc()->heldAt('asset', $asset->id)->firstWhere(fn ($h) => $h['accessory']->id === $acc->id)['qty']);

        $back = $this->svc()->receiveBack(null, [['item' => 'accessory:' . $acc->id, 'qty' => 2, 'condition' => 'good']], null, ['type' => 'asset', 'asset_id' => $asset->id]);
        $this->assertSame('return', $back->type);
        $this->assertSame('asset', $back->target_type);
        $this->assertSame($asset->id, $back->asset_id);
        $this->assertSame(9, $acc->fresh()->qty_available);
        $this->assertSame(1, $this->svc()->heldAt('asset', $asset->id)->firstWhere(fn ($h) => $h['accessory']->id === $acc->id)['qty']);
        $this->assertNotEmpty(AccessoryBastDocument::build($back->fresh())['rows']);

        $this->expectExceptionMessage('hanya memegang 1 unit');
        $this->svc()->receiveBack(null, [['item' => 'accessory:' . $acc->id, 'qty' => 2]], null, ['type' => 'asset', 'asset_id' => $asset->id]);
    }

    public function test_return_from_department_and_location_is_limited_to_what_went_there(): void
    {
        $this->as($this->staff());
        $acc = Accessory::create(['name' => 'Mouse T48', 'qty_total' => 10, 'min_qty' => 0]);
        $dept = Department::firstOrFail();
        $loc = Location::firstOrFail();
        $this->svc()->checkout(null, [['item' => 'accessory:' . $acc->id, 'qty' => 4]], null, null, ['type' => 'department', 'department_id' => $dept->id]);
        $this->svc()->checkout(null, [['item' => 'accessory:' . $acc->id, 'qty' => 2]], null, null, ['type' => 'location', 'location_id' => $loc->id]);

        $this->svc()->receiveBack(null, [['item' => 'accessory:' . $acc->id, 'qty' => 4]], null, ['type' => 'department', 'department_id' => $dept->id]);
        $this->assertSame(8, $acc->fresh()->qty_available);
        $this->assertTrue($this->svc()->heldAt('department', $dept->id)->isEmpty());

        try {
            $this->svc()->receiveBack(null, [['item' => 'accessory:' . $acc->id, 'qty' => 3]], null, ['type' => 'location', 'location_id' => $loc->id]);
            $this->fail('Pengembalian melebihi yang ada di lokasi harus ditolak.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('hanya memegang 2', $e->getMessage());
        }
        $this->assertSame(8, $acc->fresh()->qty_available);
    }

    public function test_component_cannot_go_to_asset_and_employee_return_still_works(): void
    {
        $this->as($this->staff());
        $comp = Component::create(['name' => 'SSD T48', 'qty_total' => 5, 'min_qty' => 0, 'serial_number' => 'T48-1']);
        try {
            $this->svc()->checkout(null, [['item' => 'component:' . $comp->id, 'qty' => 1]], null, null, ['type' => 'asset', 'asset_id' => Asset::value('id')]);
            $this->fail('Komponen ke perangkat harus ditolak.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Pasang ke Aset', $e->getMessage());
        }

        $acc = Accessory::create(['name' => 'Headset T48', 'qty_total' => 5, 'min_qty' => 0]);
        $emp = Employee::where('status', 'active')->firstOrFail();
        $this->svc()->checkout($emp, [['item' => 'accessory:' . $acc->id, 'qty' => 1]]);
        $this->svc()->receiveBack($emp, [['item' => 'accessory:' . $acc->id, 'qty' => 1]]);
        $this->assertSame(5, $acc->fresh()->qty_available);
        $this->expectExceptionMessage('Pilih karyawan');
        $this->svc()->receiveBack(null, [['item' => 'accessory:' . $acc->id, 'qty' => 1]]);
    }
}
