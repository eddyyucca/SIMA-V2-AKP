<?php

namespace Tests\Requisition;

use App\Models\Employee;
use App\Models\ITRequest;
use App\Models\User;
use App\Services\RequestSupervisor;
use Illuminate\Support\Facades\Notification;
use Tests\RequisitionTestCase;

/** Persetujuan atasan (HRIS supervisor_nik) sebelum request ITR / Peminjaman Aset masuk ke Tim IT, plus pengisian otomatis pemohon. */
class SupervisorApprovalTest extends RequisitionTestCase
{
    private User $boss; private User $worker; private Employee $bossEmp; private Employee $workerEmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->boss = $this->makeUser('user', ['name' => 'Atasan Satu']);
        $this->worker = $this->makeUser('user', ['name' => 'Karyawan Dua']);
        $this->bossEmp = $this->makeRequester(['name' => 'Atasan Satu', 'nik' => '9000001', 'user_id' => $this->boss->id]);
        $this->workerEmp = $this->makeRequester(['name' => 'Karyawan Dua', 'nik' => '9000002', 'user_id' => $this->worker->id, 'supervisor_nik' => '9000001']);
    }

    private function itr(array $over = []): array
    {
        return $over + ['type' => 'ITR', 'title' => 'Mouse baru', 'description' => 'Mouse rusak', 'items' => [['name' => 'Mouse', 'qty' => 1]]];
    }

    public function test_user_request_is_prefilled_for_self_and_cannot_be_filed_for_a_stranger(): void
    {
        $html = $this->actingAs($this->worker)->get('/requisition/requests/create')->assertOk()->getContent();
        $this->assertStringContainsString('data-ep-self', $html);
        $this->assertStringContainsString('data-ep-fixed', $html);       // tanpa anak buah: terkunci

        $other = $this->makeRequester(['name' => 'Orang Lain', 'nik' => '9000099']);
        $this->post('/requisition/requests', $this->itr(['employee_id' => $other->id]))->assertForbidden();

        // tanpa employee_id: pemohon = dirinya sendiri
        $this->post('/requisition/requests', $this->itr())->assertRedirect();
        $this->assertSame($this->workerEmp->id, ITRequest::latest('id')->first()->employee_id);
    }

    public function test_itr_goes_to_supervisor_first_then_it_team_after_approval(): void
    {
        Notification::fake();
        $this->actingAs($this->worker)->post('/requisition/requests', $this->itr())->assertSessionHas('success');
        $r = ITRequest::latest('id')->first();
        $this->assertSame(ITRequest::AWAITING_SUPERVISOR, $r->status);
        $this->assertSame($this->bossEmp->id, $r->supervisor_employee_id);
        Notification::assertNotSentTo(User::requisitionAdmins()->get(), \App\Notifications\NewRequestSubmitted::class);   // Tim IT belum dikabari

        // belum muncul di Incoming Requests Tim IT
        $admin = $this->makeUser('admin');
        $this->loginAs($admin)->getJson('/requisition/requests/pending-approvals')->assertOk()->assertJsonPath('count', 0);

        // orang lain tidak boleh memutuskan; atasan boleh
        $this->loginAs($this->worker)->post("/requisition/atasan/{$r->id}", ['decision' => 'approve'])->assertForbidden();
        $this->loginAs($this->boss)->get('/requisition/atasan')->assertOk()->assertSee('Mouse baru');
        $this->post("/requisition/atasan/{$r->id}", ['decision' => 'approve'])->assertRedirect();

        $r->refresh();
        $this->assertSame('Pending', $r->status);
        $this->assertSame('approved', $r->supervisor_decision);
        $this->loginAs($admin)->getJson('/requisition/requests/pending-approvals')->assertJsonPath('count', 1);
        Notification::assertSentTo($this->worker, \App\Notifications\RequestStatusUpdated::class);
        // keputusan kedua ditolak: sudah diputuskan
        $this->loginAs($this->boss)->postJson("/requisition/atasan/{$r->id}", ['decision' => 'reject', 'note' => 'x'])->assertStatus(409);
    }

    public function test_rejection_needs_a_reason_returns_to_owner_and_resubmission_goes_back_to_supervisor(): void
    {
        $this->actingAs($this->worker)->post('/requisition/requests', $this->itr());
        $r = ITRequest::latest('id')->first();

        $this->loginAs($this->boss)->postJson("/requisition/atasan/{$r->id}", ['decision' => 'reject'])->assertStatus(422);
        $this->postJson("/requisition/atasan/{$r->id}", ['decision' => 'reject', 'note' => 'Sudah ada stok mouse'])->assertOk();
        $r->refresh();
        $this->assertSame('Rejected', $r->status);
        $this->assertStringContainsString('Sudah ada stok mouse', (string) $r->update_note);

        $this->loginAs($this->worker)->patch("/requisition/requests/{$r->id}", [
            'employee_name' => 'Karyawan Dua', 'employee_id' => $this->workerEmp->id, 'employee_nik' => '9000002', 'position' => 'Staff IT',
            'title' => 'Mouse baru (revisi)', 'description' => 'Mouse rusak, stok kosong', 'items' => [['name' => 'Mouse', 'qty' => 1]],
        ])->assertRedirect();
        $r->refresh();
        $this->assertSame(ITRequest::AWAITING_SUPERVISOR, $r->status, 'kirim ulang kembali ke atasan');
        $this->assertNull($r->supervisor_note);
    }

    public function test_requests_without_supervisor_or_of_other_types_skip_the_supervisor(): void
    {
        $this->workerEmp->update(['supervisor_nik' => null]);
        $this->actingAs($this->worker)->post('/requisition/requests', $this->itr());
        $this->assertSame('Pending', ITRequest::latest('id')->first()->status);

        $this->workerEmp->update(['supervisor_nik' => '9000001']);
        $this->post('/requisition/requests', ['type' => 'TICKET_SISTEM', 'category' => 'Hardware', 'title' => 'Printer', 'description' => 'macet', 'admin_notes' => 'Lantai 2']);
        $this->assertSame('Pending', ITRequest::latest('id')->first()->status, 'Ticket Sistem tidak lewat atasan');

        // atasan mengajukan sendiri: tidak minta persetujuan dirinya
        $this->loginAs($this->boss)->post('/requisition/requests', $this->itr());
        $this->assertSame('Pending', ITRequest::latest('id')->first()->status);
    }

    public function test_supervisor_can_file_for_a_subordinate_and_only_for_them(): void
    {
        $this->actingAs($this->boss)->get('/requisition/requests/create')->assertOk()->assertDontSee('data-ep-fixed', false);
        $this->post('/requisition/requests', $this->itr(['employee_id' => $this->workerEmp->id]))->assertRedirect();
        $this->assertSame($this->workerEmp->id, ITRequest::latest('id')->first()->employee_id);
        $this->assertSame('Pending', ITRequest::latest('id')->first()->status, 'diajukan atasan sendiri: langsung ke Tim IT');

        $emps = $this->getJson('/requisition/lookup/employees?q=Karyawan')->json('data');
        $this->assertSame(['Karyawan Dua'], array_column($emps, 'name'));
        $this->assertSame([], $this->getJson('/requisition/lookup/employees?q=Budi')->json('data'));
    }

    public function test_history_and_my_requests_only_show_own_and_subordinate_requests(): void
    {
        $mine = ITRequest::factory()->create(['user_id' => $this->worker->id, 'employee_id' => $this->workerEmp->id, 'title' => 'Punya Karyawan Dua', 'status' => 'Pending', 'sequence_number' => null]);
        ITRequest::factory()->create(['user_id' => null, 'title' => 'Punya Orang Lain', 'status' => 'Pending', 'sequence_number' => null]);

        $this->actingAs($this->worker)->getJson('/requisition/requests/history')->assertOk()->assertSee('Punya Karyawan Dua')->assertDontSee('Punya Orang Lain');
        $this->get('/requisition/saya')->assertOk()->assertSee('Punya Karyawan Dua')->assertDontSee('Punya Orang Lain');
        $this->get('/requisition/saya?status=Completed')->assertOk()->assertDontSee('Punya Karyawan Dua');
        // atasan melihat milik anak buah
        $this->loginAs($this->boss)->get('/requisition/saya')->assertOk()->assertSee('Punya Karyawan Dua');
        // Tim IT melihat semua
        $this->loginAs($this->makeUser('admin'))->getJson('/requisition/requests/history')->assertSee('Punya Orang Lain');
    }

    public function test_stale_requests_are_escalated_to_it_automatically(): void
    {
        $this->actingAs($this->worker)->post('/requisition/requests', $this->itr());
        $r = ITRequest::latest('id')->first();
        $this->assertSame(0, app(RequestSupervisor::class)->escalate(), 'belum lewat batas');

        $r->forceFill(['supervisor_asked_at' => now()->subDays(3)])->save();
        $this->artisan('requisition:escalate-supervisor')->assertSuccessful();
        $r->refresh();
        $this->assertSame('Pending', $r->status);
        $this->assertSame('escalated', $r->supervisor_decision);
    }

    public function test_navigation_shows_supervisor_menu_only_to_those_with_subordinates(): void
    {
        $this->actingAs($this->boss)->get('/requisition/requests/create')->assertSee('Persetujuan Atasan')->assertSee('Request Saya');
        $this->loginAs($this->worker)->get('/requisition/requests/create')->assertDontSee('Persetujuan Atasan')->assertSee('Request Saya');
        $this->get('/requisition/atasan')->assertOk();   // tanpa anak buah halaman tetap terbuka (kosong)
    }
}
