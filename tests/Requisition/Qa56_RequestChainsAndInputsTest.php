<?php

namespace Tests\Requisition;

use App\Filament\Pages\MyItems;
use App\Models\Accessory;
use App\Models\Asset;
use App\Models\Employee;
use App\Models\ITRequest;
use App\Models\RequestItemLink;
use App\Services\AccessoryHandoverService;
use App\Services\RequestProcessingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\RequisitionTestCase;

/** QA lintas modul: alur request ITERA sampai ke data IT Asset (stok, aset, BAST, Barang Saya) dan ketahanan input form. */
class Qa56_RequestChainsAndInputsTest extends RequisitionTestCase
{
    private function person(string $role = 'user'): array
    {
        $u = $this->makeUser($role, ['name' => 'Pemohon QA56']);
        $e = $this->linkEmployee($u, ['name' => 'Pemohon QA56', 'email' => $u->email, 'nik' => '56' . random_int(10000, 99999), 'status' => 'active']);
        return [$u, $e];
    }

    private function base(Employee $e, array $o = []): array
    {
        return $o + ['type' => 'ITR', 'employee_id' => $e->id, 'employee_name' => $e->name, 'employee_nik' => $e->nik, 'position' => 'Staff', 'title' => 'Judul QA56', 'description' => 'Uraian QA56'];
    }

    public function test_itr_to_handover_to_return_keeps_stock_and_documents_consistent(): void
    {
        [$user, $emp] = $this->person();
        $acc = Accessory::create(['name' => 'Mouse QA56', 'qty_total' => 5, 'min_qty' => 0]);

        $this->actingAs($user)->postJson('/requisition/requests', $this->base($emp, ['items' => [['name' => 'Mouse QA56', 'qty' => 2, 'ref' => "accessory:{$acc->id}"]]]))->assertOk();
        $req = ITRequest::where('title', 'Judul QA56')->firstOrFail();
        $this->assertSame($emp->id, $req->employee_id);
        $this->assertSame('Pending', $req->status);
        $this->assertSame(1, RequestItemLink::where('request_id', $req->id)->where('kind', 'accessory')->where('item_id', $acc->id)->count(), 'barang saran tertaut ke request');

        $admin = $this->makeUser('super_admin');
        $this->loginAs($admin)->patchJson("/requisition/requests/{$req->id}/status", ['status' => 'Approved'])->assertOk();
        $req->refresh();
        $this->assertMatchesRegularExpression('/^AKP-ITR\d{4}\d{3}$/', (string) $req->ticket_number);

        $svc = app(AccessoryHandoverService::class);
        $h = $svc->checkout($emp, [['item' => "accessory:{$acc->id}", 'qty' => 2]], $req->id, 'QA56');
        $this->assertSame(3, (int) $acc->fresh()->qty_available);
        $this->assertSame($req->id, (int) $h->request_id);
        $this->assertNotEmpty($h->bast_number);

        $svc->receiveBack($emp, [['item' => "accessory:{$acc->id}", 'qty' => 2]], 'Kembali QA56');
        $this->assertSame(5, (int) $acc->fresh()->qty_available, 'stok pulih setelah pengembalian');

        Artisan::call('inventory:audit');
        $this->assertStringNotContainsString('Mouse QA56', Artisan::output(), 'audit tidak menemukan selisih untuk barang ini');
    }

    public function test_peminjaman_request_borrows_the_asset_shows_in_my_items_and_is_released_on_return(): void
    {
        [$user, $emp] = $this->person();
        $asset = Asset::where('status', 'available')->whereNull('employee_id')->orderBy('id')->firstOrFail();

        $this->actingAs($user)->postJson('/requisition/requests', $this->base($emp, [
            'type' => 'PEMINJAMAN_ASET', 'title' => 'Pinjam QA56', 'loan_start_date' => now()->toDateString(), 'loan_end_date' => now()->addDays(3)->toDateString(),
            'items' => [['name' => 'Laptop presentasi', 'qty' => 1]],
        ]))->assertOk();
        $req = ITRequest::where('title', 'Pinjam QA56')->firstOrFail();

        $admin = $this->makeUser('super_admin');
        $this->loginAs($admin)->patchJson("/requisition/requests/{$req->id}/status", ['status' => 'Approved'])->assertOk();
        RequestItemLink::create(['request_id' => $req->id, 'kind' => 'asset', 'item_id' => $asset->id, 'qty' => 1]);

        $this->assertSame(1, app(RequestProcessingService::class)->borrow($req->fresh()));
        $asset->refresh();
        $this->assertSame('borrowed', $asset->status);
        $this->assertSame($emp->id, (int) $asset->employee_id);
        $a = $asset->assignments()->first();
        $this->assertMatchesRegularExpression('#^IT/BAST/\d{4}/\d{2}/\d{4}$#', $a->bast_number);

        $this->loginAs($user);
        Livewire::test(MyItems::class)->assertCanSeeTableRecords([$asset]);

        $this->loginAs($admin)->patchJson("/requisition/peminjaman/{$req->id}/returned")->assertOk();
        $asset->refresh();
        $this->assertSame('available', $asset->status);
        $this->assertNull($asset->employee_id);
        $this->loginAs($user);
        Livewire::test(MyItems::class)->assertCanNotSeeTableRecords([$asset]);
    }

