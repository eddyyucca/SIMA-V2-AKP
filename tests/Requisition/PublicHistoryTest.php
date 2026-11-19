<?php

namespace Tests\Requisition;

use App\Models\ITRequest;
use App\Models\User;
use Tests\RequisitionTestCase;

class PublicHistoryTest extends RequisitionTestCase
{

    public function test_user_role_can_access_the_history_refresh_endpoint(): void
    {
        $user = $this->makeUser('user');

        $this->actingAs($user)->getJson('/requisition/requests/history')->assertOk();
    }

    public function test_history_endpoint_shows_pending_requests_with_menunggu_approval_label(): void
    {
        // Request baru harus langsung kelihatan di History Request Terbaru begitu berhasil dibuat,
        // dengan status "Menunggu Approval" - bukan menunggu sampai diproses tim IT dulu.
        $user = $this->makeUser('user');
        ITRequest::factory()->create(['type' => 'ITR', 'status' => 'Pending', 'sequence_number' => null, 'title' => 'Baru Saja Dibuat']);
        ITRequest::factory()->create(['type' => 'ITR', 'status' => 'Completed', 'title' => 'Sudah Selesai Ini']);

        $response = $this->actingAs($user)->getJson('/requisition/requests/history');

        $response->assertOk();
        $response->assertJsonStructure(['rows_html']);
        $this->assertStringContainsString('Baru Saja Dibuat', $response->json('rows_html'));
        $this->assertStringContainsString('Menunggu Approval', $response->json('rows_html'));
        $this->assertStringContainsString('Sudah Selesai Ini', $response->json('rows_html'));
    }

    public function test_history_endpoint_shows_plain_pending_label_for_a_previously_processed_request(): void
    {
        // Beda dengan request yang benar-benar baru (test di atas, label "Menunggu Approval") - request
        // yang SUDAH pernah diproses (sequence_number terisi) lalu direvert balik ke Pending harus
        // tampil labelnya "Pending" polos, sama seperti yang ditampilkan di List All Form - bukan
        // "Menunggu Approval" yang menyiratkan seolah-olah belum pernah disentuh tim IT sama sekali.
        $user = $this->makeUser('user');
        ITRequest::factory()->create(['type' => 'ITR', 'status' => 'Pending', 'sequence_number' => 12, 'title' => 'Direvert Ke Pending Lagi']);

        $response = $this->actingAs($user)->getJson('/requisition/requests/history');
        $rowsHtml = $response->json('rows_html');

        $this->assertStringContainsString('Direvert Ke Pending Lagi', $rowsHtml);
        $this->assertStringNotContainsString('Menunggu Approval', $rowsHtml);
    }

    public function test_guest_cannot_access_the_history_refresh_endpoint(): void
    {
        $this->getJson('/requisition/requests/history')->assertUnauthorized();
    }

    public function test_history_endpoint_shows_approver_name_and_a_view_button_for_keterangan_to_the_owner(): void
    {
        $user = $this->makeUser('user');
        $admin = $this->makeUser('user', ['name' => 'Admin Penyetuju']);
        ITRequest::factory()->create([
            'type' => 'ITR',
            'status' => 'Rejected',
            'user_id' => $user->id,
            'approved_by' => $admin->id,
            'update_note' => 'Mohon lengkapi spesifikasi barang.',
            'title' => 'Butuh Info Tambahan',
        ]);

        $response = $this->actingAs($user)->getJson('/requisition/requests/history');
        $rowsHtml = $response->json('rows_html');

        $this->assertStringContainsString('Admin Penyetuju', $rowsHtml);
        $this->assertStringContainsString('View', $rowsHtml);
    }

    public function test_history_endpoint_hides_keterangan_view_button_from_other_users(): void
    {
        // Tabel History Request Terbaru bersifat publik (menampilkan request semua orang), jadi
        // tombol "View" catatan keterangan admin harus tetap privat - hanya kelihatan oleh pemilik
        // request atau tim IT, bukan sesama user lain yang kebetulan lihat baris yang sama.
        $owner = $this->makeUser('user');
        $otherUser = $this->makeUser('user');
        ITRequest::factory()->create([
            'type' => 'ITR',
            'status' => 'Rejected',
            'user_id' => $owner->id,
            'update_note' => 'Catatan rahasia untuk pemilik request.',
            'title' => 'Request Milik Orang Lain',
        ]);

        $response = $this->actingAs($otherUser)->getJson('/requisition/requests/history');
        $rowsHtml = $response->json('rows_html');

        $this->assertStringContainsString('Request Milik Orang Lain', $rowsHtml);
        $this->assertStringNotContainsString('Catatan rahasia untuk pemilik request.', $rowsHtml);
        $this->assertStringNotContainsString('View', $rowsHtml);
    }

