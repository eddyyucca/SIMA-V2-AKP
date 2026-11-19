<?php

namespace Tests\Requisition;

use App\Models\ITRequest;
use App\Models\User;
use Tests\RequisitionTestCase;

// Incoming Requests: 25 baris per halaman dengan Prev/Next, tapi badge & deteksi request baru memakai SEMUA yang pending.
class PendingApprovalsPaginationTest extends RequisitionTestCase
{

    public function test_incoming_requests_are_paginated_by_25_but_counts_cover_all(): void
    {
        $admin = $this->makeUser('admin');
        ITRequest::factory()->count(27)->create(['type' => 'ITR', 'status' => 'Pending', 'sequence_number' => null]);

        $page1 = $this->actingAs($admin)->getJson('/requisition/requests/pending-approvals');
        $page1->assertOk()->assertJsonPath('count', 27)->assertJsonPath('last_page', 2);
        $this->assertSame(25, substr_count($page1->json('rows_html'), 'data-request-id='));
        $this->assertCount(27, $page1->json('items'));
        $this->assertStringContainsString('Hal. 1 / 2', $page1->json('pagination_html'));

        $page2 = $this->actingAs($admin)->getJson('/requisition/requests/pending-approvals?pending_page=2');
        $this->assertSame(2, substr_count($page2->json('rows_html'), 'data-request-id='));
        $this->assertStringContainsString('Hal. 2 / 2', $page2->json('pagination_html'));
    }

    public function test_dashboard_renders_both_panel_buttons(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->get('/requisition')
            ->assertOk()
            ->assertSee('id="tabIncoming"', false)
            ->assertSee('id="tabAll"', false)
            ->assertSee('id="pendingApprovalsPagination"', false);
    }
}
