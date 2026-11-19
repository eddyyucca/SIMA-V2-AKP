<?php

namespace Tests\Requisition;

use App\Models\ITRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\RequisitionTestCase;

class TicketNumberingTest extends RequisitionTestCase
{

    public function test_gated_type_has_no_ticket_number_until_approved(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->create(['type' => 'ITR', 'status' => 'Pending', 'sequence_number' => null]);

        $this->assertNull($item->ticket_number);

        $this->actingAs($admin)->patch("/requisition/requests/{$item->id}/status", ['status' => 'Approved']);

        $item->refresh();
        $this->assertNotNull($item->sequence_number);
        $this->assertNotNull($item->ticket_number);
        $this->assertStringStartsWith('AKP-ITR', $item->ticket_number);
    }

    public function test_rejecting_does_not_assign_a_ticket_number(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->create(['type' => 'ITR', 'status' => 'Pending', 'sequence_number' => null]);

        $this->actingAs($admin)->patch("/requisition/requests/{$item->id}/status", ['status' => 'Rejected']);

        $item->refresh();
        $this->assertNull($item->sequence_number);
        $this->assertNull($item->ticket_number);
    }

    public function test_skipping_approved_still_assigns_a_number(): void
    {
        // Admin boleh langsung set status ke In Progress/Completed tanpa lewat Approved dulu
        // (dropdown status bebas dipilih) — nomor tetap harus dikunci begitu keluar dari Pending/Rejected.
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->create(['type' => 'WO', 'status' => 'Pending', 'sequence_number' => null]);

        $this->actingAs($admin)->patch("/requisition/requests/{$item->id}/status", ['status' => 'In Progress']);

        $item->refresh();
        $this->assertNotNull($item->sequence_number);
    }

    public function test_sequence_number_is_frozen_once_assigned(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->create(['type' => 'BAK', 'status' => 'Pending', 'sequence_number' => null]);

        $this->actingAs($admin)->patch("/requisition/requests/{$item->id}/status", ['status' => 'Approved']);
        $assignedNumber = $item->refresh()->sequence_number;

        $this->actingAs($admin)->patch("/requisition/requests/{$item->id}/status", ['status' => 'Completed']);
        $this->assertEquals($assignedNumber, $item->refresh()->sequence_number);
    }

    public function test_surat_jalan_gets_a_number_immediately_without_approval(): void
    {
        Storage::fake('public');
        $user = $this->makeUser('admin');

        $this->actingAs($user)->post('/requisition/requests', [
            'type' => 'SURAT_JALAN',
            'employee_name' => 'Budi Santoso', 'employee_id' => $this->requesterId(),
            'position' => 'Staff Gudang',
            'title' => 'Kirim ke Site B',
            'recipient_name' => 'Penerima',
            'items' => [['name' => 'Monitor', 'qty' => 1]],
            'description' => 'Kirim monitor',
            'attachment' => UploadedFile::fake()->image('surat.jpg'),
        ]);

        $item = ITRequest::first();
        $this->assertNotNull($item->sequence_number);
        $this->assertNotNull($item->ticket_number);
        $this->assertEquals('Pending', $item->status); // dapat nomor meski masih Pending
    }

    public function test_pending_and_rejected_gated_requests_are_hidden_from_list_all_form(): void
    {
        // Sengaja cek endpoint AJAX List All Form secara spesifik (bukan seluruh halaman dashboard),
        // karena item Pending memang tetap tampil di tabel "Incoming Requests" yang berbeda.
        $admin = $this->makeUser('admin');

        ITRequest::factory()->create(['type' => 'ITR', 'status' => 'Pending', 'sequence_number' => null, 'title' => 'Judul Masih Pending']);
        ITRequest::factory()->create(['type' => 'BAK', 'status' => 'Rejected', 'sequence_number' => null, 'title' => 'Judul Sudah Ditolak']);
        ITRequest::factory()->create(['type' => 'WO', 'status' => 'Approved', 'sequence_number' => 1, 'title' => 'Judul Sudah Disetujui']);

        $response = $this->actingAs($admin)->getJson('/requisition/requests/list-all-form');

        $rowsHtml = $response->json('rows_html');
        $this->assertStringNotContainsString('Judul Masih Pending', $rowsHtml);
        $this->assertStringNotContainsString('Judul Sudah Ditolak', $rowsHtml);
        $this->assertStringContainsString('Judul Sudah Disetujui', $rowsHtml);
    }

