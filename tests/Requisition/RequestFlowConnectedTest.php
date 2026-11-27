<?php

namespace Tests\Requisition;

use App\Models\Accessory;
use App\Models\Asset;
use App\Models\Employee;
use App\Models\ITRequest;
use App\Models\RequestItemLink;
use App\Services\AccessoryHandoverService;
use App\Services\AssetService;
use App\Services\RequestProcessingService;
use Tests\RequisitionTestCase;

/** Alur ITERA tersambung dengan IT Asset: pemrosesan (BAST, pinjam, kembali, perbaikan) menggerakkan status request dan mengabari pemohon. */
class RequestFlowConnectedTest extends RequisitionTestCase
{
    private function person(): array
    {
        $u = $this->makeUser('user');
        $e = $this->linkEmployee($u, ['name' => 'Pemohon Alur', 'email' => $u->email, 'nik' => 'FL' . $u->id, 'status' => 'active']);
        return [$u, $e];
    }

    private function req(string $type, $u, Employee $e, array $over = []): ITRequest
    {
        return ITRequest::factory()->create($over + ['type' => $type, 'user_id' => $u->id, 'employee_id' => $e->id, 'status' => 'Approved', 'sequence_number' => random_int(1, 90000),
            'numbered_at' => now(), 'title' => 'Alur ' . $type, 'description' => 'uji alur', 'items' => null]);
    }

    private function inbox($u): array { return $u->notifications()->pluck('data')->map(fn ($d) => $d['title'] ?? '')->all(); }

    public function test_handover_moves_itr_through_in_progress_to_completed_and_notifies_owner(): void
    {
        $this->loginAs($this->itStaff());
        [$u, $e] = $this->person();
        $mouse = Accessory::create(['name' => 'Mouse Alur', 'qty_total' => 10, 'min_qty' => 0]);
        $kb = Accessory::create(['name' => 'Keyboard Alur', 'qty_total' => 10, 'min_qty' => 0]);
        $r = $this->req('ITR', $u, $e);
        foreach ([$mouse, $kb] as $a) $r->links()->create(['kind' => 'accessory', 'item_id' => $a->id, 'qty' => 1]);

        $h1 = app(AccessoryHandoverService::class)->checkout($e, [['accessory_id' => $mouse->id, 'qty' => 1]], $r->id);
        $this->assertSame('In Progress', $r->fresh()->status, 'sebagian barang diserahkan');
        $this->assertNotNull($r->links()->where('item_id', $mouse->id)->first()->processed_at);
        $this->assertNull($r->links()->where('item_id', $kb->id)->first()->processed_at);

        app(AccessoryHandoverService::class)->checkout($e, [['accessory_id' => $kb->id, 'qty' => 1]], $r->id);
        $r->refresh();
        $this->assertSame('Completed', $r->status, 'semua barang tertaut sudah diserahkan');
        $this->assertNotNull($r->completed_at);
        $this->assertContains('Semua barang sudah diserahkan', $this->inbox($u));

        // pemohon melihat BAST di Request Saya
        $this->loginAs($u)->get('/requisition/saya')->assertOk()->assertSee('BAST ' . $h1->bast_number);
    }

    public function test_loan_flow_in_progress_when_lent_completed_when_returned(): void
    {
        $this->loginAs($this->itStaff());
        [$u, $e] = $this->person();
        $asset = Asset::where('status', 'available')->whereNull('employee_id')->orderBy('id')->firstOrFail();
        $r = $this->req('PEMINJAMAN_ASET', $u, $e, ['loan_start_date' => now(), 'loan_end_date' => now()->addDays(2)]);
        $r->links()->create(['kind' => 'asset', 'item_id' => $asset->id, 'qty' => 1]);

        app(RequestProcessingService::class)->borrow($r);
        $this->assertSame('In Progress', $r->fresh()->status);
        $this->assertSame('borrowed', $asset->fresh()->status);

        $this->patch("/requisition/peminjaman/{$r->id}/returned")->assertRedirect();
        $this->assertSame('Completed', $r->fresh()->status);
        $this->assertSame('available', $asset->fresh()->status);
        $this->assertContains('Peminjaman selesai', $this->inbox($u));
    }

    public function test_damage_report_creates_repair_ticket_and_completes_when_repair_is_done(): void
    {
        $this->loginAs($this->itStaff());
        [$u, $e] = $this->person();
        $asset = Asset::where('status', 'available')->whereNull('employee_id')->orderBy('id')->firstOrFail();
        $r = $this->req('BAK', $u, $e, ['analisa' => 'a', 'rekomendasi' => 'b']);
        $r->links()->create(['kind' => 'asset', 'item_id' => $asset->id, 'qty' => 1]);

        $this->assertSame(1, app(RequestProcessingService::class)->report($r));
        $repair = $asset->repairs()->latest('id')->first();
        $this->assertNotNull($repair);
        $this->assertSame('repair', $asset->fresh()->status);
        $this->assertSame('In Progress', $r->fresh()->status);
        $this->assertSame($repair->id, $r->links()->first()->repair_id);

        app(AssetService::class)->finishRepair($repair, []);
        $this->assertSame('Completed', $r->fresh()->status);
        $this->assertContains('Perbaikan selesai', $this->inbox($u));

        // tidak bisa dibuat dua kali & jenis lain ditolak
        $this->expectException(\InvalidArgumentException::class);
        app(RequestProcessingService::class)->report($r);
    }

    public function test_pending_or_rejected_requests_are_not_advanced_and_status_change_notifies_in_app(): void
    {
        $this->loginAs($this->itStaff());
        [$u, $e] = $this->person();
        $mouse = Accessory::create(['name' => 'Mouse X', 'qty_total' => 5, 'min_qty' => 0]);
        $r = $this->req('ITR', $u, $e, ['status' => 'Pending', 'sequence_number' => null, 'numbered_at' => null]);
        $this->expectException(\InvalidArgumentException::class);     // belum disetujui: BAST tidak boleh dikaitkan
        try { app(AccessoryHandoverService::class)->checkout($e, [['accessory_id' => $mouse->id, 'qty' => 1]], $r->id); }
        finally { $this->assertSame('Pending', $r->fresh()->status); }
    }

    public function test_it_changing_status_sends_an_in_app_notification_to_the_requester(): void
    {
        $this->loginAs($this->makeUser('admin'));
        [$u, $e] = $this->person();
        $r = $this->req('TICKET_SISTEM', $u, $e, ['status' => 'Pending', 'sequence_number' => null, 'numbered_at' => null, 'category' => 'Hardware', 'admin_notes' => 'Lt 2']);
        $this->patchJson("/requisition/requests/{$r->id}/status", ['status' => 'Approved'])->assertOk();
        $this->assertContains('Request disetujui Tim IT', $this->inbox($u));
    }

    public function test_it_dashboard_shows_how_many_requests_wait_for_a_supervisor(): void
    {
        [$u, $e] = $this->person();
        $this->req('ITR', $u, $e, ['status' => ITRequest::AWAITING_SUPERVISOR, 'sequence_number' => null, 'numbered_at' => null]);
        $this->loginAs($this->makeUser('admin'))->get('/requisition')->assertOk()->assertSee('masih menunggu persetujuan atasan');
    }
}
