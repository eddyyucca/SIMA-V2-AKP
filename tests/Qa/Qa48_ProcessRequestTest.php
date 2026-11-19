<?php

namespace Tests\Qa;

use App\Filament\Pages\ProcessRequest;
use App\Models\Accessory;
use App\Models\Asset;
use App\Models\Employee;
use App\Models\ITRequest;
use App\Models\RequestItemLink;
use App\Services\RequestProcessingService;
use Livewire\Livewire;
use Tests\QaTestCase;

/** Pemohon mengisi manual; tim IT mencocokkan dengan database dan memproses (BAST / peminjaman aset). */
class Qa48_ProcessRequestTest extends QaTestCase
{
    private function req(string $type, array $extra = []): ITRequest
    {
        $emp = Employee::where('status', 'active')->firstOrFail();
        return ITRequest::factory()->create(array_merge(['type' => $type, 'status' => 'Approved', 'sequence_number' => random_int(100, 900), 'numbered_at' => now(),
            'requester_name' => $emp->name, 'nik' => $emp->nik, 'title' => 'Butuh perangkat QA'], $extra));
    }

    public function test_only_it_team_can_open_the_process_page(): void
    {
        $r = $this->req('ITR');
        $this->as($this->plain())->get(ProcessRequest::urlFor($r))->assertForbidden();
        $this->as($this->mgmt())->get(ProcessRequest::urlFor($r))->assertForbidden();
        $this->as($this->staff())->get(ProcessRequest::urlFor($r))->assertOk()->assertSee('Barang dari database');
        $this->as($this->admin())->get(ProcessRequest::urlFor($r))->assertOk();
    }

