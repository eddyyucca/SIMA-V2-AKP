<?php

namespace Tests\Requisition;

use App\Models\ITRequest;
use App\Models\User;
use Tests\RequisitionTestCase;

class EditResubmitTest extends RequisitionTestCase
{

    public function test_owner_can_edit_and_resubmit_a_rejected_itr_request(): void
    {
        $owner = $this->makeUser('user');
        $item = ITRequest::factory()->create([
            'type' => 'ITR',
            'user_id' => $owner->id,
            'status' => 'Rejected',
        ]);

        $response = $this->actingAs($owner)->patch("/requisition/requests/{$item->id}", [
            'employee_name' => 'Budi Santoso Revisi',
            'employee_nik' => '123456',
            'position' => 'Staff',
            'title' => 'Laptop Baru (Revisi)',
            'items' => [
                ['name' => 'Laptop', 'qty' => 1],
            ],
            'description' => 'Diperbaiki sesuai catatan IT',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('i_t_requests', [
            'id' => $item->id,
            'requester_name' => 'Budi Santoso',      // nama pemohon dari karyawan akun login, tidak bisa diubah lewat form
            'status' => 'Pending', // otomatis dikirim ulang sebagai Pending
        ]);
    }

    public function test_resubmitting_a_rejected_request_resets_the_previous_approver(): void
    {
        // approved_by sempat terisi nama admin yang menolak (Reject dihitung sebagai "memproses" juga) -
        // begitu diperbaiki & dikirim ulang, field ini harus kosong lagi supaya siklus approval
        // berikutnya mencatat admin yang BENAR-BENAR menyetujui submission baru ini.
        $rejectingAdmin = $this->makeUser('admin');
        $owner = $this->makeUser('user');
        $item = ITRequest::factory()->create([
            'type' => 'ITR',
            'user_id' => $owner->id,
            'status' => 'Rejected',
            'approved_by' => $rejectingAdmin->id,
        ]);

        $this->actingAs($owner)->patch("/requisition/requests/{$item->id}", [
            'employee_name' => 'Budi Santoso Revisi',
            'employee_nik' => '123456',
            'position' => 'Staff',
            'title' => 'Laptop Baru (Revisi)',
            'items' => [
                ['name' => 'Laptop', 'qty' => 1],
            ],
            'description' => 'Diperbaiki sesuai catatan IT',
        ]);

        $item->refresh();
        $this->assertNull($item->approved_by);
    }

    public function test_ajax_edit_resubmit_returns_json_success_response(): void
    {
        $owner = $this->makeUser('user');
        $item = ITRequest::factory()->create([
            'type' => 'ITR',
            'user_id' => $owner->id,
            'status' => 'Rejected',
        ]);

        $response = $this->actingAs($owner)->patchJson("/requisition/requests/{$item->id}", [
            'employee_name' => 'Budi Santoso Revisi',
            'employee_nik' => '123456',
            'position' => 'Staff',
            'title' => 'Laptop Baru (Revisi)',
            'items' => [
                ['name' => 'Laptop', 'qty' => 1],
            ],
            'description' => 'Diperbaiki sesuai catatan IT',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
    }

    public function test_owner_can_edit_and_resubmit_a_rejected_ticket_sistem_request(): void
    {
        $owner = $this->makeUser('user');
        $item = ITRequest::factory()->ticketSistem()->create([
            'user_id' => $owner->id,
            'status' => 'Rejected',
        ]);

        $response = $this->actingAs($owner)->patch("/requisition/requests/{$item->id}", [
            'employee_name' => 'Budi Santoso',
            'employee_nik' => '123456',
            'position' => 'Staff',
            'title' => 'Printer Rusak (Revisi)',
            'category' => 'Hardware',
            'admin_notes' => 'Ruang Server Baru',
            'description' => 'Sudah dicek ulang',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('i_t_requests', [
            'id' => $item->id,
            'title' => 'Printer Rusak (Revisi)',
            'category' => 'Hardware',
            'admin_notes' => 'Ruang Server Baru',
            'status' => 'Pending',
        ]);
    }

    public function test_ticket_sistem_edit_requires_lokasi_admin_notes(): void
    {
        $owner = $this->makeUser('user');
        $item = ITRequest::factory()->ticketSistem()->create([
            'user_id' => $owner->id,
            'status' => 'Rejected',
        ]);

        $response = $this->actingAs($owner)->patch("/requisition/requests/{$item->id}", [
            'employee_name' => 'Budi Santoso',
            'title' => 'Printer Rusak',
            'category' => 'Hardware',
            'description' => 'Sudah dicek ulang',
            // admin_notes (Lokasi) sengaja tidak diisi
        ]);

        $response->assertSessionHasErrors('admin_notes');
        $this->assertDatabaseHas('i_t_requests', ['id' => $item->id, 'status' => 'Rejected']);
    }

    public function test_owner_cannot_edit_a_request_that_is_not_rejected(): void
    {
        $owner = $this->makeUser('user');
        $item = ITRequest::factory()->create([
            'type' => 'ITR',
            'user_id' => $owner->id,
            'status' => 'Pending',
        ]);

        $response = $this->actingAs($owner)->patch("/requisition/requests/{$item->id}", [
            'employee_name' => 'Coba Edit',
            'title' => 'Laptop',
            'items' => [['name' => 'Laptop', 'qty' => 1]],
            'description' => 'test',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('i_t_requests', ['id' => $item->id, 'requester_name' => $item->requester_name]);
    }

    public function test_user_cannot_edit_someone_elses_request(): void
    {
        $owner = $this->makeUser('user');
        $otherUser = $this->makeUser('user');
        $item = ITRequest::factory()->create([
            'type' => 'ITR',
            'user_id' => $owner->id,
            'status' => 'Rejected',
        ]);

        $response = $this->actingAs($otherUser)->patch("/requisition/requests/{$item->id}", [
            'employee_name' => 'Coba Edit Punya Orang',
            'title' => 'Laptop',
            'items' => [['name' => 'Laptop', 'qty' => 1]],
            'description' => 'test',
        ]);

        $response->assertForbidden();
    }

    public function test_admin_can_edit_regardless_of_status(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->create([
            'type' => 'ITR',
            'status' => 'Approved',
        ]);

        $response = $this->actingAs($admin)->patch("/requisition/requests/{$item->id}", [
            'employee_name' => 'Diperbaiki Admin',
            'employee_nik' => '123456',
            'position' => 'Staff',
            'title' => 'Laptop',
            'items' => [['name' => 'Laptop', 'qty' => 1]],
            'description' => 'test',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('i_t_requests', [
            'id' => $item->id,
            'requester_name' => 'Diperbaiki Admin',
            'status' => 'Approved', // admin edit tidak mengubah status kalau bukan dari Rejected
        ]);
    }

    public function test_admin_editing_someone_elses_rejected_request_does_not_resubmit_it(): void
    {
        // Admin boleh edit request Rejected milik user lain (mis. melengkapi data), tapi TIDAK boleh
        // ikut mengirim ulang (resubmit) atas nama user - itu harus tindakan sadar pemiliknya sendiri.
        // Statusnya harus tetap Rejected, bukan otomatis balik ke Pending seperti kalau ownernya sendiri
        // yang edit (lihat test_owner_can_edit_and_resubmit_a_rejected_itr_request).
        $admin = $this->makeUser('admin');
        $owner = $this->makeUser('user');
        $rejectingAdmin = $this->makeUser('admin');
        $item = ITRequest::factory()->create([
            'type' => 'ITR',
            'user_id' => $owner->id,
            'status' => 'Rejected',
            'approved_by' => $rejectingAdmin->id,
        ]);

        $response = $this->actingAs($admin)->patch("/requisition/requests/{$item->id}", [
            'employee_name' => 'Dilengkapi Admin',
            'employee_nik' => '123456',
            'position' => 'Staff',
            'title' => 'Laptop',
            'items' => [['name' => 'Laptop', 'qty' => 1]],
            'description' => 'Dilengkapi tanpa dikirim ulang',
        ]);

        $response->assertSessionHasNoErrors();
        $item->refresh();
        $this->assertEquals('Rejected', $item->status);
        $this->assertEquals('Dilengkapi Admin', $item->requester_name);
        // approved_by TIDAK direset - resubmit sungguhan (oleh owner) yang mereset ini, bukan edit biasa.
        $this->assertEquals($rejectingAdmin->id, $item->approved_by);
    }

    public function test_admin_can_edit_a_bak_requests_original_form_fields(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->create([
            'type' => 'BAK',
            'status' => 'Approved',
            'title' => 'Judul Lama',
        ]);

        $response = $this->actingAs($admin)->patch("/requisition/requests/{$item->id}", [
            'employee_name' => 'Budi Santoso',
            'employee_nik' => '123456',
            'position' => 'Staff',
            'title' => 'Judul Sudah Diperbaiki IT',
            'items' => [
                ['name' => 'Laptop Asus', 'spesifikasi' => 'i5/8GB', 'serial_number' => 'SN123'],
            ],
            'description' => 'Kronologis sudah dikoreksi',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('i_t_requests', [
            'id' => $item->id,
            'title' => 'Judul Sudah Diperbaiki IT',
            'status' => 'Approved', // admin edit tidak mengubah status kalau bukan dari Rejected
        ]);
        $this->assertEquals('Laptop Asus', ITRequest::find($item->id)->items[0]['name']);
    }

    public function test_surat_jalan_and_tt_cannot_be_edited_at_all(): void
    {
        $owner = $this->makeUser('user');
        $item = ITRequest::factory()->suratJalan()->create([
            'user_id' => $owner->id,
            'status' => 'Rejected',
        ]);

        $response = $this->actingAs($owner)->patch("/requisition/requests/{$item->id}", [
            'employee_name' => 'Coba Edit',
            'description' => 'test',
        ]);

        $response->assertForbidden();
    }
}
