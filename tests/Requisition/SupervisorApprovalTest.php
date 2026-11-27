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
        $this->assertStringContainsString('data-ep-fixed', $html);       // kotak nama terkunci

        // employee_id dari browser diabaikan: pemohon tetap karyawan akun login
        $other = $this->makeRequester(['name' => 'Orang Lain', 'nik' => '9000099']);
        $this->post('/requisition/requests', $this->itr(['employee_id' => $other->id, 'employee_name' => 'Orang Lain']))->assertRedirect();
        $r = ITRequest::latest('id')->first();
        $this->assertSame($this->workerEmp->id, $r->employee_id);
        $this->assertSame('Karyawan Dua', $r->requester_name);

        // akun User tanpa karyawan tertaut tidak bisa mengajukan
        $orphan = $this->makeUser('user', ['no_employee' => true]);
        $this->loginAs($orphan)->post('/requisition/requests', $this->itr())->assertForbidden();
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

    public function test_even_a_supervisor_cannot_file_for_a_subordinate_or_search_other_employees(): void
    {
        $this->actingAs($this->boss)->get('/requisition/requests/create')->assertOk()->assertSee('data-ep-fixed', false);
        $this->post('/requisition/requests', $this->itr(['employee_id' => $this->workerEmp->id]))->assertRedirect();
        $r = ITRequest::latest('id')->first();
        $this->assertSame($this->bossEmp->id, $r->employee_id, 'atasan hanya bisa mengajukan atas namanya sendiri');
        $this->assertSame('Pending', $r->status, 'atasan sendiri: langsung ke Tim IT');

        $this->assertSame([], $this->getJson('/requisition/lookup/employees?q=Karyawan')->json('data'));
        $this->assertSame(['Atasan Satu'], array_column($this->getJson('/requisition/lookup/employees?q=Atasan')->json('data'), 'name'));
    }

    public function test_history_and_my_requests_only_show_own_requests(): void
    {
        $mine = ITRequest::factory()->create(['user_id' => $this->worker->id, 'employee_id' => $this->workerEmp->id, 'title' => 'Punya Karyawan Dua', 'status' => 'Pending', 'sequence_number' => null]);
        ITRequest::factory()->create(['user_id' => null, 'title' => 'Punya Orang Lain', 'status' => 'Pending', 'sequence_number' => null]);

        $this->actingAs($this->worker)->getJson('/requisition/requests/history')->assertOk()->assertSee('Punya Karyawan Dua')->assertDontSee('Punya Orang Lain');
        $this->get('/requisition/saya')->assertOk()->assertSee('Punya Karyawan Dua')->assertDontSee('Punya Orang Lain');
        $this->get('/requisition/saya?status=Completed')->assertOk()->assertDontSee('Punya Karyawan Dua');
        // atasan tidak melihat milik anak buah di Request Saya (hanya lewat halaman Persetujuan Atasan)
        $this->loginAs($this->boss)->get('/requisition/saya')->assertOk()->assertDontSee('Punya Karyawan Dua');
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

    public function test_with_sso_a_supervisor_who_never_logged_in_gets_an_account_and_sees_the_request_after_portal_login(): void
    {
        config(['sso.enabled' => true, 'sso.secret' => 'rahasia-uji-sso-0123456789abcdef0123456789abcdef', 'sso.app_code' => 'asset', 'sso.portal_url' => 'http://portal.test']);
        $bossEmp = $this->makeRequester(['name' => 'Atasan Baru', 'nik' => '9100001']);          // belum punya akun
        $this->assertNull($bossEmp->user_id);
        $this->workerEmp->update(['supervisor_nik' => '9100001']);

        $this->actingAs($this->worker)->withSession(['sso.local' => true])->post('/requisition/requests', $this->itr())->assertSessionHas('success');
        $r = ITRequest::latest('id')->first();
        $this->assertSame(ITRequest::AWAITING_SUPERVISOR, $r->status);
        $bossEmp->refresh();
        $this->assertNotNull($bossEmp->user_id, 'akun atasan dibuat otomatis');
        $this->assertTrue($bossEmp->user->hasRole('User'));
        $this->assertNotEmpty($bossEmp->user->notifications()->get(), 'atasan sudah menerima notifikasi');

        // atasan masuk lewat Portal: dikenali lewat NIK (akun yang sama), langsung melihat request
        auth()->forgetGuards(); $this->flushSession();
        $now = time();
        $token = \Firebase\JWT\JWT::encode(['iss' => 'http://portal.test', 'portal' => 'http://portal.test', 'aud' => 'asset', 'sub' => '501', 'iat' => $now, 'nbf' => $now, 'exp' => $now + 60,
            'jti' => (string) \Illuminate\Support\Str::uuid(), 'usr' => '9100001', 'emp' => '9100001', 'name' => 'Atasan Baru', 'email' => 'atasan.baru@akp.test', 'role' => 'user', 'level' => 10, 'admin' => false],
            'rahasia-uji-sso-0123456789abcdef0123456789abcdef', 'HS256', 'asset');
        $this->get('/sso/callback?token=' . urlencode($token))->assertRedirect('/admin');
        $this->assertSame($bossEmp->user_id, auth()->id(), 'tidak membuat akun ganda');
        $this->assertSame(1, User::where('portal_user_id', '501')->count());
        $this->get('/requisition/atasan')->assertOk()->assertSee('Mouse baru');
    }

    public function test_without_sso_no_account_is_created_for_a_supervisor_and_request_goes_straight_to_it(): void
    {
        config(['sso.enabled' => false]);
        $bossEmp = $this->makeRequester(['name' => 'Atasan Lokal', 'nik' => '9100002']);
        $this->workerEmp->update(['supervisor_nik' => '9100002']);
        $this->actingAs($this->worker)->post('/requisition/requests', $this->itr());
        $this->assertSame('Pending', ITRequest::latest('id')->first()->status);
        $this->assertNull($bossEmp->fresh()->user_id);
    }
}