    public function test_employee_is_suggested_from_nik_and_links_are_saved_and_audited(): void
    {
        $this->as($this->staff());
        $emp = Employee::where('status', 'active')->firstOrFail();
        $r = $this->req('ITR', ['user_id' => null]);
        $acc = Accessory::create(['name' => 'Mouse Link', 'qty_total' => 5, 'min_qty' => 0]);
        $page = Livewire::withQueryParams(['request' => $r->id])->test(ProcessRequest::class)->assertSet('employeeId', $emp->id);
        $page->set('links.0.kind', 'accessory')->set('links.0.item_id', $acc->id)->set('links.0.qty', 2)->set('links.0.note', 'dari gudang')
            ->set('fields.position', 'Staf Baru')->call('save')->assertHasNoErrors();
        $r->refresh();
        $this->assertSame($emp->id, $r->employee_id);
        $this->assertSame('Staf Baru', $r->position);
        $this->assertSame(1, $r->links()->count());
        $this->assertSame(2.0, (float) $r->links()->first()->qty);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $r->id, 'description' => 'Request dicocokkan dengan data IT Asset']);
        // duplikat ditolak
        $this->expectException(\InvalidArgumentException::class);
        app(RequestProcessingService::class)->save($r, [], $emp->id, [['kind' => 'accessory', 'item_id' => $acc->id, 'qty' => 1], ['kind' => 'accessory', 'item_id' => $acc->id, 'qty' => 1]]);
    }

    public function test_invalid_links_are_rejected_with_clear_messages(): void
    {
        $this->as($this->staff());
        $r = $this->req('ITR');
        $svc = app(RequestProcessingService::class);
        foreach ([[['kind' => 'accessory', 'item_id' => null, 'qty' => 1], 'Pilih barang'], [['kind' => 'accessory', 'item_id' => Accessory::create(['name' => 'X', 'qty_total' => 1, 'min_qty' => 0])->id, 'qty' => 0], 'lebih dari 0'],
                  [['kind' => 'xx', 'item_id' => 1, 'qty' => 1], 'jenis barang']] as [$row, $msg]) {
            try { $svc->save($r, [], null, [$row]); $this->fail('harus ditolak'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString($msg, $e->getMessage()); }
        }
    }

    public function test_loan_links_asset_borrows_it_and_return_frees_it(): void
    {
        $this->as($this->staff());
        $emp = Employee::where('status', 'active')->firstOrFail();
        $asset = $this->freeAsset();
        $r = $this->req('PEMINJAMAN_ASET', ['loan_start_date' => now(), 'loan_end_date' => now()->addDays(3)]);
        $page = Livewire::withQueryParams(['request' => $r->id])->test(ProcessRequest::class);
        $page->set('links.0.item_id', $asset->id)->call('saveAndBorrow')->assertHasNoErrors()->assertNotified('1 aset dipinjamkan');
        $asset->refresh();
        $this->assertSame('borrowed', $asset->status);
        $this->assertSame($emp->id, (int) $asset->employee_id);
        $link = $r->links()->first();
        $this->assertNotNull($link->processed_at);
        $this->assertNotNull($link->assignment_id);
        // aset yang sedang dipinjam tidak bisa ditautkan ke request lain
        $r2 = $this->req('PEMINJAMAN_ASET');
        $this->expectExceptionMessage('Tersedia');
        try {
            app(RequestProcessingService::class)->save($r2, [], $emp->id, [['kind' => 'asset', 'item_id' => $asset->id, 'qty' => 1]]);
            app(RequestProcessingService::class)->borrow($r2);
        } finally {
            // tandai kembali -> aset Tersedia lagi
            $this->as($this->staff())->patch(route('peminjaman.mark-returned', $r->id))->assertRedirect();
            $this->assertSame('available', $asset->fresh()->status);
            $this->assertNull($asset->fresh()->employee_id);
            $this->assertNotNull($r->fresh()->returned_at);
        }
    }

    public function test_borrowed_link_cannot_be_removed_and_requires_employee_and_approval(): void
    {
        $this->as($this->staff());
        $asset = $this->freeAsset();
        $svc = app(RequestProcessingService::class);
        $pending = $this->req('PEMINJAMAN_ASET', ['status' => 'Pending']);
        $svc->save($pending, [], null, [['kind' => 'asset', 'item_id' => $asset->id, 'qty' => 1]]);
        try { $svc->borrow($pending); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Approved', $e->getMessage()); }
        $ok = $this->req('PEMINJAMAN_ASET');
        $svc->save($ok, [], null, [['kind' => 'asset', 'item_id' => $asset->id, 'qty' => 1]]);
        try { $svc->borrow($ok); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('karyawan', $e->getMessage()); }
        $emp = Employee::where('status', 'active')->firstOrFail();
        $svc->save($ok, [], $emp->id, [['kind' => 'asset', 'item_id' => $asset->id, 'qty' => 1]]);
        $this->assertSame(1, $svc->borrow($ok));
        try { $svc->save($ok, [], $emp->id, []); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('sudah diproses', $e->getMessage()); }
        $svc->release($ok);
        $this->assertSame('available', $asset->fresh()->status);
    }

    public function test_itr_with_links_prefills_the_handover_form(): void
    {
        $this->as($this->staff());
        $emp = Employee::where('status', 'active')->firstOrFail();
        $acc = Accessory::create(['name' => 'Headset Link', 'qty_total' => 5, 'min_qty' => 0]);
        $r = $this->req('ITR', ['status' => 'In Progress']);
        app(RequestProcessingService::class)->save($r, [], $emp->id, [['kind' => 'accessory', 'item_id' => $acc->id, 'qty' => 2]]);
        $form = Livewire::withQueryParams(['request' => $r->id])->test(\App\Filament\Resources\AccessoryHandovers\Pages\CreateAccessoryHandover::class);
        $form->assertFormSet(['employee_id' => $emp->id, 'request_id' => $r->id]);
        $this->assertSame('accessory:' . $acc->id, $form->get('data.lines')[array_key_first($form->get('data.lines'))]['item']);
    }
}
