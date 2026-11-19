<?php

namespace Tests\Requisition;

use App\Models\ITRequest;
use App\Models\User;
use Tests\RequisitionTestCase;

class PdfGenerationTest extends RequisitionTestCase
{

    private function assertRendersPdf($response): void
    {
        $response->assertOk();
        $this->assertStringContainsString('pdf', strtolower($response->headers->get('content-type')));
    }

    public function test_admin_can_view_itr_pdf(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->create(['type' => 'ITR', 'status' => 'Approved']);

        $this->assertRendersPdf($this->actingAs($admin)->get("/requisition/requests/{$item->id}/pdf?preview=1"));
    }

    public function test_admin_can_view_bak_pdf(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->bak()->create(['status' => 'Approved']);

        $this->assertRendersPdf($this->actingAs($admin)->get("/requisition/requests/{$item->id}/pdf?preview=1"));
    }

    public function test_admin_can_view_surat_jalan_pdf(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->suratJalan()->create(['status' => 'Approved']);

        $this->assertRendersPdf($this->actingAs($admin)->get("/requisition/requests/{$item->id}/pdf?preview=1"));
    }

    public function test_admin_can_view_tt_pdf(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->tt()->create(['status' => 'Approved']);

        $this->assertRendersPdf($this->actingAs($admin)->get("/requisition/requests/{$item->id}/pdf?preview=1"));
    }

    public function test_admin_can_view_wo_pdf(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->wo()->create(['status' => 'Approved']);

        $this->assertRendersPdf($this->actingAs($admin)->get("/requisition/requests/{$item->id}/pdf?preview=1"));
    }

    public function test_ticket_sistem_has_no_pdf_even_for_admin(): void
    {
        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->ticketSistem()->create(['status' => 'Approved']);

        $response = $this->actingAs($admin)->get("/requisition/requests/{$item->id}/pdf?preview=1");

        $response->assertNotFound();
    }

    public function test_owner_cannot_view_pdf_while_still_pending(): void
    {
        $owner = $this->makeUser('user');
        $item = ITRequest::factory()->create([
            'type' => 'ITR',
            'user_id' => $owner->id,
            'status' => 'Pending',
        ]);

        $response = $this->actingAs($owner)->get("/requisition/requests/{$item->id}/pdf?preview=1");

        $response->assertForbidden();
    }

    public function test_owner_can_view_pdf_once_approved(): void
    {
        $owner = $this->makeUser('user');
        $item = ITRequest::factory()->create([
            'type' => 'ITR',
            'user_id' => $owner->id,
            'status' => 'Approved',
        ]);

        $this->assertRendersPdf($this->actingAs($owner)->get("/requisition/requests/{$item->id}/pdf?preview=1"));
    }

    public function test_user_cannot_view_someone_elses_pdf(): void
    {
        $owner = $this->makeUser('user');
        $otherUser = $this->makeUser('user');
        $item = ITRequest::factory()->create([
            'type' => 'ITR',
            'user_id' => $owner->id,
            'status' => 'Approved',
        ]);

        $response = $this->actingAs($otherUser)->get("/requisition/requests/{$item->id}/pdf?preview=1");

        $response->assertForbidden();
    }

    public function test_guest_cannot_view_pdf(): void
    {
        $item = ITRequest::factory()->create(['type' => 'ITR', 'status' => 'Approved']);

        $response = $this->get("/requisition/requests/{$item->id}/pdf?preview=1");

        $response->assertRedirect(url('/admin/login'));
    }
}
