<?php

namespace Tests\Requisition;

use App\Filament\Resources\Accessories\Pages\ManageAccessories;
use App\Filament\Resources\AccessoryHandovers\AccessoryHandoverResource;
use App\Filament\Resources\AccessoryHandovers\Pages\CreateAccessoryHandover;
use App\Filament\Resources\AccessoryHandovers\Pages\ListAccessoryHandovers;
use App\Models\Accessory;
use App\Models\AccessoryHandover;
use App\Models\Employee;
use App\Models\ITRequest;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AccessoryHandoverService;
use App\Support\AccessoryBastDocument;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\RequisitionTestCase;

/** Serah terima accessories (mis. mouse) antara IT dan karyawan dengan BAST, termasuk dari request ITR System Requisition. */
class AccessoryHandoverTest extends RequisitionTestCase
{
    /** Data demo punya BAST dari rencana kedatangan; tes penomoran / hitungan di sini mulai dari tabel BAST kosong (dalam transaksi tes, dibatalkan otomatis). */
    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\AccessoryHandoverItem::query()->delete();
        \App\Models\AccessoryHandoverFile::query()->delete();
        AccessoryHandover::query()->delete();
    }

    private function svc(): AccessoryHandoverService { return app(AccessoryHandoverService::class); }

    private function acc(string $name = 'Mouse QA', int $qty = 10): Accessory
    {
        return Accessory::create(['name' => $name, 'qty_total' => $qty, 'min_qty' => 0]);
    }

    /** Karyawan yang tertaut ke akun login (peminta request). */
    private function requester(string $name = 'Budi Peminta'): array
    {
        $u = $this->makeUser('user', ['name' => $name]);
        $e = $this->linkEmployee($u, ['name' => $name, 'email' => $u->email, 'nik' => 'REQ' . $u->id, 'status' => 'active']);
        return [$u, $e];
    }

    private function itr(User $u, array $over = []): ITRequest
    {
        return ITRequest::factory()->create($over + ['type' => 'ITR', 'user_id' => $u->id, 'status' => 'Approved', 'sequence_number' => random_int(1, 90000), 'numbered_at' => now(),
            'title' => 'Mouse baru', 'items' => [['name' => 'Mouse QA', 'qty' => 2]]]);
    }

    public function test_checkout_creates_a_numbered_bast_moves_stock_and_merges_duplicate_lines(): void
    {
        $this->loginAs($this->itStaff());
        [, $emp] = $this->requester();
        $mouse = $this->acc('Mouse QA', 10); $kb = $this->acc('Keyboard QA', 5);

        $h = $this->svc()->checkout($emp, [['accessory_id' => $mouse->id, 'qty' => 1], ['accessory_id' => $kb->id, 'qty' => 2], ['accessory_id' => $mouse->id, 'qty' => 2], ['accessory_id' => 0, 'qty' => 9]]);
        $this->assertMatchesRegularExpression('#^001/IT/BSA/AKP/(I|II|III|IV|V|VI|VII|VIII|IX|X|XI|XII)/' . now()->format('Y') . '$#', $h->bast_number);
        $this->assertSame('checkout', $h->type);
        $this->assertSame($this->itStaff()->id, $h->created_by);
        $this->assertCount(2, $h->items);                                                         // mouse digabung: 1 + 2 = 3
        $this->assertSame(3, $h->items->firstWhere('accessory_id', $mouse->id)->qty);
        $this->assertSame(7, $mouse->fresh()->qty_available);
        $this->assertSame(3, $kb->fresh()->qty_available);
        $this->assertSame(2, StockMovement::where('employee_id', $emp->id)->where('type', 'out')->count());
        $this->assertSame('Mouse QA × 3, Keyboard QA × 2', $h->itemsSummary());
        $this->assertSame(['Mouse QA' => 3, 'Keyboard QA' => 2], $emp->heldAccessories()->mapWithKeys(fn ($x) => [$x['accessory']->name => $x['qty']])->all());

        $h2 = $this->svc()->checkout($emp, [['accessory_id' => $mouse->id, 'qty' => 1]]);
        $this->assertStringStartsWith('002/IT/BSA/AKP/', $h2->bast_number);                       // nomor berurutan per kode per tahun
    }

    public function test_failures_leave_no_trace_and_do_not_consume_a_bast_number(): void
    {
        $this->loginAs($this->itStaff());
        [, $emp] = $this->requester();
        $mouse = $this->acc('Mouse QA', 3); $kb = $this->acc('Keyboard QA', 1);
        $before = [AccessoryHandover::count(), StockMovement::count()];

        // barang kedua melebihi stok -> seluruh serah terima dibatalkan (barang pertama tidak ikut keluar)
        try { $this->svc()->checkout($emp, [['accessory_id' => $mouse->id, 'qty' => 2], ['accessory_id' => $kb->id, 'qty' => 5]]); $this->fail('harus gagal'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('melebihi stok', $e->getMessage()); }
        $this->assertSame($before, [AccessoryHandover::count(), StockMovement::count()]);
        $this->assertSame(3, $mouse->fresh()->qty_available);

        foreach ([[[], 'minimal satu'], [[['accessory_id' => $mouse->id, 'qty' => 0]], 'minimal 1'], [[['accessory_id' => 999999, 'qty' => 1]], 'tidak ditemukan']] as [$lines, $msg]) {
            try { $this->svc()->checkout($emp, $lines); $this->fail('harus gagal'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString($msg, $e->getMessage()); }
        }
        $resigned = Employee::create(['name' => 'Sudah Resign', 'email' => 'rs@qa.test', 'status' => 'resigned']);
        try { $this->svc()->checkout($resigned, [['accessory_id' => $mouse->id, 'qty' => 1]]); $this->fail('harus gagal'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('resigned', $e->getMessage()); }

        $ok = $this->svc()->checkout($emp, [['accessory_id' => $mouse->id, 'qty' => 1]]);
        $this->assertStringStartsWith('001/IT/BSA/AKP/', $ok->bast_number);                       // gagal sebelumnya tidak memakai nomor
    }

    public function test_return_bast_requires_the_employee_to_hold_the_items_and_restores_stock(): void
    {
        $this->loginAs($this->itStaff());
        [, $emp] = $this->requester();
        $mouse = $this->acc('Mouse QA', 5);
        try { $this->svc()->receiveBack($emp, [['accessory_id' => $mouse->id, 'qty' => 1, 'condition' => 'good']]); $this->fail('harus gagal'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('hanya memegang 0', $e->getMessage()); }

        $this->svc()->checkout($emp, [['accessory_id' => $mouse->id, 'qty' => 3]]);
        try { $this->svc()->receiveBack($emp, [['accessory_id' => $mouse->id, 'qty' => 4, 'condition' => 'good']]); $this->fail('harus gagal'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('hanya memegang 3', $e->getMessage()); }

        $r = $this->svc()->receiveBack($emp, [['accessory_id' => $mouse->id, 'qty' => 2, 'condition' => 'minor_damage']], 'mouse retak');
        $this->assertSame('return', $r->type);
        $this->assertSame('minor_damage', $r->items->first()->condition);
        $this->assertSame(4, $mouse->fresh()->qty_available);
        $this->assertSame(1, $emp->heldAccessories()->first()['qty']);
        $this->assertStringContainsString('PENGEMBALIAN', AccessoryBastDocument::build($r)['title']);
    }

    public function test_handover_can_be_linked_only_to_an_open_itr_of_the_same_person(): void
    {
        $this->loginAs($this->itStaff());
        [$u, $emp] = $this->requester();
        $mouse = $this->acc('Mouse QA', 10);
        $line = [['accessory_id' => $mouse->id, 'qty' => 1]];

        foreach (['Approved', 'In Progress'] as $st) {
            $h = $this->svc()->checkout($emp, $line, $this->itr($u, ['status' => $st])->id);
            $this->assertNotNull($h->request_id);
        }
        foreach (['Pending', 'Rejected', 'Completed'] as $st) {
            try { $this->svc()->checkout($emp, $line, $this->itr($u, ['status' => $st])->id); $this->fail("$st harus ditolak"); }
            catch (\InvalidArgumentException $e) { $this->assertStringContainsString("berstatus $st", $e->getMessage()); }
        }
        $notItr = ITRequest::factory()->create(['type' => 'TICKET_SISTEM', 'user_id' => $u->id, 'status' => 'Approved']);
        try { $this->svc()->checkout($emp, $line, $notItr->id); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('hanya bisa dikaitkan dengan request ITR', $e->getMessage()); }

        [, $other] = $this->requester('Orang Lain');
        try { $this->svc()->checkout($other, $line, $this->itr($u)->id); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('sama dengan karyawan yang mengajukan', $e->getMessage()); }
        try { $this->svc()->checkout($emp, $line, 999999); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('tidak ditemukan', $e->getMessage()); }
        $this->assertSame(2, AccessoryHandover::where('employee_id', $emp->id)->whereNotNull('request_id')->count());      // hanya dua yang berhasil terkait request
    }

    public function test_bast_pdf_content_and_access_rules(): void
    {
        $this->loginAs($this->itStaff());
        [$u, $emp] = $this->requester('Siti Penerima');
        $mouse = $this->acc('Mouse QA', 5);
        $req = $this->itr($u);
        $h = $this->svc()->checkout($emp, [['accessory_id' => $mouse->id, 'qty' => 2]], $req->id, 'Untuk kerja remote');

        $d = AccessoryBastDocument::build($h);
        $this->assertSame('SITI PENERIMA', strtoupper($d['receiver']['name']));
        $this->assertSame('BUKTI SERAH TERIMA ACCESSORIES', $d['title']);
        $this->assertSame($req->ticket_number, $d['ticket']);
        $this->assertSame([['kind' => 'Accessories', 'name' => 'Mouse QA', 'code' => $mouse->code, 'category' => '-', 'qty' => '2', 'remark' => '-']], $d['rows']);

        // IT (staff) dan pemilik BAST bisa membuka; orang lain & tamu tidak
        $pdf = $this->loginAs($this->itStaff())->get(route('accessory-bast.view', $h))->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString('attachment', $this->loginAs($this->itStaff())->get(route('accessory-bast.download', $h))->assertOk()->headers->get('content-disposition'));
        $this->loginAs($u)->get(route('accessory-bast.view', $h))->assertOk();
        $this->loginAs($this->itMgmt())->get(route('accessory-bast.view', $h))->assertOk();      // izin lihat inventaris
        $this->loginAs($this->makeUser('user'))->get(route('accessory-bast.view', $h))->assertForbidden();
        auth()->forgetGuards(); $this->flushSession();
        $this->get(route('accessory-bast.view', $h))->assertRedirect(url('/admin/login'));
    }

    public function test_signed_scans_are_archived_and_validated(): void
    {
        Storage::fake('local');
        $this->loginAs($this->itStaff());
        [$u, $emp] = $this->requester();
        $h = $this->svc()->checkout($emp, [['accessory_id' => $this->acc()->id, 'qty' => 1]]);
        $put = fn (string $name, string $content) => tap('accessory-bast-signed/' . $name, fn ($p) => Storage::disk('local')->put($p, $content));

        $pdf = $this->svc()->attachSigned($h, $put('a.pdf', "%PDF-1.4\n"), 'Scan BAST Budi.pdf', 'lengkap');
        $this->svc()->attachSigned($h, $put('b.jpg', "\xFF\xD8\xFF\xE0 jpeg"), 'foto.jpg');
        $this->svc()->attachSigned($h, $put('c.png', "\x89PNG\r\n\x1a\n"), null);
        $this->assertSame(3, $h->files()->count());                                                // tiap unggahan arsip baru, tidak menimpa
        $this->assertSame('Scan BAST Budi.pdf', $pdf->original_name);

        foreach ([['x.pdf', 'bukan pdf'], ] as [$n, $c]) {
            $path = $put($n, $c);
            try { $this->svc()->attachSigned($h, $path, $n); $this->fail('harus ditolak'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('bukan PDF', $e->getMessage()); }
            Storage::disk('local')->assertMissing($path);                                          // file palsu dibuang
        }
        foreach (['../etc/passwd', 'lain/a.pdf', 'accessory-bast-signed/../a.pdf', 'accessory-bast-signed/tidak-ada.pdf'] as $bad) {
            try { $this->svc()->attachSigned($h, $bad); $this->fail("$bad harus ditolak"); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('tidak ditemukan', $e->getMessage()); }
        }

        // lihat/unduh: staff & pemilik boleh, orang lain tidak
        $this->loginAs($this->itStaff())->get(route('accessory-bast.file', $pdf))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->loginAs($u)->get(route('accessory-bast.file.download', $pdf))->assertOk();
        $this->loginAs($this->itStaff())->get(route('accessory-bast.file', $h->files()->where('path', 'like', '%b.jpg')->first()))->assertHeader('content-type', 'image/jpeg');
        $this->loginAs($this->makeUser('user'))->get(route('accessory-bast.file', $pdf))->assertForbidden();
    }

    public function test_filament_create_page_makes_the_bast_and_reports_problems(): void
    {
        $this->loginAs($this->itStaff());
        [, $emp] = $this->requester();
        $mouse = $this->acc('Mouse QA', 4);

        Livewire::test(CreateAccessoryHandover::class)->fillForm(['type' => 'checkout', 'employee_id' => $emp->id, 'lines' => [['item' => 'accessory:' . $mouse->id, 'qty' => 2]], 'notes' => 'uji'])
            ->call('create')->assertHasNoFormErrors()->assertNotified();
        $h = AccessoryHandover::latest('id')->first();
        $this->assertSame($emp->id, $h->employee_id);
        $this->assertSame(2, $mouse->fresh()->qty_available);

        // melebihi stok: notifikasi merah, tidak ada BAST baru
        $n = AccessoryHandover::count();
        Livewire::test(CreateAccessoryHandover::class)->fillForm(['type' => 'checkout', 'employee_id' => $emp->id, 'lines' => [['item' => 'accessory:' . $mouse->id, 'qty' => 99]]])
            ->call('create')->assertNotifiedFailure();
        $this->assertSame($n, AccessoryHandover::count());

        // pengembalian
        Livewire::test(CreateAccessoryHandover::class)->fillForm(['type' => 'return', 'employee_id' => $emp->id, 'lines' => [['item' => 'accessory:' . $mouse->id, 'qty' => 1, 'condition' => 'good']]])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame('return', AccessoryHandover::latest('id')->first()->type);

        // daftar: tampil, ada tombol cetak
        Livewire::test(ListAccessoryHandovers::class)->assertCanSeeTableRecords(AccessoryHandover::all())->assertSee($h->bast_number);

        // Manajemen hanya melihat; tidak boleh membuat
        $this->loginAs($this->itMgmt())->get(AccessoryHandoverResource::getUrl('index'))->assertOk();
        $this->loginAs($this->itMgmt())->get(AccessoryHandoverResource::getUrl('create'))->assertForbidden();
    }

    public function test_create_page_opened_from_an_itr_is_prefilled_from_the_request(): void
    {
        $this->loginAs($this->itStaff());
        [$u, $emp] = $this->requester();
        $mouse = $this->acc('Mouse QA', 10);
        $req = $this->itr($u, ['items' => [['name' => 'Mouse QA', 'qty' => 2], ['name' => 'Barang Tidak Ada XYZ', 'qty' => 1]]]);

        Livewire::withQueryParams(['request' => $req->id])->test(CreateAccessoryHandover::class)
            ->assertFormSet(['type' => 'checkout', 'employee_id' => $emp->id, 'request_id' => $req->id])
            ->assertSet('data.lines', fn ($lines) => collect($lines)->contains(fn ($l) => ($l['item'] ?? null) === 'accessory:' . $mouse->id && ($l['qty'] ?? null) == 2))
            ->assertNotified('Barang belum cocok dengan daftar Accessories / Consumable / Komponen');                      // barang yang tidak ketemu diberi tahu

        // dari request: BAST tersimpan terkait request itu
        Livewire::withQueryParams(['request' => $req->id])->test(CreateAccessoryHandover::class)->call('create')->assertHasNoFormErrors();
        $this->assertSame($req->id, AccessoryHandover::latest('id')->first()->request_id);
    }

    public function test_accessories_menu_actions_now_produce_bast_documents(): void
    {
        $this->loginAs($this->itStaff());
        [, $emp] = $this->requester();
        $mouse = $this->acc('Mouse QA', 5);

        Livewire::test(ManageAccessories::class)->callAction(TestAction::make('checkout')->table($mouse), ['employee_id' => $emp->id, 'qty' => 2])->assertHasNoActionErrors()->assertNotified();
        $h = AccessoryHandover::latest('id')->first();
        $this->assertSame('checkout', $h->type);
        $this->assertSame(3, $mouse->fresh()->qty_available);

        Livewire::test(ManageAccessories::class)->callAction(TestAction::make('checkin')->table($mouse->fresh()), ['employee_id' => $emp->id, 'qty' => 1, 'condition' => 'good'])->assertHasNoActionErrors()->assertNotified();
        $this->assertSame('return', AccessoryHandover::latest('id')->first()->type);
        $this->assertSame(2, AccessoryHandover::count());

        // lebih dari yang dipegang -> ditolak, tidak ada BAST
        Livewire::test(ManageAccessories::class)->callAction(TestAction::make('checkin')->table($mouse->fresh()), ['employee_id' => $emp->id, 'qty' => 5, 'condition' => 'good'])->assertNotifiedFailure();
        $this->assertSame(2, AccessoryHandover::count());
    }

    public function test_system_requisition_shows_the_handover_button_and_bast_links_on_approved_itr_rows(): void
    {
        $this->loginAs($this->itStaff());
        [$u, $emp] = $this->requester();
        $mouse = $this->acc('Mouse QA', 10);
        $approved = $this->itr($u, ['status' => 'In Progress', 'title' => 'Mouse baru QA']);
        $completed = $this->itr($u, ['status' => 'Completed', 'title' => 'Sudah Selesai QA']);
        $ticket = ITRequest::factory()->create(['type' => 'TICKET_SISTEM', 'user_id' => $u->id, 'status' => 'Approved', 'sequence_number' => 4321, 'numbered_at' => now(), 'title' => 'Tiket QA']);
        $create = \App\Filament\Pages\ProcessRequest::urlFor($approved);

        $rows = fn () => $this->loginAs($this->itStaff())->getJson('/requisition/requests/list-all-form')->assertOk()->json('rows_html');
        $html = $rows();
        $this->assertStringContainsString($create, $html);                                          // tombol "Proses IT" (cocokkan data & BAST) untuk tim IT
        $this->assertStringContainsString(\App\Filament\Pages\ProcessRequest::urlFor($ticket), $html);   // semua jenis request bisa dicocokkan
        $this->assertStringNotContainsString('BAST:', $html);

        $h = $this->svc()->checkout($emp, [['accessory_id' => $mouse->id, 'qty' => 1]], $approved->id);
        $html = $rows();
        $this->assertStringContainsString('BAST:', $html);
        $this->assertStringContainsString(route('accessory-bast.view', $h), $html);
        $this->assertStringContainsString($h->bast_number, $html);
    }

    public function test_consumable_and_component_go_on_the_same_bast_and_show_in_employee_history(): void
    {
        $this->loginAs($this->itStaff());
        [, $emp] = $this->requester();
        $mouse = $this->acc('Mouse QA', 10);
        $toner = \App\Models\Consumable::create(['name' => 'Tinta QA', 'unit' => 'pcs', 'qty' => 5, 'min_qty' => 0]);
        $ram = \App\Models\Component::create(['name' => 'RAM 8GB QA', 'qty_total' => 4, 'min_qty' => 0, 'serial_number' => 'RAM-QA-1']);

        $h = $this->svc()->checkout($emp, [['item' => 'accessory:' . $mouse->id, 'qty' => 1], ['item' => 'consumable:' . $toner->id, 'qty' => 2], ['item' => 'component:' . $ram->id, 'qty' => 1]]);
        $this->assertCount(3, $h->items);
        $this->assertSame(9, $mouse->fresh()->qty_available);
        $this->assertEquals(3, $toner->fresh()->qty);
        $this->assertSame(3, $ram->fresh()->qty_available);
        $this->assertSame(3, $ram->fresh()->qty_total);
        $this->assertSame('Mouse QA × 1, Tinta QA × 2, RAM 8GB QA × 1', $h->itemsSummary());
        $this->assertSame(['Accessories', 'Consumable', 'Komponen'], $h->items->map->kindLabel()->all());

        $d = AccessoryBastDocument::build($h);
        $this->assertSame(['Accessories', 'Consumable', 'Komponen'], array_column($d['rows'], 'kind'));
        $this->assertSame('SN RAM-QA-1', $d['rows'][2]['remark']);
        $this->assertSame(['2', 'Satuan: pcs'], [$d['rows'][1]['qty'], $d['rows'][1]['remark']]);
        $this->assertSame(4, $d['total']);
        $this->assertSame('BUKTI SERAH TERIMA BARANG PENDUKUNG', $d['title']);
        $this->assertNotEmpty(AccessoryBastDocument::pdf($h)->output());

        // hanya accessories yang bisa dikembalikan; gagal tanpa jejak
        $before = AccessoryHandover::count();
        try { $this->svc()->receiveBack($emp, [['item' => 'consumable:' . $toner->id, 'qty' => 1]]); $this->fail('harus gagal'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('hanya untuk accessories', $e->getMessage()); }
        try { $this->svc()->checkout($emp, [['item' => 'consumable:' . $toner->id, 'qty' => 99]]); $this->fail('harus gagal'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('melebihi', $e->getMessage()); }
        try { $this->svc()->checkout($emp, [['item' => 'accessory:' . $mouse->id, 'qty' => 1.5]]); $this->fail('harus gagal'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('bilangan bulat', $e->getMessage()); }
        try { $this->svc()->checkout($emp, [['item' => 'consumable:' . $toner->id, 'qty' => 0]]); $this->fail('harus gagal'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('minimal', $e->getMessage()); }
        $this->assertSame($before, AccessoryHandover::count());
        $this->assertEquals(3, $toner->fresh()->qty);

        // riwayat per karyawan + arsip TTD masing-masing BAST
        $h2 = $this->svc()->checkout($emp, [['item' => 'accessory:' . $mouse->id, 'qty' => 1]]);
        $this->assertSame([$h2->id, $h->id], $emp->accessoryHandovers()->pluck('id')->all());
        $html = view('filament.employee-items', ['employee' => $emp->fresh()])->render();
        foreach ([$h->bast_number, $h2->bast_number, 'Tinta QA', 'Belum TTD'] as $needle) $this->assertStringContainsString($needle, $html);
    }

    public function test_document_format_follows_the_sample_per_item_kind(): void
    {
        $this->loginAs($this->itStaff());
        [, $emp] = $this->requester('Hendrik Sumantri');
        $mouse = $this->acc('Mouse QA', 10);
        $toner = \App\Models\Consumable::create(['name' => 'BATTERY RADIO QA', 'unit' => 'pcs', 'qty' => 5, 'min_qty' => 0]);
        $ram = \App\Models\Component::create(['name' => 'RAM QA', 'qty_total' => 4, 'min_qty' => 0]);
        $roman = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][(int) now()->format('n') - 1];
        $tail = "/AKP/$roman/" . now()->format('Y');

        $c = $this->svc()->checkout($emp, [['item' => 'consumable:' . $toner->id, 'qty' => 1]]);
        $a = $this->svc()->checkout($emp, [['item' => 'accessory:' . $mouse->id, 'qty' => 1]]);
        $k = $this->svc()->checkout($emp, [['item' => 'component:' . $ram->id, 'qty' => 1]]);
        $m = $this->svc()->checkout($emp, [['item' => 'accessory:' . $mouse->id, 'qty' => 1], ['item' => 'component:' . $ram->id, 'qty' => 1]]);
        $r = $this->svc()->receiveBack($emp, [['item' => 'accessory:' . $mouse->id, 'qty' => 1, 'condition' => 'good']]);
        // urutan per kode jenis (tiap jenis mulai 001), format urut/IT/KODE/AKP/bulan-romawi/tahun
        $this->assertSame(['001/IT/BPB' . $tail, '001/IT/BSA' . $tail, '001/IT/BSK' . $tail, '001/IT/BSP' . $tail, '001/IT/BPA' . $tail], collect([$c, $a, $k, $m, $r])->map(fn ($x) => $x->bast_number)->all());
        $this->assertSame('002/IT/BPB' . $tail, $this->svc()->checkout($emp, [['item' => 'consumable:' . $toner->id, 'qty' => 1]])->bast_number);

        $titles = [[$c, 'BUKTI PENGELUARAN BARANG CONSUMABLE'], [$a, 'BUKTI SERAH TERIMA ACCESSORIES'], [$k, 'BUKTI SERAH TERIMA KOMPONEN'], [$m, 'BUKTI SERAH TERIMA BARANG PENDUKUNG'], [$r, 'BUKTI PENGEMBALIAN ACCESSORIES']];
        foreach ($titles as [$h, $title]) {
            $d = AccessoryBastDocument::build($h->fresh());
            $html = view('pdf.accessory-bast', ['d' => $d])->render();
            $this->assertSame($title, $d['title']);
            foreach ([$title, 'No. Dokumen', 'Referensi', 'DATA PENYERAH', 'DATA PENERIMA', 'DETAIL BARANG', 'Item No.', 'Kategori', 'TOTAL', 'TANDA TANGAN', 'Mengetahui', 'dikendalikan oleh IT PT. Adhi Kartiko Pratama Tbk.'] as $needle) {
                $this->assertStringContainsString($needle, $html, "$title: $needle");
            }
            $this->assertStringNotContainsString('Superintendent', $html);
            $this->assertStringNotContainsString('Revisi', $html);
            $this->assertNotEmpty(AccessoryBastDocument::pdf($h)->output());
        }
        $this->assertStringContainsString('keperluan pekerjaan', AccessoryBastDocument::build($c)['statement']);
        $this->assertStringContainsString('mengembalikannya', AccessoryBastDocument::build($a)['statement']);
        $this->assertStringContainsString('Komponen di atas', AccessoryBastDocument::build($k)['statement']);
        $this->assertStringContainsString('dikembalikan', AccessoryBastDocument::build($r)['statement']);
        $this->assertSame(['Dikembalikan', 'Diterima (IT)', 'Mengetahui'], AccessoryBastDocument::build($r)['sign']);
        $this->assertSame(['Diserahkan (IT)', 'Diterima', 'Mengetahui'], AccessoryBastDocument::build($c)['sign']);
        $this->assertNull(AccessoryBastDocument::build($c)['giver']['dept']);                    // sisi IT tanpa department, sesuai contoh
        $this->assertSame('M. RASYID RIDHO', AccessoryBastDocument::build($c)['known']);

        // nama "Mengetahui" bisa diubah / dikosongkan di Pengaturan
        \App\Models\Setting::put('bast_acc_known', 'BUDI BARU');
        $this->assertSame('BUDI BARU', AccessoryBastDocument::build($c)['known']);
        \App\Models\Setting::put('bast_acc_known', '');
        $this->assertSame('', AccessoryBastDocument::build($c)['known']);
    }
}
