<?php

namespace Tests\Requisition;

use App\Models\ITRequest;
use Illuminate\Support\Facades\Http;
use Tests\RequisitionTestCase;

/** Prioritas dihapus dari semua form, dan tombol Approve tampil jelas berbeda antara terkunci dan aktif. */
class PriorityRemovedAndApproveButtonTest extends RequisitionTestCase
{
    public function test_no_form_asks_for_priority_anymore(): void
    {
        $user = $this->makeUser('user');
        $html = $this->actingAs($user)->get('/requisition/requests/create')->assertOk()->getContent();
        $this->assertStringNotContainsString('name="priority"', $html);
        $this->assertStringNotContainsString('Prioritas', $html);
        $this->assertStringNotContainsString('editPriority', $html);
        $this->assertStringNotContainsString('publicPriorityFilter', $html);
    }

    public function test_dashboard_tables_filters_and_modals_have_no_priority(): void
    {
        $admin = $this->makeUser('admin');
        ITRequest::factory()->create(['type' => 'TICKET_SISTEM', 'status' => 'Pending', 'title' => 'Printer macet', 'sequence_number' => null]);
        ITRequest::factory()->create(['type' => 'WO', 'status' => 'In Progress', 'sequence_number' => 1, 'numbered_at' => now(), 'approved_at' => now()]);

        $html = $this->actingAs($admin)->get('/requisition')->assertOk()->getContent();
        $this->assertStringNotContainsString('Prioritas', $html);
        $this->assertStringNotContainsString('priorityFilter', $html);
        $this->assertStringNotContainsString('modalPriority', $html);
        $this->assertStringNotContainsString('name="priority"', $html);

        $json = $this->actingAs($admin)->getJson('/requisition/requests/pending-approvals')->assertOk()->json();
        $this->assertStringNotContainsString('Prioritas', $json['rows_html']);
        $all = $this->actingAs($admin)->getJson('/requisition/requests/list-all-form?priority=P1')->assertOk()->json();   // parameter lama diabaikan, tidak error
        $this->assertStringNotContainsString('Prioritas', $all['rows_html']);
    }

    public function test_submitting_without_priority_works_and_an_old_priority_value_is_not_stored(): void
    {
        $user = $this->makeUser('user');
        $emp = $this->makeRequester(['name' => 'Andi Saputra']);
        $payload = ['type' => 'TICKET_SISTEM', 'title' => 'Printer macet', 'category' => 'Hardware', 'employee_name' => 'Andi Saputra', 'employee_id' => $emp->id,
            'employee_nik' => $emp->nik, 'department' => 'IT', 'position' => 'Staff', 'description' => 'Uji', 'admin_notes' => 'Ruang server'];

        $this->actingAs($user)->post('/requisition/requests', $payload)->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/requisition/requests', $payload + ['priority' => 'P1', 'title' => 'Kirim dengan nilai lama'])->assertSessionHasNoErrors();

        $this->assertSame(2, ITRequest::where('type', 'TICKET_SISTEM')->count());
        $this->assertSame(0, ITRequest::where('priority', 'P1')->count(), 'nilai prioritas dari klien tidak lagi disimpan');
    }

    public function test_telegram_message_has_no_priority_line(): void
    {
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.chat_id' => '-100123456789']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $user = $this->makeUser('user');
        $this->actingAs($user)->post('/requisition/requests', [
            'type' => 'ITR', 'employee_name' => 'Budi Santoso', 'employee_id' => $this->requesterId(), 'employee_nik' => '123456', 'department' => 'IT', 'position' => 'Staff',
            'title' => 'Laptop Baru', 'items' => [['name' => 'Laptop', 'qty' => 1]], 'description' => 'Untuk kerja',
        ]);
        Http::assertSent(fn ($r) => str_contains($r['text'], 'Laptop Baru') && ! str_contains($r['text'], 'Prioritas'));
    }

