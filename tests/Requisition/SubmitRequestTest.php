<?php

namespace Tests\Requisition;

use App\Models\ITRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\RequisitionTestCase;

class SubmitRequestTest extends RequisitionTestCase
{

    public function test_authenticated_user_can_submit_an_itr_request(): void
    {
        $user = $this->makeUser('admin');

        $response = $this->actingAs($user)->post('/requisition/requests', [
            'type' => 'ITR',
            'employee_name' => 'Budi Santoso', 'employee_id' => $this->requesterId(),
            'employee_nik' => '123456',
            'department' => 'IT',
            'position' => 'Staff IT',
            'title' => 'Laptop Baru',
            'items' => [
                ['name' => 'Laptop', 'qty' => 1],
            ],
            'description' => 'Karyawan baru butuh laptop',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $this->assertDatabaseHas('i_t_requests', [
            'type' => 'ITR',
            'requester_name' => 'Budi Santoso',
            'status' => 'Pending',
        ]);

        // ITR baru dapat nomor tiket setelah di-approve (lihat TicketNumberingTest), jadi saat
        // masih Pending belum ada sequence_number/ticket_number sama sekali.
        $item = ITRequest::first();
        $this->assertNull($item->sequence_number);
        $this->assertNull($item->ticket_number);
    }

    public function test_ajax_submit_returns_json_success_response(): void
    {
        // Form request di halaman "Pilih Jenis Request" submit via fetch (Accept: application/json)
        // supaya bisa menampilkan loading -> notifikasi berhasil tanpa reload halaman.
        $user = $this->makeUser('admin');

        $response = $this->actingAs($user)->postJson('/requisition/requests', [
            'type' => 'ITR',
            'employee_name' => 'Budi Santoso', 'employee_id' => $this->requesterId(),
            'employee_nik' => '123456',
            'position' => 'Staff IT',
            'title' => 'Laptop Baru',
            'items' => [
                ['name' => 'Laptop', 'qty' => 1],
            ],
            'description' => 'Karyawan baru butuh laptop',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $response->assertJsonStructure(['success', 'message']);
    }

    public function test_ajax_submit_returns_json_validation_errors(): void
    {
        $user = $this->makeUser('admin');

        $response = $this->actingAs($user)->postJson('/requisition/requests', [
            'type' => 'ITR',
            // karyawan (employee_id) & description sengaja tidak diisi
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['employee_id']);
        $response2 = $this->actingAs($user)->postJson('/requisition/requests', ['type' => 'ITR', 'employee_id' => $this->requesterId()]);
        $response2->assertStatus(422)->assertJsonValidationErrors(['description']);
    }

    public function test_submitting_without_required_fields_fails_validation(): void
    {
        $user = $this->makeUser('admin');

        $response = $this->actingAs($user)->post('/requisition/requests', [
            'type' => 'ITR',
            // karyawan & description sengaja tidak diisi
        ]);

        $response->assertSessionHasErrors(['employee_id']);
        $this->assertDatabaseCount('i_t_requests', 0);
    }

    public function test_requester_must_be_picked_from_the_employee_database(): void
    {
        $user = $this->makeUser('admin');
        $base = ['type' => 'ITR', 'employee_nik' => '123456', 'position' => 'Staff', 'title' => 'Laptop Baru', 'items' => [['name' => 'Laptop', 'qty' => 1]], 'description' => 'Karyawan baru butuh laptop'];

        // nama diketik tanpa memilih dari daftar (tanpa employee_id) -> ditolak, walau namanya benar
        $this->actingAs($user)->post('/requisition/requests', $base + ['employee_name' => 'Dendi Ardiansyah'])->assertSessionHasErrors('employee_id');
        // id tidak ada / bukan angka / karyawan resign -> ditolak
        $this->actingAs($user)->post('/requisition/requests', $base + ['employee_id' => 999999])->assertSessionHasErrors('employee_id');
        $this->actingAs($user)->post('/requisition/requests', $base + ['employee_id' => 'abc'])->assertSessionHasErrors('employee_id');
        $resigned = $this->makeRequester(['name' => 'Sudah Resign', 'status' => 'resigned']);
        $this->actingAs($user)->post('/requisition/requests', $base + ['employee_id' => $resigned->id])->assertSessionHasErrors('employee_id');
        $this->assertDatabaseCount('i_t_requests', 0);
    }

    public function test_requester_data_comes_from_the_database_not_from_the_browser(): void
    {
        $user = $this->makeUser('admin');
        // nama dari database tidak perlu memenuhi aturan Title Case lama (mis. "Budi Wijaya A")
        $dept = \App\Models\Department::where('name', 'Finance')->firstOrFail();
        $emp = $this->makeRequester(['name' => 'Budi Wijaya A', 'nik' => '7788', 'position' => 'Akuntan', 'department_id' => $dept->id]);

        $this->actingAs($user)->post('/requisition/requests', [
            'type' => 'ITR', 'employee_id' => $emp->id,
            // isian browser yang berbeda dari database harus diabaikan
            'employee_name' => 'Nama Palsu', 'employee_nik' => '1', 'department' => \App\Models\Department::where('name', '!=', 'Finance')->value('name'), 'position' => 'Direktur',
            'title' => 'Laptop Baru', 'items' => [['name' => 'Laptop', 'qty' => 1]], 'description' => 'Uji',
        ])->assertSessionHasNoErrors();

        $r = ITRequest::latest('id')->firstOrFail();
        $this->assertSame([$emp->id, 'Budi Wijaya A', '7788', 'Finance', 'Akuntan', $user->id], [$r->employee_id, $r->requester_name, $r->nik, $r->department, $r->position, $r->user_id]);
    }

    public function test_only_what_is_missing_in_the_database_is_taken_from_the_form(): void
    {
        $user = $this->makeUser('admin');
        $payload = ['type' => 'ITR', 'title' => 'Laptop Baru', 'items' => [['name' => 'Laptop', 'qty' => 1]], 'description' => 'Uji'];
        $emp = $this->makeRequester(['name' => 'Tanpa Nik', 'nik' => null, 'position' => null, 'department_id' => null]);
        $deptName = \App\Models\Department::value('name');

        // NIK, jabatan, dan department kosong di database: NIK & jabatan wajib diisi, department opsional tetapi harus dari daftar
        $r = $this->actingAs($user)->post('/requisition/requests', $payload + ['employee_id' => $emp->id]);
        $r->assertSessionHasErrors(['employee_nik', 'position']);
        $this->actingAs($user)->post('/requisition/requests', $payload + ['employee_id' => $emp->id, 'employee_nik' => '9988', 'position' => 'Operator', 'department' => 'Departemen Tidak Ada'])->assertSessionHasErrors('department');
        $this->actingAs($user)->post('/requisition/requests', $payload + ['employee_id' => $emp->id, 'employee_nik' => '9988', 'position' => 'Operator', 'department' => $deptName])->assertSessionHasNoErrors();
        $saved = ITRequest::latest('id')->firstOrFail();
        $this->assertSame(['9988', 'Operator', $deptName], [$saved->nik, $saved->position, $saved->department]);
        $this->assertNull($emp->fresh()->nik);                                      // database karyawan tidak diubah oleh formulir request
    }

    public function test_ticket_sistem_succeeds_without_an_attachment(): void
    {
        $user = $this->makeUser('admin');

        $response = $this->actingAs($user)->post('/requisition/requests', [
            'type' => 'TICKET_SISTEM',
            'employee_name' => 'Budi Santoso', 'employee_id' => $this->requesterId(),
            'employee_nik' => '123456',
            'department' => 'IT',
            'position' => 'Staff IT',
            'title' => 'Printer Rusak',
            'category' => 'Hardware',
            'admin_notes' => 'Ruang Server',
            'description' => 'Printer tidak bisa mencetak',
            // attachment sengaja tidak diisi
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('i_t_requests', [
            'type' => 'TICKET_SISTEM',
            'status' => 'Pending',
            'title' => 'Printer Rusak',
            'category' => 'Hardware',
        ]);
    }

    public function test_ticket_sistem_succeeds_with_attachment(): void
    {
        Storage::fake('public');
        $user = $this->makeUser('admin');

        $response = $this->actingAs($user)->post('/requisition/requests', [
            'type' => 'TICKET_SISTEM',
            'employee_name' => 'Budi Santoso', 'employee_id' => $this->requesterId(),
            'employee_nik' => '123456',
            'department' => 'IT',
            'position' => 'Staff IT',
            'title' => 'Printer Rusak',
            'category' => 'Hardware',
            'admin_notes' => 'Ruang Server',
            'description' => 'Printer tidak bisa mencetak',
            'attachment' => UploadedFile::fake()->image('bukti.jpg'),
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('i_t_requests', ['type' => 'TICKET_SISTEM', 'status' => 'Pending']);
        Storage::disk('public')->assertExists(ITRequest::first()->attachment);
    }

    public function test_ticket_sistem_rejects_a_category_outside_the_valid_list(): void
    {
        $user = $this->makeUser('admin');

        $response = $this->actingAs($user)->post('/requisition/requests', [
            'type' => 'TICKET_SISTEM',
            'employee_name' => 'Budi Santoso', 'employee_id' => $this->requesterId(),
            'employee_nik' => '123456',
            'department' => 'IT',
            'position' => 'Staff IT',
            'title' => 'Printer Rusak',
            'category' => 'Bukan Kategori Valid',
            'admin_notes' => 'Ruang Server',
            'description' => 'Printer tidak bisa mencetak',
        ]);

        $response->assertSessionHasErrors('category');
        $this->assertDatabaseCount('i_t_requests', 0);
    }

    public function test_ticket_sistem_requires_a_category(): void
    {
        $user = $this->makeUser('admin');

        $response = $this->actingAs($user)->post('/requisition/requests', [
            'type' => 'TICKET_SISTEM',
            'employee_name' => 'Budi Santoso', 'employee_id' => $this->requesterId(),
            'employee_nik' => '123456',
            'department' => 'IT',
            'position' => 'Staff IT',
            'title' => 'Printer Rusak',
            'admin_notes' => 'Ruang Server',
            'description' => 'Printer tidak bisa mencetak',
            // category sengaja tidak diisi
        ]);

        $response->assertSessionHasErrors('category');
        $this->assertDatabaseCount('i_t_requests', 0);
    }

    public function test_user_role_cannot_submit_surat_jalan_or_tt(): void
    {
        $user = $this->makeUser('user');

        $response = $this->actingAs($user)->post('/requisition/requests', [
            'type' => 'SURAT_JALAN',
            'employee_name' => 'Budi Santoso', 'employee_id' => $this->requesterId(),
            'recipient_name' => 'Penerima',
            'description' => 'Kirim barang',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('i_t_requests', 0);
    }

    public function test_user_role_can_submit_itr(): void
    {
        $user = $this->makeUser('user');

        $response = $this->actingAs($user)->post('/requisition/requests', [
            'type' => 'ITR',
            'employee_name' => 'Budi Santoso', 'employee_id' => $this->requesterId(),
            'employee_nik' => '123456',
            'position' => 'Staff',
            'title' => 'Mouse Baru',
            'items' => [
                ['name' => 'Mouse', 'qty' => 1],
            ],
            'description' => 'Mouse rusak',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('i_t_requests', ['type' => 'ITR', 'requester_name' => 'Budi Santoso']);
    }

    public function test_deleting_a_middle_request_does_not_renumber_or_reuse_sequence(): void
    {
        // Skenario asli: SURAT_JALAN #1-3 dibuat, #2 dihapus karena salah input, request baru
        // berikutnya harus lanjut #4 (bukan mengisi slot #2, dan #1/#3 tidak berubah).
        // Pakai SURAT_JALAN (bukan ITR) karena tipe ini dapat nomor langsung tanpa approval.
        $user = $this->makeUser('admin');

        $first = ITRequest::factory()->suratJalan()->create(['user_id' => $user->id, 'sequence_number' => 1]);
        $second = ITRequest::factory()->suratJalan()->create(['user_id' => $user->id, 'sequence_number' => 2]);
        $third = ITRequest::factory()->suratJalan()->create(['user_id' => $user->id, 'sequence_number' => 3]);
        $second->delete();
        Storage::fake('public');

        $response = $this->actingAs($user)->post('/requisition/requests', [
            'type' => 'SURAT_JALAN',
            'employee_name' => 'Budi Santoso', 'employee_id' => $this->requesterId(),
            'position' => 'Staff Gudang',
            'title' => 'Kirim ke Site B',
            'recipient_name' => 'Penerima Baru',
            'items' => [
                ['name' => 'Monitor', 'qty' => 1],
            ],
            'description' => 'Kirim monitor',
            'attachment' => UploadedFile::fake()->image('surat.jpg'),
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('i_t_requests', ['requester_name' => 'Budi Santoso', 'sequence_number' => 4]);
        $this->assertEquals(1, $first->fresh()->sequence_number);
        $this->assertEquals(3, $third->fresh()->sequence_number);
    }
}
