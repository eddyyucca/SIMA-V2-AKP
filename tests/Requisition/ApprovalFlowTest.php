<?php

namespace Tests\Requisition;

use App\Models\ITRequest;
use App\Models\User;
use Tests\RequisitionTestCase;

class ApprovalFlowTest extends RequisitionTestCase
{

    public function test_dashboard_hides_the_admin_notes_field_by_default_and_labels_it_lokasi(): void
    {
        // admin_notes cuma dipakai untuk Ticket Sistem (sebagai field "Lokasi", wajib) - untuk tipe
        // lain field ini disembunyikan total lewat class "hidden" (bukan ditampilkan sebagai
        // "Keterangan (Opsional)" yang tidak berguna). JS yang menampilkannya lagi khusus TICKET_SISTEM.
        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin)->get('/requisition');
        $html = $response->getContent();

        $this->assertStringNotContainsString('Keterangan (Opsional)', $html);
        $this->assertStringContainsString('id="editAdminNotesLabel" class="block text-xs font-bold text-slate-300 uppercase mb-2">Lokasi</label>', $html);
        $this->assertMatchesRegularExpression('/id="editAdminNotesWrapper" class="hidden"/', $html);
    }

    public function test_incoming_requests_keterangan_reject_and_approve_are_disabled_until_viewed(): void
    {
        // Tim IT wajib klik "View" dulu sebelum bisa Keterangan/Reject/Approve - tombol-tombol itu
        // sengaja dirender `disabled` dari server; JS (markViewed/applyViewGate) yang menyalakannya
        // lagi setelah View item itu diklik. Lihat resources/views/partials/pending-approvals-rows.blade.php.
        $admin = $this->makeUser('admin');
        ITRequest::factory()->create(['type' => 'ITR', 'status' => 'Pending', 'sequence_number' => null]);

        $response = $this->actingAs($admin)->getJson('/requisition/requests/pending-approvals');
        $rowsHtml = $response->json('rows_html');

        $this->assertMatchesRegularExpression('/view-gated[^>]*disabled/', $rowsHtml);
        $this->assertStringContainsString('markViewed(', $rowsHtml);
    }

    public function test_pending_approvals_endpoint_returns_pending_gated_requests(): void
    {
        $admin = $this->makeUser('admin');
        ITRequest::factory()->create(['type' => 'ITR', 'status' => 'Pending', 'sequence_number' => null, 'title' => 'Butuh Approval Ini']);
        ITRequest::factory()->create(['type' => 'ITR', 'status' => 'Approved', 'title' => 'Sudah Disetujui']);

        $response = $this->actingAs($admin)->getJson('/requisition/requests/pending-approvals');

        $response->assertOk();
        $response->assertJsonStructure(['rows_html', 'count', 'items' => [['id', 'type', 'title', 'requester_name']]]);
        $this->assertEquals(1, $response->json('count'));
        $this->assertStringContainsString('Butuh Approval Ini', $response->json('rows_html'));
        $this->assertStringNotContainsString('Sudah Disetujui', $response->json('rows_html'));
    }

    public function test_pending_approvals_panel_shows_the_requesters_username(): void
    {
        $admin = $this->makeUser('admin');
        $owner = $this->makeUser('user', ['username' => 'budi.santoso']);
        ITRequest::factory()->create([
            'type' => 'ITR', 'status' => 'Pending', 'sequence_number' => null,
            'user_id' => $owner->id, 'title' => 'Butuh Approval Username',
        ]);

        $response = $this->actingAs($admin)->getJson('/requisition/requests/pending-approvals');

        $this->assertStringContainsString('budi.santoso', $response->json('rows_html'));
    }

    public function test_reverting_an_already_processed_request_to_pending_does_not_reappear_in_incoming(): void
    {
        // Beda dengan request yang benar-benar baru (test di atas, sequence_number masih null) - kalau
        // tim IT sengaja mengubah status request yang SUDAH pernah diproses (sequence_number terisi)
        // balik ke Pending lewat dropdown, item itu jangan muncul lagi di "Incoming Requests" seolah-olah
        // request baru masuk.
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->create([
            'type' => 'ITR',
            'status' => 'Approved',
            'sequence_number' => 5,
            'title' => 'Pernah Diproses Lalu Direvert',
        ]);

        $this->actingAs($admin)->patch("/requisition/requests/{$item->id}/status", ['status' => 'Pending']);

        $item->refresh();
        $this->assertEquals('Pending', $item->status);
        $this->assertNotNull($item->sequence_number);

        $response = $this->actingAs($admin)->getJson('/requisition/requests/pending-approvals');
        $this->assertStringNotContainsString('Pernah Diproses Lalu Direvert', $response->json('rows_html'));
        $this->assertEquals(0, $response->json('count'));
    }

    public function test_user_role_cannot_access_pending_approvals_endpoint(): void
    {
        $user = $this->makeUser('user');

        $this->actingAs($user)->getJson('/requisition/requests/pending-approvals')->assertForbidden();
    }

    public function test_admin_can_approve_a_pending_request(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->create(['status' => 'Pending']);

        $response = $this->actingAs($admin)->patch("/requisition/requests/{$item->id}/status", [
            'status' => 'Approved',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('i_t_requests', ['id' => $item->id, 'status' => 'Approved']);
    }

    public function test_ajax_status_update_returns_json_success_response(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->create(['status' => 'Pending']);

        $response = $this->actingAs($admin)->patchJson("/requisition/requests/{$item->id}/status", [
            'status' => 'Approved',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
    }

    public function test_admin_can_set_analisa_and_rekomendasi_via_the_merged_edit_form(): void
    {
        // Analisa & Rekomendasi digabung ke form Edit BAK (bukan modal terpisah lagi) supaya
        // tim IT bisa lihat Kronologis sambil mengisi analisa.
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->bak()->create(['status' => 'Approved']);

        $response = $this->actingAs($admin)->patchJson("/requisition/requests/{$item->id}", [
            'employee_name' => 'Budi Santoso', 'employee_id' => $this->requesterId(),
            'employee_nik' => '123456',
            'position' => 'Staff',
            'title' => $item->title,
            'items' => [['name' => 'Laptop Asus']],
            'description' => $item->description,
            'analisa' => 'Sudah dicek, kerusakan pada kabel power.',
            'rekomendasi' => 'Ganti kabel power.',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertDatabaseHas('i_t_requests', [
            'id' => $item->id,
            'analisa' => 'Sudah dicek, kerusakan pada kabel power.',
            'rekomendasi' => 'Ganti kabel power.',
        ]);
    }

    public function test_owner_resubmitting_a_rejected_bak_cannot_set_analisa_or_rekomendasi(): void
    {
        // Analisa & Rekomendasi adalah penilaian internal tim IT — pemilik request yang cuma
        // memperbaiki & mengirim ulang request Rejected miliknya sendiri tidak boleh mengisinya,
        // meskipun field itu ada di payload (misal dikirim manual/crafted request).
        $owner = $this->makeUser('user');
        $item = ITRequest::factory()->bak()->create(['user_id' => $owner->id, 'status' => 'Rejected']);

        $response = $this->actingAs($owner)->patchJson("/requisition/requests/{$item->id}", [
            'employee_name' => 'Budi Santoso', 'employee_id' => $this->requesterId(),
            'employee_nik' => '123456',
            'position' => 'Staff',
            'title' => $item->title,
            'items' => [['name' => 'Laptop Asus']],
            'description' => $item->description,
            'analisa' => 'Coba menyelundupkan analisa',
            'rekomendasi' => 'Coba menyelundupkan rekomendasi',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('i_t_requests', [
            'id' => $item->id,
            'analisa' => null,
            'rekomendasi' => null,
        ]);
    }

    public function test_admin_can_reject_a_pending_request(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->create(['status' => 'Pending']);

        $response = $this->actingAs($admin)->patch("/requisition/requests/{$item->id}/status", [
            'status' => 'Rejected',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('i_t_requests', ['id' => $item->id, 'status' => 'Rejected']);
    }

    public function test_super_admin_can_update_status(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $item = ITRequest::factory()->create(['status' => 'Pending']);

        $response = $this->actingAs($superAdmin)->patch("/requisition/requests/{$item->id}/status", [
            'status' => 'Completed',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('i_t_requests', ['id' => $item->id, 'status' => 'Completed']);
    }

    public function test_user_role_cannot_update_status(): void
    {
        $user = $this->makeUser('user');
        $item = ITRequest::factory()->create(['status' => 'Pending']);

        $response = $this->actingAs($user)->patch("/requisition/requests/{$item->id}/status", [
            'status' => 'Approved',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('i_t_requests', ['id' => $item->id, 'status' => 'Pending']);
    }

    public function test_guest_cannot_update_status(): void
    {
        $item = ITRequest::factory()->create(['status' => 'Pending']);

        $response = $this->patch("/requisition/requests/{$item->id}/status", [
            'status' => 'Approved',
        ]);

        $response->assertRedirect(url('/admin/login'));
        $this->assertDatabaseHas('i_t_requests', ['id' => $item->id, 'status' => 'Pending']);
    }

    public function test_invalid_status_value_is_rejected(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->create(['status' => 'Pending']);

        $response = $this->actingAs($admin)->patch("/requisition/requests/{$item->id}/status", [
            'status' => 'NotARealStatus',
        ]);

        $response->assertSessionHasErrors('status');
        $this->assertDatabaseHas('i_t_requests', ['id' => $item->id, 'status' => 'Pending']);
    }

    public function test_completed_status_cannot_be_changed_again(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->create(['status' => 'Completed']);

        $response = $this->actingAs($admin)->patch("/requisition/requests/{$item->id}/status", [
            'status' => 'In Progress',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('i_t_requests', ['id' => $item->id, 'status' => 'Completed']);
    }

    public function test_super_admin_also_cannot_change_a_completed_status(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $item = ITRequest::factory()->create(['status' => 'Completed']);

        $response = $this->actingAs($superAdmin)->patch("/requisition/requests/{$item->id}/status", [
            'status' => 'Pending',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('i_t_requests', ['id' => $item->id, 'status' => 'Completed']);
    }

    public function test_admin_can_set_keterangan_note_for_any_gated_type(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->create(['type' => 'BAK', 'status' => 'Rejected']);

        $response = $this->actingAs($admin)->patchJson("/requisition/requests/{$item->id}/note", [
            'update_note' => 'Foto kurang jelas, mohon diulang.',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertDatabaseHas('i_t_requests', [
            'id' => $item->id,
            'update_note' => 'Foto kurang jelas, mohon diulang.',
        ]);
    }

    public function test_super_admin_can_delete_any_request(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $item = ITRequest::factory()->create();

        $response = $this->actingAs($superAdmin)->delete("/requisition/requests/{$item->id}");

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('i_t_requests', ['id' => $item->id]);
    }

    public function test_admin_cannot_delete_an_arbitrary_request(): void
    {
        // Kolom/tombol Delete di tabel "List All Form" sekarang khusus Super Admin - admin biasa
        // hanya bisa hapus request Rejected miliknya sendiri, sama seperti role user.
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->create(['status' => 'Pending']);

        $response = $this->actingAs($admin)->delete("/requisition/requests/{$item->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('i_t_requests', ['id' => $item->id]);
    }

    public function test_admin_can_still_delete_their_own_rejected_request(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->create(['user_id' => $admin->id, 'status' => 'Rejected']);

        $response = $this->actingAs($admin)->delete("/requisition/requests/{$item->id}");

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('i_t_requests', ['id' => $item->id]);
    }

    public function test_user_role_cannot_delete_a_request(): void
    {
        $user = $this->makeUser('user');
        $item = ITRequest::factory()->create();

        $response = $this->actingAs($user)->delete("/requisition/requests/{$item->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('i_t_requests', ['id' => $item->id]);
    }

    public function test_owner_can_delete_their_own_rejected_request_of_any_type(): void
    {
        $owner = $this->makeUser('user');

        // Surat Jalan & TT sengaja diikutkan karena kedua tipe ini tidak bisa diedit/resubmit,
        // jadi hapus adalah satu-satunya cara pemilik menyingkirkan request Rejected miliknya.
        $factories = [
            ITRequest::factory(),
            ITRequest::factory()->bak(),
            ITRequest::factory()->suratJalan(),
            ITRequest::factory()->tt(),
            ITRequest::factory()->wo(),
            ITRequest::factory()->ticketSistem(),
        ];

        foreach ($factories as $factory) {
            $item = $factory->create(['user_id' => $owner->id, 'status' => 'Rejected']);

            $response = $this->actingAs($owner)->delete("/requisition/requests/{$item->id}");

            $response->assertSessionHasNoErrors();
            $this->assertDatabaseMissing('i_t_requests', ['id' => $item->id]);
        }
    }

    public function test_owner_cannot_delete_their_own_request_unless_it_is_rejected(): void
    {
        $owner = $this->makeUser('user');
        $item = ITRequest::factory()->create(['user_id' => $owner->id, 'status' => 'Pending']);

        $response = $this->actingAs($owner)->delete("/requisition/requests/{$item->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('i_t_requests', ['id' => $item->id]);
    }

    public function test_user_cannot_delete_someone_elses_rejected_request(): void
    {
        $owner = $this->makeUser('user');
        $otherUser = $this->makeUser('user');
        $item = ITRequest::factory()->create(['user_id' => $owner->id, 'status' => 'Rejected']);

        $response = $this->actingAs($otherUser)->delete("/requisition/requests/{$item->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('i_t_requests', ['id' => $item->id]);
    }

    public function test_super_admin_can_bulk_delete_requests(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $items = ITRequest::factory()->count(2)->create();

        $response = $this->actingAs($superAdmin)->delete('/requisition/requests/bulk-destroy', [
            'ids' => $items->pluck('id')->all(),
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('i_t_requests', 0);
    }

    public function test_admin_cannot_bulk_delete_requests(): void
    {
        $admin = $this->makeUser('admin');
        $items = ITRequest::factory()->count(2)->create();

        $response = $this->actingAs($admin)->delete('/requisition/requests/bulk-destroy', [
            'ids' => $items->pluck('id')->all(),
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('i_t_requests', 2);
    }
}
