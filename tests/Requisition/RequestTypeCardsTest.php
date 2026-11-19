<?php

namespace Tests\Requisition;

use Tests\RequisitionTestCase;

/** Kartu "Pilih Jenis Request" (desain baru) menggantikan kartu lama; Dashboard aplikasi tidak memuat kartu ini. */
class RequestTypeCardsTest extends RequisitionTestCase
{
    public function test_new_cards_replace_the_old_grid_and_open_each_form(): void
    {
        $html = $this->actingAs($this->makeUser('admin'))->get('/requisition/requests/create')->assertOk()->getContent();
        $this->assertStringContainsString('class="rq-grid"', $html);
        $this->assertStringNotContainsString('request-type-grid', $html);                    // kartu gelap lama sudah diganti
        foreach (['TICKET_SISTEM', 'ITR', 'BAK', 'SURAT_JALAN', 'WO', 'PEMINJAMAN_ASET'] as $t) {
            $this->assertStringContainsString("onclick=\"openModalForm('{$t}')\"", $html, $t);
            $this->assertStringContainsString('id="form' . $t . '"', $html, "form {$t} tetap ada");
        }
    }

    public function test_role_user_does_not_get_the_surat_jalan_card(): void
    {
        $html = $this->actingAs($this->makeUser('user'))->get('/requisition/requests/create')->assertOk()->getContent();
        $this->assertStringNotContainsString("openModalForm('SURAT_JALAN')", $html);
        $this->assertSame(5, substr_count($html, 'class="rq-card"'));
    }

    public function test_filament_dashboard_has_no_request_cards(): void
    {
        $this->actingAs($this->makeUser('admin'))->get('/admin')->assertOk()->assertDontSee('Pilih Jenis Request');
    }
}
