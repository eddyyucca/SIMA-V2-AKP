<?php

namespace Tests\Requisition;

use App\Models\ITRequest;
use App\Models\User;
use App\Notifications\PeminjamanReturnReminder;
use Illuminate\Support\Facades\Notification;
use Tests\RequisitionTestCase;

class PeminjamanTest extends RequisitionTestCase
{

    public function test_user_role_can_submit_a_peminjaman_aset_request(): void
    {
        $user = $this->makeUser('user');

        $response = $this->actingAs($user)->post('/requisition/requests', [
            'type' => 'PEMINJAMAN_ASET',
            'employee_name' => 'Budi Santoso', 'employee_id' => $this->requesterId(),
            'employee_nik' => '123456',
            'department' => 'IT',
            'position' => 'Staff',
            'title' => 'Survey lapangan',
            'loan_start_date' => now()->toDateString(),
            'loan_end_date' => now()->addDays(2)->toDateString(),
            'items' => [['name' => 'Laptop Asus', 'qty' => 1, 'spesifikasi' => 'i5/8GB', 'serial_number' => 'SN-1']],
            'description' => 'Untuk keperluan survey.',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('i_t_requests', [
            'type' => 'PEMINJAMAN_ASET',
            'requester_name' => 'Budi Santoso',
            'status' => 'Pending',
        ]);

        // Sama seperti ITR/BAK/TICKET_SISTEM/WO: belum dapat nomor tiket sebelum di-approve
        $item = ITRequest::first();
        $this->assertNull($item->sequence_number);
    }

    public function test_peminjaman_aset_requires_loan_start_and_end_date(): void
    {
        $user = $this->makeUser('user');

        $response = $this->actingAs($user)->post('/requisition/requests', [
            'type' => 'PEMINJAMAN_ASET',
            'employee_name' => 'Budi Santoso', 'employee_id' => $this->requesterId(),
            'employee_nik' => '123456',
            'position' => 'Staff',
            'title' => 'Survey lapangan',
            'description' => 'Untuk keperluan survey.',
            // loan_start_date & loan_end_date sengaja tidak diisi
        ]);

        $response->assertSessionHasErrors(['loan_start_date', 'loan_end_date']);
        $this->assertDatabaseCount('i_t_requests', 0);
    }

    public function test_peminjaman_aset_rejects_end_date_before_start_date(): void
    {
        $user = $this->makeUser('user');

        $response = $this->actingAs($user)->post('/requisition/requests', [
            'type' => 'PEMINJAMAN_ASET',
            'employee_name' => 'Budi Santoso', 'employee_id' => $this->requesterId(),
            'employee_nik' => '123456',
            'position' => 'Staff',
            'title' => 'Survey lapangan',
            'loan_start_date' => now()->toDateString(),
            'loan_end_date' => now()->subDay()->toDateString(),
            'description' => 'Untuk keperluan survey.',
        ]);

        $response->assertSessionHasErrors('loan_end_date');
        $this->assertDatabaseCount('i_t_requests', 0);
    }

    public function test_peminjaman_aset_allows_same_day_loan_and_return(): void
    {
        // Tanggal-only, jadi pinjam & rencana kembali di hari yang sama tetap boleh (mis. pinjam pagi,
        // kembali sore hari itu juga) - bukan error, durasinya cuma jadi "1 hari".
        $user = $this->makeUser('user');
        $today = now()->toDateString();

        $response = $this->actingAs($user)->post('/requisition/requests', [
            'type' => 'PEMINJAMAN_ASET',
            'employee_name' => 'Budi Santoso', 'employee_id' => $this->requesterId(),
            'employee_nik' => '123456',
            'position' => 'Staff',
            'title' => 'Pinjam proyektor rapat',
            'loan_start_date' => $today,
            'loan_end_date' => $today,
            'items' => [['name' => 'Proyektor Epson']],
            'description' => 'Untuk rapat internal.',
        ]);

        $response->assertSessionHasNoErrors();
        $item = ITRequest::first();
        $this->assertEquals('1 hari', $item->loan_duration_label);
        $this->assertEquals(1, $item->loan_duration_days);
    }

    public function test_peminjaman_aset_does_not_appear_in_list_all_form(): void
    {
        $admin = $this->makeUser('admin');
        ITRequest::factory()->peminjamanAset()->create(['status' => 'Approved', 'sequence_number' => 1]);
        ITRequest::factory()->create(['type' => 'ITR', 'status' => 'Approved', 'sequence_number' => 1]);

        $response = $this->actingAs($admin)->getJson('/requisition/requests/list-all-form');

        $response->assertOk();
        $this->assertStringNotContainsString('Keperluan survey lapangan', $response->json('rows_html'));
    }

    public function test_peminjaman_aset_appears_in_public_history_immediately_when_pending(): void
    {
        // Sama seperti tipe lain: langsung kelihatan begitu berhasil dibuat, dengan status
        // "Menunggu Approval" - tidak perlu menunggu diproses tim IT dulu.
        $user = $this->makeUser('user');
        ITRequest::factory()->peminjamanAset()->create(['status' => 'Pending', 'sequence_number' => null, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson('/requisition/requests/history');

        $response->assertOk();
        $rowsHtml = $response->json('rows_html');
        $this->assertStringContainsString('Keperluan survey lapangan', $rowsHtml);
        $this->assertStringContainsString('Menunggu Approval', $rowsHtml);
    }

    public function test_short_peminjaman_hides_pdf_button_in_public_history(): void
    {
        $user = $this->makeUser('user');
        // Default factory state: 3 hari, di bawah ambang 7 hari
        ITRequest::factory()->peminjamanAset()->create(['status' => 'Approved', 'sequence_number' => 1, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson('/requisition/requests/history');

        $response->assertOk();
        $this->assertStringNotContainsString('📄 PDF', $response->json('rows_html'));
    }

    public function test_owner_can_resubmit_a_rejected_peminjaman_without_spesifikasi_or_serial_number(): void
    {
        // Form edit di sisi role user cuma kirim Nama Aset (Spesifikasi & Serial Number disembunyikan
        // di UI) - pastikan resubmit tetap berhasil tanpa field itu, bukan malah gagal validasi.
        $user = $this->makeUser('user');
        $item = ITRequest::factory()->peminjamanAset()->create(['status' => 'Rejected', 'user_id' => $user->id]);

        $response = $this->actingAs($user)->patch("/requisition/requests/{$item->id}", [
            'employee_name' => 'Budi Santoso', 'employee_id' => $this->requesterId(),
            'employee_nik' => '123456',
            'position' => 'Staff',
            'title' => 'Survey lapangan revisi',
            'loan_start_date' => $item->loan_start_date->toDateString(),
            'loan_end_date' => $item->loan_end_date->toDateString(),
            'items' => [['name' => 'Laptop Asus']],
            'description' => 'Diperbaiki sesuai catatan IT.',
        ]);

        $response->assertSessionHasNoErrors();
        $item->refresh();
        $this->assertEquals('Pending', $item->status);
        $this->assertEquals('Survey lapangan revisi', $item->title);
    }

    public function test_user_role_cannot_access_peminjaman_list_page(): void
    {
        $user = $this->makeUser('user');

        $this->actingAs($user)->get('/requisition/peminjaman')->assertForbidden();
    }

    public function test_admin_can_view_peminjaman_list(): void
    {
        $admin = $this->makeUser('admin');
        ITRequest::factory()->peminjamanAset()->create(['status' => 'Approved', 'sequence_number' => 1]);

        $response = $this->actingAs($admin)->get('/requisition/peminjaman');

        $response->assertOk();
        $response->assertSee('Keperluan survey lapangan');
    }

    public function test_peminjaman_list_shows_the_requesters_username(): void
    {
        $admin = $this->makeUser('admin');
        $owner = $this->makeUser('user', ['username' => 'budi.santoso']);
        ITRequest::factory()->peminjamanAset()->create(['status' => 'Approved', 'sequence_number' => 1, 'user_id' => $owner->id]);

        $response = $this->actingAs($admin)->get('/requisition/peminjaman');

        $response->assertOk();
        $response->assertSee('budi.santoso');
    }

    public function test_pending_peminjaman_does_not_appear_in_the_peminjaman_list(): void
    {
        // Pending sekarang tampil di panel "Incoming Requests" dashboard, bukan di list Peminjaman -
        // baru pindah ke sana setelah statusnya berubah (Approved/In Progress/Completed/Rejected).
        $admin = $this->makeUser('admin');
        ITRequest::factory()->peminjamanAset()->create(['status' => 'Pending', 'sequence_number' => null, 'title' => 'Masih Pending Judul']);

        $response = $this->actingAs($admin)->get('/requisition/peminjaman');

        $response->assertOk();
        $response->assertDontSee('Masih Pending Judul');
    }

    public function test_pending_peminjaman_appears_in_incoming_requests_panel(): void
    {
        $admin = $this->makeUser('admin');
        ITRequest::factory()->peminjamanAset()->create(['status' => 'Pending', 'sequence_number' => null, 'title' => 'Perlu Approval Peminjaman']);

        $response = $this->actingAs($admin)->getJson('/requisition/requests/pending-approvals');

        $response->assertOk();
        $this->assertStringContainsString('Perlu Approval Peminjaman', $response->json('rows_html'));
        $this->assertEquals(1, $response->json('count'));
    }

    public function test_previously_processed_peminjaman_reverted_to_pending_still_shows_in_peminjaman_list(): void
    {
        // Beda dengan Pending yang benar-benar baru (test di atas, sequence_number null) - kalau
        // statusnya DIREVERT ke Pending setelah sempat diproses (sequence_number sudah terisi), tetap
        // tampil di list Peminjaman, bukan menghilang begitu saja.
        $admin = $this->makeUser('admin');
        ITRequest::factory()->peminjamanAset()->create(['status' => 'Pending', 'sequence_number' => 9, 'title' => 'Peminjaman Direvert']);

        $response = $this->actingAs($admin)->get('/requisition/peminjaman');

        $response->assertOk();
        $response->assertSee('Peminjaman Direvert');
    }

    public function test_reverting_an_already_processed_peminjaman_to_pending_does_not_reappear_in_incoming(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->peminjamanAset()->create([
            'status' => 'Approved',
            'sequence_number' => 5,
            'title' => 'Peminjaman Pernah Diproses',
        ]);

        $this->actingAs($admin)->patch("/requisition/requests/{$item->id}/status", ['status' => 'Pending']);

        $item->refresh();
        $this->assertEquals('Pending', $item->status);

        $response = $this->actingAs($admin)->getJson('/requisition/requests/pending-approvals');
        $this->assertStringNotContainsString('Peminjaman Pernah Diproses', $response->json('rows_html'));
    }

    public function test_rejected_peminjaman_still_shows_in_the_peminjaman_list(): void
    {
        $admin = $this->makeUser('admin');
        ITRequest::factory()->peminjamanAset()->create(['status' => 'Rejected', 'title' => 'Peminjaman Ditolak']);

        $response = $this->actingAs($admin)->get('/requisition/peminjaman');

        $response->assertOk();
        $response->assertSee('Peminjaman Ditolak');
    }

    public function test_approving_a_peminjaman_records_approved_at_and_assigns_a_ticket_number(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->peminjamanAset()->create(['status' => 'Pending', 'sequence_number' => null]);

        $this->actingAs($admin)->patch("/requisition/requests/{$item->id}/status", ['status' => 'In Progress']);

        $item->refresh();
        $this->assertNotNull($item->approved_at);
        $this->assertNotNull($item->sequence_number);
        $this->assertStringStartsWith('AKP-PJ', $item->ticket_number);
    }

    public function test_completing_a_peminjaman_records_completed_at(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->peminjamanAset()->create(['status' => 'In Progress', 'approved_by' => $admin->id, 'approved_at' => now()->subHour()]);

        $this->actingAs($admin)->patch("/requisition/requests/{$item->id}/status", ['status' => 'Completed']);

        $item->refresh();
        $this->assertNotNull($item->completed_at);
    }

    public function test_short_loan_has_no_pdf(): void
    {
        $admin = $this->makeUser('admin');
        // Default factory state: 3 hari, di bawah ambang 7 hari
        $item = ITRequest::factory()->peminjamanAset()->create(['status' => 'Completed', 'sequence_number' => 1]);

        $this->assertEquals(3, $item->loan_duration_days);
        $this->assertEquals('3 hari', $item->loan_duration_label);

        $response = $this->actingAs($admin)->get("/requisition/requests/{$item->id}/pdf");

        $response->assertNotFound();
    }

    public function test_long_loan_of_seven_days_or_more_has_a_pdf_with_signature_blocks(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->peminjamanAset()->create([
            'status' => 'Completed',
            'sequence_number' => 1,
            'loan_start_date' => now()->toDateString(),
            'loan_end_date' => now()->addDays(6)->toDateString(),
        ]);

        $this->assertEquals(7, $item->loan_duration_days);
        $this->assertEquals('7 hari', $item->loan_duration_label);

        $response = $this->actingAs($admin)->get("/requisition/requests/{$item->id}/pdf");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_admin_can_edit_a_peminjaman_to_fill_in_spesifikasi_and_serial_number(): void
    {
        // Skenario utama: user cuma isi Nama Aset saat submit, IT melengkapi Spesifikasi &
        // Serial Number belakangan lewat Edit.
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->create([
            'type' => 'PEMINJAMAN_ASET',
            'status' => 'Pending',
            'title' => 'Survey lapangan',
            'items' => [['name' => 'Laptop Asus']],
            'loan_start_date' => now()->toDateString(),
            'loan_end_date' => now()->addDays(2)->toDateString(),
        ]);

        $response = $this->actingAs($admin)->patch("/requisition/requests/{$item->id}", [
            'employee_name' => 'Budi Santoso', 'employee_id' => $this->requesterId(),
            'employee_nik' => '123456',
            'position' => 'Staff',
            'title' => 'Survey lapangan',
            'loan_start_date' => $item->loan_start_date->toDateString(),
            'loan_end_date' => $item->loan_end_date->toDateString(),
            'items' => [['name' => 'Laptop Asus', 'spesifikasi' => 'i5/8GB', 'serial_number' => 'SN-99']],
            'description' => 'Untuk keperluan survey.',
        ]);

        $response->assertSessionHasNoErrors();
        $item->refresh();
        $this->assertEquals('SN-99', $item->items[0]['serial_number']);
        $this->assertEquals('i5/8GB', $item->items[0]['spesifikasi']);
    }

    public function test_editing_a_peminjaman_requires_loan_dates(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->peminjamanAset()->create(['status' => 'Pending']);

        $response = $this->actingAs($admin)->patch("/requisition/requests/{$item->id}", [
            'employee_name' => 'Budi Santoso', 'employee_id' => $this->requesterId(),
            'employee_nik' => '123456',
            'position' => 'Staff',
            'title' => 'Survey lapangan',
            'description' => 'Untuk keperluan survey.',
            // loan_start_date & loan_end_date sengaja tidak diisi
        ]);

        $response->assertSessionHasErrors(['loan_start_date', 'loan_end_date']);
    }

    public function test_admin_can_mark_a_peminjaman_as_returned(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->peminjamanAset()->create(['status' => 'In Progress']);

        $response = $this->actingAs($admin)->patch("/requisition/peminjaman/{$item->id}/returned");

        $response->assertSessionHasNoErrors();
        $item->refresh();
        $this->assertNotNull($item->returned_at);
    }

    public function test_user_role_cannot_mark_a_peminjaman_as_returned(): void
    {
        $user = $this->makeUser('user');
        $item = ITRequest::factory()->peminjamanAset()->create(['status' => 'In Progress']);

        $this->actingAs($user)->patch("/requisition/peminjaman/{$item->id}/returned")->assertForbidden();
    }

    public function test_reminder_command_notifies_it_team_for_overdue_unreturned_loans(): void
    {
        Notification::fake();

        $admin = $this->makeUser('admin');
        $overdue = ITRequest::factory()->peminjamanAset()->create([
            'status' => 'In Progress',
            'loan_start_date' => now()->subDays(10)->toDateString(),
            'loan_end_date' => now()->subDays(3)->toDateString(),
            'returned_at' => null,
        ]);

        $this->artisan('peminjaman:remind')->assertExitCode(0);

        Notification::assertSentTo($admin, PeminjamanReturnReminder::class);
    }

    public function test_reminder_command_skips_loans_not_yet_due(): void
    {
        Notification::fake();

        $admin = $this->makeUser('admin');
        ITRequest::factory()->peminjamanAset()->create([
            'status' => 'In Progress',
            'loan_start_date' => now()->toDateString(),
            'loan_end_date' => now()->addDays(3)->toDateString(),
            'returned_at' => null,
        ]);

        $this->artisan('peminjaman:remind')->assertExitCode(0);

        Notification::assertNotSentTo($admin, PeminjamanReturnReminder::class);
    }

    public function test_reminder_command_skips_loans_already_marked_returned(): void
    {
        Notification::fake();

        $admin = $this->makeUser('admin');
        ITRequest::factory()->peminjamanAset()->create([
            'status' => 'In Progress',
            'loan_start_date' => now()->subDays(10)->toDateString(),
            'loan_end_date' => now()->subDays(3)->toDateString(),
            'returned_at' => now()->subDay(),
        ]);

        $this->artisan('peminjaman:remind')->assertExitCode(0);

        Notification::assertNotSentTo($admin, PeminjamanReturnReminder::class);
    }

    public function test_reminder_command_notifies_it_team_30_minutes_before_due(): void
    {
        Notification::fake();

        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->peminjamanAset()->create([
            'status' => 'In Progress',
            'loan_start_date' => now()->subHours(4),
            'loan_end_date' => now()->addMinutes(20),
            'returned_at' => null,
        ]);

        $this->artisan('peminjaman:remind')->assertExitCode(0);

        Notification::assertSentTo($admin, PeminjamanReturnReminder::class);
        $this->assertNotNull($item->fresh()->pre_due_reminder_sent_at);
    }

    public function test_reminder_command_does_not_repeat_the_upcoming_reminder(): void
    {
        Notification::fake();

        $admin = $this->makeUser('admin');
        ITRequest::factory()->peminjamanAset()->create([
            'status' => 'In Progress',
            'loan_start_date' => now()->subHours(4),
            'loan_end_date' => now()->addMinutes(20),
            'returned_at' => null,
        ]);

        $this->artisan('peminjaman:remind');
        $this->artisan('peminjaman:remind');

        Notification::assertSentToTimes($admin, PeminjamanReturnReminder::class, 1);
    }

    public function test_reminder_command_repeats_overdue_reminder_every_six_hours(): void
    {
        Notification::fake();

        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->peminjamanAset()->create([
            'status' => 'In Progress',
            'loan_start_date' => now()->subHours(1),
            'loan_end_date' => now()->subMinute(),
            'returned_at' => null,
        ]);

        // Reminder pertama begitu jatuh tempo.
        $this->artisan('peminjaman:remind');
        Notification::assertSentToTimes($admin, PeminjamanReturnReminder::class, 1);

        // Jalan lagi segera setelahnya - belum 6 jam, jadi tidak kirim ulang.
        $this->artisan('peminjaman:remind');
        Notification::assertSentToTimes($admin, PeminjamanReturnReminder::class, 1);

        // Mundurkan reminder terakhir jadi 7 jam lalu - siklus 6 jam berikutnya harus terkirim.
        $item->update(['last_overdue_reminder_at' => now()->subHours(7)]);
        $this->artisan('peminjaman:remind');
        Notification::assertSentToTimes($admin, PeminjamanReturnReminder::class, 2);
    }
}
