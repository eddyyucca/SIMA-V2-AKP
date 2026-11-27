<?php

namespace Tests\Requisition;

use App\Models\Employee;
use App\Models\ITRequest;
use App\Models\User;
use Tests\RequisitionTestCase;

/** Pemohon request selalu karyawan akun login; semua request langsung ke Tim IT (tanpa persetujuan atasan). */
class RequesterLockTest extends RequisitionTestCase
{
    private User $boss; private User $worker; private Employee $bossEmp; private Employee $workerEmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->boss = $this->makeUser('user', ['name' => 'Atasan Satu']);
        $this->worker = $this->makeUser('user', ['name' => 'Karyawan Dua']);
        $this->bossEmp = $this->linkEmployee($this->boss, ['name' => 'Atasan Satu', 'nik' => '9000001']);
        $this->workerEmp = $this->linkEmployee($this->worker, ['name' => 'Karyawan Dua', 'nik' => '9000002', 'supervisor_nik' => '9000001']);
    }

    private function itr(array $over = []): array
    {
        return $over + ['type' => 'ITR', 'title' => 'Mouse baru', 'description' => 'Mouse rusak', 'items' => [['name' => 'Mouse', 'qty' => 1]]];
    }

    public function test_requester_is_always_the_logged_in_employee_and_cannot_be_changed(): void
    {
        $html = $this->actingAs($this->worker)->get('/requisition/requests/create')->assertOk()->getContent();
        $this->assertStringContainsString('data-ep-self', $html);
        $this->assertStringContainsString('data-ep-fixed', $html);

        $other = $this->makeRequester(['name' => 'Orang Lain', 'nik' => '9000099']);
        $this->post('/requisition/requests', $this->itr(['employee_id' => $other->id, 'employee_name' => 'Orang Lain']))->assertRedirect();
        $r = ITRequest::latest('id')->first();
        $this->assertSame($this->workerEmp->id, $r->employee_id);
        $this->assertSame('Karyawan Dua', $r->requester_name);

        $orphan = $this->makeUser('user', ['no_employee' => true]);
        $this->loginAs($orphan)->post('/requisition/requests', $this->itr())->assertForbidden();
    }

    public function test_every_request_goes_straight_to_the_it_team_even_when_the_requester_has_a_supervisor(): void
    {
        foreach (['ITR', 'PEMINJAMAN_ASET'] as $type) {
            $payload = $type === 'ITR' ? $this->itr() : ['type' => $type, 'title' => 'Pinjam proyektor', 'description' => 'Rapat', 'loan_start_date' => now()->toDateString(), 'loan_end_date' => now()->addDay()->toDateString()];
            $this->actingAs($this->worker)->post('/requisition/requests', $payload)->assertSessionHas('success');
            $r = ITRequest::latest('id')->first();
            $this->assertSame('Pending', $r->status, $type);
        }
        // Tim IT langsung melihatnya di Incoming Requests
        $this->loginAs($this->makeUser('admin'))->getJson('/requisition/requests/pending-approvals')->assertOk()->assertJsonPath('count', 2);
        // Tim IT dikabari dalam aplikasi
        $titles = \App\Models\User::requisitionAdmins()->get()->flatMap(fn ($u) => $u->notifications->pluck('data.title'))->all();
        $this->assertContains('Request baru menunggu peninjauan', $titles);
        // halaman persetujuan atasan sudah tidak ada
        $this->loginAs($this->boss)->get('/requisition/atasan')->assertNotFound();
    }

    public function test_it_team_reviews_and_rejection_returns_to_the_owner_who_can_resubmit_to_it(): void
    {
        $this->actingAs($this->worker)->post('/requisition/requests', $this->itr());
        $r = ITRequest::latest('id')->first();
        $admin = $this->makeUser('admin');
        $this->loginAs($admin)->patchJson("/requisition/requests/{$r->id}/status", ['status' => 'Rejected'])->assertOk();
        $this->assertSame('Rejected', $r->fresh()->status);

        $this->loginAs($this->worker)->patch("/requisition/requests/{$r->id}", [
            'employee_name' => 'Karyawan Dua', 'employee_id' => $this->workerEmp->id, 'employee_nik' => '9000002', 'position' => 'Staff IT',
            'title' => 'Mouse baru (revisi)', 'description' => 'Mouse rusak, stok kosong', 'items' => [['name' => 'Mouse', 'qty' => 1]],
        ])->assertRedirect();
        $this->assertSame('Pending', $r->fresh()->status, 'dikirim ulang ke Tim IT');
    }

    public function test_nobody_can_file_for_another_employee_or_search_other_employees(): void
    {
        $this->actingAs($this->boss)->get('/requisition/requests/create')->assertOk()->assertSee('data-ep-fixed', false);
        $this->post('/requisition/requests', $this->itr(['employee_id' => $this->workerEmp->id]))->assertRedirect();
        $this->assertSame($this->bossEmp->id, ITRequest::latest('id')->first()->employee_id);

        $this->assertSame([], $this->getJson('/requisition/lookup/employees?q=Karyawan')->json('data'));
        $this->assertSame(['Atasan Satu'], array_column($this->getJson('/requisition/lookup/employees?q=Atasan')->json('data'), 'name'));
    }

    public function test_history_and_my_requests_only_show_own_requests(): void
    {
        ITRequest::factory()->create(['user_id' => $this->worker->id, 'employee_id' => $this->workerEmp->id, 'title' => 'Punya Karyawan Dua', 'status' => 'Pending', 'sequence_number' => null]);
        ITRequest::factory()->create(['user_id' => null, 'title' => 'Punya Orang Lain', 'status' => 'Pending', 'sequence_number' => null]);

        $this->actingAs($this->worker)->getJson('/requisition/requests/history')->assertOk()->assertSee('Punya Karyawan Dua')->assertDontSee('Punya Orang Lain');
        $this->get('/requisition/saya')->assertOk()->assertSee('Punya Karyawan Dua')->assertDontSee('Punya Orang Lain');
        $this->get('/requisition/saya?status=Completed')->assertOk()->assertDontSee('Punya Karyawan Dua');
        $this->loginAs($this->boss)->get('/requisition/saya')->assertOk()->assertDontSee('Punya Karyawan Dua');
        $this->loginAs($this->makeUser('admin'))->getJson('/requisition/requests/history')->assertSee('Punya Orang Lain');
    }

    public function test_navigation_has_no_supervisor_menu_for_anyone(): void
    {
        foreach ([$this->boss, $this->worker] as $u) {
            $this->loginAs($u)->get('/requisition/requests/create')->assertOk()->assertDontSee('Persetujuan Atasan')->assertSee('Request Saya');
        }
    }

    public function test_requests_left_awaiting_a_supervisor_are_moved_to_the_it_queue_by_the_migration(): void
    {
        $r = ITRequest::factory()->create(['user_id' => $this->worker->id, 'status' => 'Awaiting Supervisor', 'sequence_number' => null]);
        (include database_path('migrations/2026_12_15_000001_end_supervisor_stage_of_requests.php'))->up();
        $this->assertSame('Pending', $r->fresh()->status);
    }
}