    public function test_list_all_form_shows_the_requesters_username(): void
    {
        $admin = $this->makeUser('admin');
        $owner = $this->makeUser('user', ['username' => 'budi.santoso']);
        ITRequest::factory()->create([
            'type' => 'WO', 'status' => 'Approved', 'sequence_number' => 1,
            'user_id' => $owner->id, 'title' => 'Judul Punya Budi',
        ]);

        $response = $this->actingAs($admin)->getJson('/requisition/requests/list-all-form');

        $this->assertStringContainsString('budi.santoso', $response->json('rows_html'));
    }

    public function test_previously_processed_request_reverted_to_pending_still_shows_in_list_all_form(): void
    {
        // Beda dengan Pending yang benar-benar baru (test di atas, sequence_number null & disembunyikan
        // dari sini) - kalau statusnya DIREVERT ke Pending setelah sempat diproses (sequence_number
        // sudah terisi), tetap harus tampil di List All Form, bukan menghilang begitu saja.
        $admin = $this->makeUser('admin');
        ITRequest::factory()->create(['type' => 'ITR', 'status' => 'Pending', 'sequence_number' => 9, 'title' => 'Direvert Ke Pending']);

        $response = $this->actingAs($admin)->getJson('/requisition/requests/list-all-form');

        $this->assertStringContainsString('Direvert Ke Pending', $response->json('rows_html'));
    }

    public function test_dashboard_pending_badge_count_matches_list_all_form_visibility(): void
    {
        // Regresi: badge "Pending" di dashboard sebelumnya menghitung SEMUA request Pending (termasuk
        // yang belum pernah diproses, yang memang sengaja tidak muncul di List All Form - ada di panel
        // "Incoming Requests" sendiri). Klik badge-nya untuk filter List All Form jadi kelihatan kosong
        // walau angkanya bukan 0. Sekarang badge-nya harus dihitung dari scope yang sama dengan tabel.
        $admin = $this->makeUser('admin');

        // Belum pernah diproses - TIDAK ikut dihitung (bukan bagian List All Form).
        ITRequest::factory()->create(['type' => 'ITR', 'status' => 'Pending', 'sequence_number' => null]);
        ITRequest::factory()->peminjamanAset()->create(['status' => 'Pending', 'sequence_number' => null]);

        // Sudah pernah diproses lalu direvert ke Pending - IKUT dihitung (tampil di List All Form).
        ITRequest::factory()->create(['type' => 'BAK', 'status' => 'Pending', 'sequence_number' => 3]);

        $response = $this->actingAs($admin)->get('/requisition');

        $response->assertOk();
        $response->assertViewHas('pendingRequests', 1);
    }

    public function test_updating_status_records_the_acting_admin_as_approver(): void
    {
        $admin = $this->makeUser('admin', ['name' => 'Admin Penyetuju']);
        $item = ITRequest::factory()->create(['type' => 'ITR', 'status' => 'Pending', 'sequence_number' => null]);

        $this->actingAs($admin)->patch("/requisition/requests/{$item->id}/status", ['status' => 'Approved']);

        $item->refresh();
        $this->assertEquals($admin->id, $item->approved_by);

        $response = $this->actingAs($admin)->getJson('/requisition/requests/list-all-form');
        $this->assertStringContainsString('Admin Penyetuju', $response->json('rows_html'));
    }

