<?php

namespace Tests\Requisition;

use App\Models\ITRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\RequisitionTestCase;

// Upload dokumen dari List All Form: semua tipe kecuali Ticket Sistem, khusus tim IT.
class DocumentUploadTest extends RequisitionTestCase
{

    private function admin(): User
    {
        return $this->makeUser('admin');
    }

    public function test_admin_can_upload_and_view_a_document(): void
    {
        Storage::fake('local');
        $bak = ITRequest::factory()->bak()->create();

        $this->actingAs($this->admin())
            ->post("/requisition/requests/{$bak->id}/document", ['document' => UploadedFile::fake()->create('berita-acara.pdf', 100, 'application/pdf')])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $bak->refresh();
        $this->assertSame('berita-acara.pdf', $bak->document_name);
        Storage::disk('local')->assertExists($bak->document_path);

        $this->actingAs($this->admin())->get("/requisition/requests/{$bak->id}/document")->assertOk();
    }

    public function test_replacing_a_document_deletes_the_old_file(): void
    {
        Storage::fake('local');
        $bak = ITRequest::factory()->bak()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->post("/requisition/requests/{$bak->id}/document", ['document' => UploadedFile::fake()->create('lama.pdf', 50, 'application/pdf')]);
        $oldPath = $bak->fresh()->document_path;

        $this->actingAs($admin)->post("/requisition/requests/{$bak->id}/document", ['document' => UploadedFile::fake()->create('baru.pdf', 50, 'application/pdf')]);

        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($bak->fresh()->document_path);
    }

    public function test_ticket_sistem_cannot_receive_a_document(): void
    {
        Storage::fake('local');
        $ticket = ITRequest::factory()->create(['type' => 'TICKET_SISTEM']);

        $this->actingAs($this->admin())
            ->post("/requisition/requests/{$ticket->id}/document", ['document' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->assertSessionHas('error');

        $this->assertNull($ticket->fresh()->document_path);
    }

    public function test_disallowed_file_type_is_rejected(): void
    {
        Storage::fake('local');
        $bak = ITRequest::factory()->bak()->create();

        $this->actingAs($this->admin())
            ->post("/requisition/requests/{$bak->id}/document", ['document' => UploadedFile::fake()->create('virus.exe', 10)])
            ->assertSessionHasErrors('document');
    }

    public function test_regular_user_cannot_upload_or_view_documents(): void
    {
        Storage::fake('local');
        $bak = ITRequest::factory()->bak()->create();
        $user = $this->makeUser('user');

        $this->actingAs($user)
            ->post("/requisition/requests/{$bak->id}/document", ['document' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])
            ->assertForbidden();
        $this->actingAs($user)->get("/requisition/requests/{$bak->id}/document")->assertForbidden();
    }
}
