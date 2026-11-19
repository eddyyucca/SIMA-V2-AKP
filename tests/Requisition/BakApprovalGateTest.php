<?php

namespace Tests\Requisition;

use App\Models\ITRequest;
use App\Models\User;
use Tests\RequisitionTestCase;

// Aturan: BAK baru bisa di-approve (pertama kali) setelah tim IT mengisi Analisa DAN Rekomendasi.
class BakApprovalGateTest extends RequisitionTestCase
{

    private function admin(): User
    {
        return $this->makeUser('admin');
    }

    private function pendingBak(array $overrides = []): ITRequest
    {
        return ITRequest::factory()->bak()->create(array_merge([
            'status' => 'Pending',
            'sequence_number' => null,
            'analisa' => null,
            'rekomendasi' => null,
        ], $overrides));
    }

    public function test_bak_cannot_be_approved_without_analisa_and_rekomendasi(): void
    {
        $bak = $this->pendingBak();

        $response = $this->actingAs($this->admin())->patch("/requisition/requests/{$bak->id}/status", ['status' => 'In Progress']);

        $response->assertSessionHas('error');
        $bak->refresh();
        $this->assertSame('Pending', $bak->status);
        $this->assertNull($bak->approved_at);
        $this->assertNull($bak->approved_by);
        $this->assertNull($bak->sequence_number, 'Nomor tiket tidak boleh terpakai untuk BAK yang gagal di-approve.');
    }

    public function test_bak_approval_via_json_returns_422_when_incomplete(): void
    {
        $bak = $this->pendingBak();

        $response = $this->actingAs($this->admin())->patchJson("/requisition/requests/{$bak->id}/status", ['status' => 'Approved']);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
        $this->assertSame('Pending', $bak->fresh()->status);
    }

    public function test_bak_needs_both_fields_not_just_one_and_whitespace_does_not_count(): void
    {
        $admin = $this->admin();

        $onlyAnalisa = $this->pendingBak(['analisa' => 'Kabel putus', 'rekomendasi' => null]);
        $this->actingAs($admin)->patch("/requisition/requests/{$onlyAnalisa->id}/status", ['status' => 'In Progress']);
        $this->assertSame('Pending', $onlyAnalisa->fresh()->status);

        $whitespace = $this->pendingBak(['analisa' => 'Kabel putus', 'rekomendasi' => "   \n  "]);
        $this->actingAs($admin)->patch("/requisition/requests/{$whitespace->id}/status", ['status' => 'In Progress']);
        $this->assertSame('Pending', $whitespace->fresh()->status);
    }

    public function test_bak_can_be_approved_once_analisa_and_rekomendasi_are_filled(): void
    {
        $admin = $this->admin();
        $bak = $this->pendingBak(['analisa' => 'Layar retak.', 'rekomendasi' => 'Ganti layar.']);

        $response = $this->actingAs($admin)->patch("/requisition/requests/{$bak->id}/status", ['status' => 'In Progress']);

        $response->assertSessionHasNoErrors();
        $response->assertSessionMissing('error');
        $bak->refresh();
        $this->assertSame('In Progress', $bak->status);
        $this->assertSame($admin->id, $bak->approved_by);
        $this->assertNotNull($bak->approved_at);
        $this->assertNotNull($bak->sequence_number);
    }

    public function test_bak_can_still_be_rejected_without_analisa_and_rekomendasi(): void
    {
        $bak = $this->pendingBak();

        $this->actingAs($this->admin())->patch("/requisition/requests/{$bak->id}/status", ['status' => 'Rejected'])
            ->assertSessionMissing('error');

        $this->assertSame('Rejected', $bak->fresh()->status);
    }

    public function test_a_rejected_bak_is_blocked_again_when_approved_without_the_fields(): void
    {
        // Pernah ditolak lalu diperbaiki user: belum pernah disetujui (approved_at null), jadi aturan tetap berlaku.
        $bak = $this->pendingBak(['status' => 'Rejected']);

        $this->actingAs($this->admin())->patch("/requisition/requests/{$bak->id}/status", ['status' => 'In Progress']);

        $this->assertSame('Rejected', $bak->fresh()->status);
    }

    public function test_other_request_types_are_not_affected_by_the_bak_rule(): void
    {
        $admin = $this->admin();

        foreach (['ITR', 'WO', 'TICKET_SISTEM'] as $type) {
            $item = ITRequest::factory()->create(['type' => $type, 'status' => 'Pending', 'analisa' => null, 'rekomendasi' => null]);
            $this->actingAs($admin)->patch("/requisition/requests/{$item->id}/status", ['status' => 'In Progress'])
                ->assertSessionMissing('error');
            $this->assertSame('In Progress', $item->fresh()->status, "Tipe {$type} seharusnya tetap bisa di-approve tanpa Analisa.");
        }
    }

    public function test_bak_approved_before_the_rule_existed_can_still_be_completed(): void
    {
        // BAK lama yang sudah disetujui (approved_at terisi) tapi Analisa/Rekomendasi kosong tidak boleh terkunci
        // saat statusnya dilanjutkan ke Completed.
        $bak = $this->pendingBak([
            'status' => 'In Progress', 'sequence_number' => 7,
            'approved_at' => now()->subDay(), 'approved_by' => $this->admin()->id,
        ]);

        $this->actingAs($this->admin())->patch("/requisition/requests/{$bak->id}/status", ['status' => 'Completed'])
            ->assertSessionMissing('error');

        $this->assertSame('Completed', $bak->fresh()->status);
    }

    public function test_incoming_panel_locks_approve_only_for_incomplete_bak(): void
    {
        $admin = $this->admin();
        $this->pendingBak(['title' => 'BAK Belum Lengkap']);
        $complete = $this->pendingBak(['title' => 'BAK Sudah Lengkap', 'analisa' => 'A', 'rekomendasi' => 'R']);
        ITRequest::factory()->create(['type' => 'ITR', 'status' => 'Pending', 'sequence_number' => null, 'title' => 'ITR Biasa']);

        $html = $this->actingAs($admin)->getJson('/requisition/requests/pending-approvals')->json('rows_html');

        $this->assertSame(1, substr_count($html, 'Isi Analisa &amp; Rekomendasi lewat'), 'Petunjuk hanya muncul di baris BAK yang belum lengkap, tidak terbawa ke baris lain.');
        $this->assertStringContainsString('title="Isi Analisa & Rekomendasi dulu lewat tombol Edit"', $html);
        // BAK yang lengkap + ITR: tombol Approve biasa (form PATCH status In Progress), 2 buah.
        $this->assertSame(2, substr_count($html, 'name="status" value="In Progress"'));
        $this->assertStringContainsString("/requisition/requests/{$complete->id}/status", $html);
    }
}