    public function test_generated_pdfs_show_the_status_instead_of_priority(): void
    {
        $admin = $this->makeUser('admin');
        foreach (['ITR', 'WO'] as $type) {
            $item = ITRequest::factory()->create(['type' => $type, 'status' => 'In Progress', 'sequence_number' => 5, 'numbered_at' => now(), 'approved_at' => now()]);
            $res = $this->actingAs($admin)->get("/requisition/requests/{$item->id}/pdf")->assertOk();
            $this->assertStringStartsWith('%PDF', $res->getContent());
        }
        $this->assertStringNotContainsString("priority", file_get_contents(resource_path('views/requisition/pdf_template.blade.php')));
        $this->assertStringNotContainsString("priority", file_get_contents(resource_path('views/requisition/pdf_template_wo.blade.php')));
    }

    public function test_edit_success_message_does_not_print_empty_quotes_for_unnumbered_requests(): void
    {
        $owner = $this->makeUser('user');
        $item = ITRequest::factory()->create(['type' => 'ITR', 'user_id' => $owner->id, 'status' => 'Rejected', 'title' => 'Laptop Baru', 'sequence_number' => null]);
        $res = $this->actingAs($owner)->patchJson("/requisition/requests/{$item->id}", [
            'employee_name' => 'Budi Santoso Revisi', 'employee_nik' => '123456', 'position' => 'Staff', 'title' => 'Laptop Baru (Revisi)',
            'items' => [['name' => 'Laptop', 'qty' => 1]], 'description' => 'Diperbaiki',
        ])->assertOk();
        $this->assertStringNotContainsString('Request ""', $res->json('message'));
        $this->assertStringContainsString('Laptop Baru (Revisi)', $res->json('message'));
    }

    public function test_approve_button_has_its_own_style_and_titles_that_follow_the_view_gate(): void
    {
        $admin = $this->makeUser('admin');
        ITRequest::factory()->create(['type' => 'ITR', 'status' => 'Pending', 'title' => 'Laptop uji', 'sequence_number' => null]);
        ITRequest::factory()->create(['type' => 'BAK', 'status' => 'Pending', 'title' => 'BAK uji', 'analisa' => null, 'rekomendasi' => null, 'sequence_number' => null]);

        $rows = $this->actingAs($admin)->getJson('/requisition/requests/pending-approvals')->assertOk()->json('rows_html');
        $this->assertSame(2, substr_count($rows, 'btn-approve'), 'Approve biasa dan Approve BAK sama-sama memakai kelas khusus');
        $this->assertStringContainsString('data-ready-title="Setujui request ini', $rows);
        $this->assertStringContainsString('data-locked-title="Klik View dulu sebelum bisa Approve"', $rows);
        $this->assertStringContainsString('data-ready-title="Tolak request ini"', $rows);

        // aturan CSS: aktif = biru merek, terkunci = abu; harus lebih spesifik daripada aturan badge (bg-emerald-500/…) yang dulu membuatnya pucat
        $css = file_get_contents(public_path('css/requisition-theme.css'));
        $this->assertStringContainsString('button.btn-approve {', $css);
        $this->assertStringContainsString('button.btn-approve:disabled {', $css);
        $this->assertGreaterThan(strrpos($css, '[class*="bg-emerald-500/"]'), strrpos($css, 'button.btn-approve'), 'aturan tombol Approve berada setelah aturan badge');

        // gate dibuka langsung saat halaman dimuat (bukan menunggu polling pertama)
        $page = $this->actingAs($admin)->get('/requisition')->assertOk()->getContent();
        $this->assertStringContainsString('baris dari server selalu dirender terkunci', $page);
        $this->assertMatchesRegularExpression('/\n        applyViewGate\(\);\n\n        \/\/ Auto-refresh "Incoming Requests"/', $page);
        $this->assertStringContainsString('btn.dataset.readyTitle', $page);
    }
}