    public function test_history_endpoint_shows_keterangan_view_button_to_admins_for_any_request(): void
    {
        $admin = $this->makeUser('admin');
        $owner = $this->makeUser('user');
        ITRequest::factory()->create([
            'type' => 'ITR',
            'status' => 'Rejected',
            'user_id' => $owner->id,
            'update_note' => 'Mohon lengkapi spesifikasi barang.',
            'title' => 'Butuh Info Tambahan Admin',
        ]);

        $response = $this->actingAs($admin)->getJson('/requisition/requests/history');
        $rowsHtml = $response->json('rows_html');

        $this->assertStringContainsString('View', $rowsHtml);
    }

    public function test_history_endpoint_shows_dash_when_no_keterangan_is_filled(): void
    {
        $user = $this->makeUser('user');
        ITRequest::factory()->create([
            'type' => 'ITR',
            'status' => 'Completed',
            'update_note' => null,
            'title' => 'Tidak Ada Keterangan',
        ]);

        $response = $this->actingAs($user)->getJson('/requisition/requests/history');

        $response->assertOk();
    }

    public function test_super_admin_sees_delete_button_and_can_delete_a_non_rejected_request_from_history(): void
    {
        // Backend destroy() sudah mengizinkan Super Admin hapus status apa pun (bukan cuma Rejected),
        // sama seperti tombol Hapus di List All Form & list Peminjaman Aset - tombol di History Request
        // Terbaru harus konsisten, bukan cuma muncul untuk Rejected.
        $superAdmin = $this->makeUser('super_admin');
        $item = ITRequest::factory()->create(['type' => 'ITR', 'status' => 'Completed', 'title' => 'Selesai Tapi Perlu Dihapus']);

        $response = $this->actingAs($superAdmin)->getJson('/requisition/requests/history');
        $this->assertStringContainsString('deleteRequest(' . $item->id . ',', $response->json('rows_html'));

        $this->actingAs($superAdmin)->delete("/requisition/requests/{$item->id}")->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('i_t_requests', ['id' => $item->id]);
    }

    public function test_regular_admin_does_not_see_delete_button_for_a_non_rejected_request_in_history(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->create(['type' => 'ITR', 'status' => 'Completed', 'title' => 'Selesai Admin Biasa']);

        $response = $this->actingAs($admin)->getJson('/requisition/requests/history');
        $rowsHtml = $response->json('rows_html');

        $this->assertStringContainsString('Selesai Admin Biasa', $rowsHtml);
        $this->assertStringNotContainsString('deleteRequest(' . $item->id . ',', $rowsHtml);
    }

    public function test_history_endpoint_includes_compact_item_data_for_toast_detection(): void
    {
        $user = $this->makeUser('user');
        $item = ITRequest::factory()->create(['type' => 'ITR', 'status' => 'Approved', 'user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson('/requisition/requests/history');

        $response->assertOk();
        $response->assertJsonStructure(['items' => [['id', 'user_id', 'status', 'title', 'type']]]);
        $response->assertJsonFragment(['id' => $item->id, 'user_id' => $user->id, 'status' => 'Approved']);
    }

    public function test_ticket_number_opens_the_filled_form_only_for_the_owner(): void
    {
        $owner = $this->makeUser('user');
        $other = $this->makeUser('user');
        $admin = $this->makeUser('admin');
        ITRequest::factory()->create(['type' => 'ITR', 'user_id' => $owner->id, 'title' => 'Form Milik Owner']);

        $this->assertStringContainsString('openMyFormModal', $this->actingAs($owner)->getJson('/requisition/requests/history')->json('rows_html'));
        $this->assertStringContainsString('openMyFormModal', $this->actingAs($admin)->getJson('/requisition/requests/history')->json('rows_html'));
        // User lain tetap bisa melihat barisnya, tapi isi form (data lengkap) tidak ikut terkirim ke halaman
        $otherHtml = $this->actingAs($other)->getJson('/requisition/requests/history')->json('rows_html');
        $this->assertStringContainsString('Form Milik Owner', $otherHtml);
        $this->assertStringNotContainsString('openMyFormModal', $otherHtml);
    }
}