    public function test_a_later_status_change_by_a_different_admin_does_not_overwrite_the_original_approver(): void
    {
        // Skenario nyata: Admin A approve request, lalu Admin B (atau siapa pun) yang lanjut ubah
        // statusnya ke In Progress / Completed. Kolom "Disetujui Oleh" harus tetap nama Admin A.
        $firstApprover = $this->makeUser('admin', ['name' => 'Admin Pertama']);
        $laterAdmin = $this->makeUser('admin', ['name' => 'Admin Kedua']);
        $item = ITRequest::factory()->create(['type' => 'ITR', 'status' => 'Pending', 'sequence_number' => null]);

        $this->actingAs($firstApprover)->patch("/requisition/requests/{$item->id}/status", ['status' => 'Approved']);
        $this->actingAs($laterAdmin)->patch("/requisition/requests/{$item->id}/status", ['status' => 'In Progress']);
        $this->actingAs($laterAdmin)->patch("/requisition/requests/{$item->id}/status", ['status' => 'Completed']);

        $item->refresh();
        $this->assertEquals($firstApprover->id, $item->approved_by);
    }

    public function test_sequence_number_uses_approval_month_not_creation_month(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->create(['type' => 'ITR', 'status' => 'Pending', 'sequence_number' => null]);

        // Request diajukan bulan lalu tapi baru disetujui sekarang
        $lastMonth = now()->subMonthNoOverflow();
        DB::table('i_t_requests')->where('id', $item->id)->update(['created_at' => $lastMonth]);

        $this->actingAs($admin)->patch("/requisition/requests/{$item->id}/status", ['status' => 'Approved']);

        $item->refresh();
        $this->assertStringContainsString(now()->format('ym'), $item->ticket_number);
        $this->assertSame(1, $item->sequence_number);
    }

    public function test_numbers_follow_approval_order_not_submission_order(): void
    {
        $admin = $this->makeUser('admin');
        $first = ITRequest::factory()->create(['type' => 'WO', 'status' => 'Pending', 'sequence_number' => null, 'created_at' => now()->subDays(2)]);
        $second = ITRequest::factory()->create(['type' => 'WO', 'status' => 'Pending', 'sequence_number' => null, 'created_at' => now()->subDay()]);

        // Yang diajukan belakangan disetujui lebih dulu
        $this->actingAs($admin)->patch("/requisition/requests/{$second->id}/status", ['status' => 'Approved']);
        $this->actingAs($admin)->patch("/requisition/requests/{$first->id}/status", ['status' => 'Approved']);

        $this->assertSame(1, $second->fresh()->sequence_number);
        $this->assertSame(2, $first->fresh()->sequence_number);
    }

    public function test_legacy_numbers_keep_their_creation_month_code(): void
    {
        $lastMonth = now()->subMonthNoOverflow();
        $legacy = ITRequest::factory()->create(['type' => 'ITR', 'sequence_number' => 7, 'numbered_at' => null, 'created_at' => $lastMonth]);

        $this->assertStringContainsString($lastMonth->format('ym'), $legacy->ticket_number);
    }

    public function test_list_all_form_puts_the_most_recently_approved_at_the_top(): void
    {
        $admin = $this->makeUser('admin');
        ITRequest::factory()->create(['type' => 'ITR', 'status' => 'Approved', 'sequence_number' => 1, 'numbered_at' => now()->subHour(), 'title' => 'Disetujui Duluan']);
        ITRequest::factory()->create(['type' => 'ITR', 'status' => 'Approved', 'sequence_number' => 2, 'numbered_at' => now(), 'title' => 'Disetujui Terakhir']);

        $html = $this->actingAs($admin)->getJson('/requisition/requests/list-all-form')->json('rows_html');

        $this->assertLessThan(strpos($html, 'Disetujui Duluan'), strpos($html, 'Disetujui Terakhir'));
    }
}