    public function test_every_form_type_accepts_valid_input_and_links_to_the_employee(): void
    {
        [$user, $emp] = $this->person();
        $img = fn () => UploadedFile::fake()->image('bukti.jpg', 100, 100);
        $cases = [
            'TICKET_SISTEM' => ['category' => 'Hardware', 'title' => 'TS QA56'],
            'ITR' => ['items' => [['name' => 'Keyboard', 'qty' => 1]], 'title' => 'ITR QA56'],
            'BAK' => ['attachment' => $img(), 'title' => 'BAK QA56'],
            'WO' => ['items' => [['name' => 'Ganti kabel', 'qty' => 1]], 'title' => 'WO QA56'],
            'PEMINJAMAN_ASET' => ['loan_start_date' => now()->toDateString(), 'loan_end_date' => now()->addDay()->toDateString(), 'title' => 'PJ QA56'],
        ];
        foreach ($cases as $type => $extra) {
            $this->actingAs($user)->post('/requisition/requests', $this->base($emp, ['type' => $type] + $extra))->assertSessionHasNoErrors();
            $r = ITRequest::where('title', $extra['title'])->firstOrFail();
            $this->assertSame($emp->id, $r->employee_id, $type);
            $this->assertSame($user->id, $r->user_id, $type);
            $this->assertSame('Pending', $r->status, $type);
        }
        $admin = $this->makeUser('super_admin');
        $this->actingAs($admin)->post('/requisition/requests', $this->base($emp, ['type' => 'SURAT_JALAN', 'title' => 'SJ QA56', 'recipient_name' => 'Penerima', 'attachment' => $img()]))->assertSessionHasNoErrors();
        $this->assertNotNull(ITRequest::where('title', 'SJ QA56')->firstOrFail()->ticket_number, 'Surat Jalan langsung bernomor');
        $this->actingAs($user)->post('/requisition/requests', $this->base($emp, ['type' => 'SURAT_JALAN', 'recipient_name' => 'X', 'attachment' => $img()]))->assertForbidden();   // role User dikunci
    }

    public function test_invalid_inputs_are_rejected_and_hostile_text_is_stored_safely(): void
    {
        [$user, $emp] = $this->person();
        $post = fn (array $o) => $this->actingAs($user)->postJson('/requisition/requests', $this->base($emp, $o));
        $before = ITRequest::count();

        $post(['title' => ''])->assertStatus(422);
        $post(['description' => ''])->assertStatus(422);
        $post(['title' => str_repeat('a', 256)])->assertStatus(422);
        $post(['employee_nik' => 'ABC123', 'employee_id' => 999999])->assertStatus(422);
        $post(['items' => array_fill(0, 6, ['name' => 'x', 'qty' => 1])])->assertStatus(422);            // maksimal 5 barang
        $post(['items' => [['name' => 'x', 'qty' => 0]]])->assertStatus(422);
        $post(['items' => [['name' => 'x', 'qty' => 'banyak']]])->assertStatus(422);
        $post(['items' => [['name' => 'x', 'qty' => 1, 'ref' => 'asset:1']]])->assertStatus(422);        // ref hanya accessory/consumable/component
        $post(['type' => 'NGAWUR'])->assertStatus(422);
        $post(['type' => 'TICKET_SISTEM', 'category' => 'Bukan Kategori'])->assertStatus(422);
        $post(['type' => 'BAK'])->assertStatus(422);                                                        // BAK wajib foto
        $post(['type' => 'BAK', 'attachment' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertStatus(422);
        $post(['type' => 'BAK', 'attachment' => UploadedFile::fake()->image('big.jpg')->size(6000)])->assertStatus(422);
        $post(['type' => 'PEMINJAMAN_ASET', 'loan_start_date' => '2026-12-10', 'loan_end_date' => '2026-12-01'])->assertStatus(422);
        $post(['type' => 'PEMINJAMAN_ASET'])->assertStatus(422);
        $this->assertSame($before, ITRequest::count(), 'input tidak valid tidak menyimpan apa pun');

        $evil = '<script>alert(1)</script>\' OR 1=1; DROP TABLE users;--';
        $post(['title' => 'Aman QA56', 'description' => $evil, 'items' => [['name' => $evil, 'qty' => 1]]])->assertOk();
        $this->assertSame($before + 1, ITRequest::count());
        $this->assertDatabaseHas('users', ['id' => $user->id]);                                              // tabel tetap utuh
        $rows = $this->actingAs($user)->getJson('/requisition/requests/history')->assertOk()->json('rows_html');
        $this->assertStringNotContainsString('<script>alert(1)</script>', $rows, 'teks berbahaya tidak tercetak mentah');
    }

    public function test_users_only_see_and_edit_their_own_requests(): void
    {
        [$u1, $e1] = $this->person();
        [$u2] = $this->person();
        $this->actingAs($u1)->postJson('/requisition/requests', $this->base($e1, ['title' => 'Milik U1 QA56']))->assertOk();
        $r = ITRequest::where('title', 'Milik U1 QA56')->firstOrFail();
        $this->loginAs($u2)->patchJson("/requisition/requests/{$r->id}", ['title' => 'dibajak'])->assertForbidden();
        $this->loginAs($u2)->deleteJson("/requisition/requests/{$r->id}")->assertForbidden();
        $this->assertSame('Milik U1 QA56', $r->fresh()->title);
    }
}
