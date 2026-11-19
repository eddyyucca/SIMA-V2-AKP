<?php

namespace Tests\Qa;

use App\Filament\Resources\ArrivalPlans\ArrivalPlanResource;
use App\Filament\Resources\ArrivalPlans\Pages\CreateArrivalPlan;
use App\Filament\Resources\ArrivalPlans\Pages\EditArrivalPlan;
use App\Filament\Resources\ArrivalPlans\Pages\ListArrivalPlans;
use App\Filament\Resources\ArrivalPlans\Pages\ViewArrivalPlan;
use App\Mail\BastMail;
use App\Models\Accessory;
use App\Models\ArrivalPlan;
use App\Models\ArrivalPlanItem;
use App\Models\Asset;
use App\Models\Employee;
use App\Models\Location;
use App\Reports\ReportCatalog;
use App\Services\ArrivalPlanService;
use App\Services\AssetService;
use App\Services\StockService;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\QaTestCase;

class Qa19_ArrivalPlanTest extends QaTestCase
{
    private ArrivalPlanService $svc;
    private static int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(ArrivalPlanService::class);
    }

    private function plan(array $o = []): ArrivalPlan
    {
        self::$n++;
        return $this->svc->create($o + [
            'name' => 'Calon QA ' . self::$n . ' ' . substr(md5((string) microtime(true)), 0, 4), 'arrival_date' => now()->addDays(5)->toDateString(),
            'location_id' => Location::where('name', 'Cabang Palu')->value('id'), 'department_id' => \App\Models\Department::first()->id, 'position' => 'Staff',
        ]);
    }

    private function free(int $n = 1)
    {
        $a = Asset::where('status', 'available')->whereNull('employee_id')->where('ownership', 'owned')->whereDoesntHave('reservationItem')->orderBy('id')->take($n)->get();
        $this->assertCount($n, $a, 'data demo harus punya aset bebas');
        return $n === 1 ? $a->first() : $a;
    }

    private function acc(): Accessory
    {
        return Accessory::where('name', 'like', 'Webcam%')->firstOrFail();
    }

    private function ready(ArrivalPlan $p): void
    {
        foreach ($p->items()->get() as $it) {
            $this->svc->setItemStatus($it, 'prepared');
            $this->svc->setItemStatus($it->fresh(), 'ready');
        }
    }

    // ---------------------------------------------------------------- membuat rencana
    public function test_staff_creates_a_plan_with_number_pic_and_validation(): void
    {
        $this->as($this->staff());
        $loc = Location::first();
        $ok = ['name' => 'Calon Baru', 'location_id' => $loc->id, 'arrival_date' => now()->addDays(4)->toDateString(), 'department_id' => \App\Models\Department::first()->id, 'position' => 'Operator'];
        foreach ([['name' => ''], ['location_id' => null], ['arrival_date' => null], ['arrival_date' => now()->subDay()->toDateString()], ['email' => 'bukan-email'], ['email' => Employee::first()->email]] as $bad) {
            Livewire::test(CreateArrivalPlan::class)->fillForm($bad + $ok)->call('create')->assertHasFormErrors();
        }
        $this->assertSame(0, ArrivalPlan::where('name', 'Calon Baru')->count(), 'isian salah tidak menyimpan apa pun');

        $t = Livewire::test(CreateArrivalPlan::class)->fillForm($ok + ['email' => 'calon.baru@inventaris.test'])->call('create')->assertHasNoFormErrors();
        $p = ArrivalPlan::where('name', 'Calon Baru')->firstOrFail();
        $this->assertMatchesRegularExpression('#^RK/\d{4}/\d{2}/\d{4}$#', $p->plan_no);
        $this->assertSame('planned', $p->status);
        $this->assertSame($this->staff()->id, $p->pic_id);
        $this->assertSame($this->staff()->id, $p->created_by);
        $t->assertRedirect(ArrivalPlanResource::getUrl('view', ['record' => $p]));
        // email yang sama pada rencana lain yang masih berjalan ditolak
        Livewire::test(CreateArrivalPlan::class)->fillForm(['name' => 'Dobel'] + $ok + ['email' => 'calon.baru@inventaris.test'])->call('create')->assertHasFormErrors(['email']);
        // nomor berurutan
        $p2 = $this->plan();
        $this->assertSame((int) substr($p->plan_no, -4) + 1, (int) substr($p2->plan_no, -4));
    }

    public function test_service_rejects_past_dates_and_duplicate_emails(): void
    {
        $this->as($this->staff());
        foreach ([['arrival_date' => now()->subDay()->toDateString()], ['name' => ' '], ['email' => Employee::first()->email]] as $bad) {
            try { $this->plan($bad); $this->fail('harus ditolak'); } catch (\InvalidArgumentException) { $this->assertTrue(true); }
        }
        $this->plan(['email' => 'dobel@inventaris.test']);
        $this->expectException(\InvalidArgumentException::class);
        $this->plan(['email' => 'DOBEL@inventaris.test']);
    }

    // ---------------------------------------------------------------- pencadangan aset
    public function test_assets_are_reserved_once_and_only_when_available(): void
    {
        $this->as($this->staff());
        [$a, $b] = $this->free(2)->all();
        $p1 = $this->plan();
        $p2 = $this->plan();
        $item = $this->svc->addAsset($p1, $a);
        $this->assertSame(['asset', 'planned'], [$item->type, $item->status]);
        $this->assertSame($p1->id, $a->fresh()->reservationItem->arrival_plan_id);

        foreach ([$p2, $p1] as $target) {          // oleh rencana lain maupun rencana yang sama
            try { $this->svc->addAsset($target, $a); $this->fail('aset sudah dicadangkan'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('dicadangkan', $e->getMessage()); }
        }
        // status tidak tersedia
        $deployed = Asset::where('status', 'deployed')->firstOrFail();
        $repair = Asset::where('status', 'repair')->firstOrFail();
        $lost = Asset::where('status', 'lost')->firstOrFail();
        $disposed = Asset::where('status', 'disposed')->firstOrFail();
        $returned = Asset::where('status', 'returned')->firstOrFail();
        foreach ([$deployed, $repair, $lost, $disposed, $returned] as $bad) {
            try { $this->svc->addAsset($p2, $bad); $this->fail("{$bad->asset_tag} ({$bad->status}) tidak boleh dicadangkan"); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('tidak tersedia', $e->getMessage()); }
        }
        $this->assertSame(0, $p2->items()->count());

        // pilihan di form hanya aset yang masih bebas
        $page = Livewire::test(ViewArrivalPlan::class, ['record' => $p2->getKey()]);
        $opts = $page->instance()->assetOptions();
        $this->assertArrayNotHasKey($a->id, $opts);
        $this->assertArrayHasKey($b->id, $opts);
        $page->callAction('addAsset', ['asset_id' => $b->id])->assertHasNoActionErrors();
        $this->assertSame($p2->id, $b->fresh()->reservationItem->arrival_plan_id);
        $page->callAction('addAsset', ['asset_id' => $a->id])->assertHasActionErrors(['asset_id']);       // sudah dicadangkan: tidak ada di pilihan
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_accessory_reservation_respects_free_stock_across_plans(): void
    {
        $this->as($this->staff());
        $acc = $this->acc();
        $free = $acc->qty_available - $this->svc->reservedQty($acc);
        $this->assertGreaterThan(3, $free);
        $p1 = $this->plan();
        $p2 = $this->plan();

        foreach ([0, -1] as $badQty) {
            try { $this->svc->addAccessory($p1, $acc, $badQty); $this->fail('jumlah tidak valid'); } catch (\InvalidArgumentException) { $this->assertTrue(true); }
        }
        $this->svc->addAccessory($p1, $acc, 2);
        $this->assertSame(2, $this->svc->reservedQty($acc));
        try { $this->svc->addAccessory($p2, $acc, $free - 1); $this->fail('melebihi stok bebas'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('hanya ' . ($free - 2), $e->getMessage()); }
        $this->svc->addAccessory($p2, $acc, $free - 2);                                           // tepat sisa bebas
        $this->assertSame($free, $this->svc->reservedQty($acc));
        $this->assertSame($acc->qty_available, $acc->fresh()->qty_available, 'stok fisik tidak berubah saat dicadangkan');
        try { $this->svc->addAccessory($p1, $acc, 1); $this->fail('stok bebas habis'); } catch (\InvalidArgumentException) { $this->assertTrue(true); }

        // menambah accessory yang sama dalam satu rencana menggabungkan jumlah dan menyiapkan ulang
        $it = $p1->items()->first();
        $this->svc->setItemStatus($it, 'prepared');
        $this->svc->removeItem($p2->items()->first());                                              // melepas pencadangan p2
        $this->svc->addAccessory($p1, $acc, 1);
        $this->assertSame(1, $p1->items()->count());
        $this->assertSame([3, 'planned'], [$it->fresh()->qty, $it->fresh()->status]);
        $this->assertSame(3, $this->svc->reservedQty($acc));

        $opts = Livewire::test(ViewArrivalPlan::class, ['record' => $p2->getKey()])->instance()->accessoryOptions();
        $this->assertStringContainsString('bisa dicadangkan: ' . ($acc->qty_available - 3), $opts[$acc->id]);
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    // ---------------------------------------------------------------- alur status
    public function test_status_flow_drives_the_plan_status_and_progress(): void
    {
        $this->as($this->staff());
        [$a, $b] = $this->free(2)->all();
        $p = $this->plan();
        $this->assertSame('planned', $p->fresh()->status);                                       // tanpa barang
        $i1 = $this->svc->addAsset($p, $a);
        $i2 = $this->svc->addAsset($p, $b);
        $i3 = $this->svc->addAccessory($p, $this->acc(), 1);
        $this->assertSame(['total' => 3, 'ready' => 0], $p->fresh()->progress());

        $page = Livewire::test(ViewArrivalPlan::class, ['record' => $p->getKey()]);
        $page->call('markItem', $i1->id, 'prepared');
        $this->assertSame('prepared', $p->fresh()->status);                                      // Disiapkan
        $page->call('markItem', $i1->id, 'ready')->call('markItem', $i2->id, 'prepared')->call('markItem', $i2->id, 'ready');
        $this->assertSame('prepared', $p->fresh()->status);                                      // satu barang masih Direncanakan
        $this->assertSame(2, $p->fresh()->progress()['ready']);
        $page->call('markItem', $i3->id, 'ready');                                                // boleh langsung Siap
        $this->assertSame('ready', $p->fresh()->status);                                         // semua siap → Siap
        $this->assertNotNull($i3->fresh()->ready_at);

        $page->call('markItem', $i2->id, 'prepared');                                             // batal siap
        $this->assertSame(['prepared', null], [$p->fresh()->status, $i2->fresh()->ready_at]);
        foreach ([$i1, $i2, $i3] as $i) $page->call('markItem', $i->id, 'planned');
        $this->assertSame('planned', $p->fresh()->status);
        $page->call('markItem', $i1->id, 'handed_over')->assertNotifiedFailure();                // status tidak valid
        $this->assertSame('planned', $i1->fresh()->status);

        // menghapus barang: pencadangan dilepas dan status rencana dihitung ulang
        $page->call('markItem', $i1->id, 'ready')->call('markItem', $i2->id, 'ready');
        $this->assertSame('prepared', $p->fresh()->status);
        $page->call('removeItem', $i3->id);
        $this->assertSame('ready', $p->fresh()->status);
        $page->call('removeItem', $i2->id);
        $this->assertNull($b->fresh()->reservationItem);
        $this->assertSame(['ready', 1], [$p->fresh()->status, $p->items()->count()]);
        $this->assertSame(['total' => 1, 'ready' => 1], $p->fresh()->progress());
    }

    public function test_item_cannot_be_marked_ready_when_it_is_no_longer_available(): void
    {
        $this->as($this->staff());
        $a = $this->free();
        $p = $this->plan();
        $it = $this->svc->addAsset($p, $a);
        $this->svc->setItemStatus($it, 'prepared');
        app(AssetService::class)->reportDamage($a->fresh(), null, 'Layar retak saat disiapkan');           // aset masuk perbaikan
        $this->assertStringContainsString('Perbaikan', (string) $it->fresh()->problem());
        Livewire::test(ViewArrivalPlan::class, ['record' => $p->getKey()])->call('markItem', $it->id, 'ready')->assertNotifiedFailure();
        $this->assertSame('prepared', $it->fresh()->status);
        $this->get(ArrivalPlanResource::getUrl('view', ['record' => $p]))->assertOk()->assertSee('Aset tidak tersedia');   // kendala terlihat di halaman

        // accessory: stok turun di bawah jumlah yang dicadangkan
        $acc = $this->acc();
        $p2 = $this->plan();
        $ai = $this->svc->addAccessory($p2, $acc, 2);
        app(StockService::class)->move($acc, 'out', $acc->fresh()->qty_available - 1, ['employee_id' => $this->emp('Rina Wulandari')->id, 'from_plan' => true]);
        $this->assertStringContainsString('Stok tinggal 1', (string) $ai->fresh()->problem());
        try { $this->svc->setItemStatus($ai, 'ready'); $this->fail('stok kurang'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Tidak bisa ditandai Siap', $e->getMessage()); }
    }

    // ---------------------------------------------------------------- pencadangan melindungi alur lain
    public function test_reserved_assets_and_stock_cannot_be_given_to_someone_else(): void
    {
        $this->as($this->staff());
        $a = $this->free();
        $p = $this->plan(['name' => 'Calon Pemilik Cadangan']);
        $this->svc->addAsset($p, $a);
        $other = $this->emp('Rina Wulandari');

        Livewire::test(\App\Filament\Resources\Assets\Pages\ViewAsset::class, ['record' => $a->getKey()])
            ->callAction('checkout', ['employee_id' => $other->id, 'condition' => 'good'])->assertNotifiedFailure();
        $this->assertSame('available', $a->fresh()->status);
        try { app(AssetService::class)->checkout($a->fresh(), $other); $this->fail('dicadangkan'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Calon Pemilik Cadangan', $e->getMessage()); }
        try { app(AssetService::class)->checkout($a->fresh(), $other, ['reservation_plan_id' => 999999]); $this->fail('rencana lain'); } catch (\InvalidArgumentException) { $this->assertTrue(true); }

        // tampilan: kartu pada detail aset, kolom pada daftar
        $this->get(\App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $a]))->assertOk()
            ->assertSee('Dicadangkan untuk rencana kedatangan')->assertSee('Calon Pemilik Cadangan')->assertSee($p->plan_no);
        Livewire::test(\App\Filament\Resources\Assets\Pages\ListAssets::class)->searchTable($a->asset_tag)->assertTableColumnStateSet('reserved', 'Calon Pemilik Cadangan', $a);

        // pengajuan disposal ditolak
        try { app(\App\Services\DisposalService::class)->request($a->id, 'x', true); $this->fail('dicadangkan'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('dicadangkan', $e->getMessage()); }

        // accessory: hanya stok bebas yang boleh diserahkan ke orang lain
        $acc = $this->acc();
        $free = $acc->qty_available - 3;
        $this->svc->addAccessory($p, $acc, 3);
        $this->assertGreaterThan(1, $free);
        app(StockService::class)->move($acc, 'out', $free, ['employee_id' => $other->id]);          // tepat sisa bebas: boleh
        $this->assertSame(3, $acc->fresh()->qty_available);
        try { app(StockService::class)->move($acc->fresh(), 'out', 1, ['employee_id' => $other->id]); $this->fail('milik rencana'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('dicadangkan', $e->getMessage()); }
        Livewire::test(\App\Filament\Resources\Accessories\Pages\ManageAccessories::class)
            ->callAction(\Filament\Actions\Testing\TestAction::make('checkout')->table($acc), ['employee_id' => $other->id, 'qty' => 1])->assertNotifiedFailure();
        $this->assertSame(3, $acc->fresh()->qty_available);
        // aset & stok lain tidak terpengaruh
        $c = $this->free();
        app(AssetService::class)->checkout($c, $other);
        $this->assertSame('deployed', $c->fresh()->status);
    }

    // ---------------------------------------------------------------- serah terima saat karyawan tiba
    public function test_handover_creates_employee_bast_per_asset_and_updates_stock(): void
    {
        Mail::fake();
        $this->as($this->staff());
        [$a, $b] = $this->free(2)->all();
        $acc = $this->acc();
        $stock = $acc->qty_available;
        $p = $this->plan(['name' => 'Karyawan Tiba', 'phone' => '0811999', 'position' => 'Surveyor']);
        $this->svc->addAsset($p, $a);
        $this->svc->addAsset($p, $b);
        $this->svc->addAccessory($p, $acc, 2);
        $this->ready($p);
        $this->assertSame('ready', $p->fresh()->status);
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
        $count = Employee::count();

        Livewire::test(ViewArrivalPlan::class, ['record' => $p->getKey()])
            ->callAction('handover', ['email' => 'karyawan.tiba@inventaris.test', 'condition' => 'good', 'signature_receiver' => $png, 'signature_it' => $png])->assertHasNoActionErrors();

        $p->refresh();
        $emp = Employee::where('email', 'karyawan.tiba@inventaris.test')->firstOrFail();
        $this->assertSame($count + 1, Employee::count());
        $this->assertSame(['active', 'Karyawan Tiba', 'Surveyor', '0811999'], [$emp->status, $emp->name, $emp->position, $emp->phone]);
        $this->assertSame(['handed_over', $emp->id], [$p->status, $p->employee_id]);
        $this->assertNotNull($p->handed_over_at);

        foreach ([$a, $b] as $asset) {
            $asset->refresh();
            $this->assertSame(['deployed', $emp->id, $p->location_id], [$asset->status, $asset->employee_id, $asset->location_id]);
            $row = $asset->assignments()->first();
            $this->assertSame('checkout', $row->type);
            $this->assertStringContainsString($p->plan_no, (string) $row->notes);
            $this->assertTrue(\Storage::disk('local')->exists($row->bast_pdf_path));              // BAST per aset
            $this->assertTrue(\Storage::disk('local')->exists($row->signature_receiver_path));
            $this->assertNull($asset->reservationItem, 'pencadangan selesai');
        }
        $this->assertSame($stock - 2, $acc->fresh()->qty_available);                               // stok berkurang
        $mv = $acc->movements()->where('type', 'out')->latest('id')->first();
        $this->assertSame([$emp->id, 2.0], [$mv->employee_id, (float) $mv->qty]);
        foreach ($p->items()->get() as $i) {
            $this->assertSame('handed_over', $i->status);
            $this->assertNotNull($i->type === 'asset' ? $i->asset_assignment_id : $i->stock_movement_id);
        }
        Mail::assertQueued(BastMail::class, 2);
        $this->assertSame(1, collect($emp->heldAccessories())->where('qty', 2)->count());

        // halaman rencana menampilkan BAST (Lihat/Unduh) dan tidak bisa diubah lagi
        $html = $this->get(ArrivalPlanResource::getUrl('view', ['record' => $p]))->assertOk()->getContent();
        $this->assertStringContainsString('Diserahkan', $html);
        $this->assertStringContainsString(route('bast.view', $a->assignments()->first()), $html);
        $this->assertStringContainsString(route('bast.download', $b->assignments()->first()), $html);
        Livewire::test(ViewArrivalPlan::class, ['record' => $p->getKey()])->assertActionHidden('handover')->assertActionHidden('addAsset')->assertActionHidden('cancel');
        $this->get(ArrivalPlanResource::getUrl('edit', ['record' => $p]))->assertForbidden();
        try { $this->svc->addAsset($p, $this->free()); $this->fail('sudah diserahkan'); } catch (\InvalidArgumentException) { $this->assertTrue(true); }
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_handover_requires_ready_items_unless_partial_and_a_valid_unique_email(): void
    {
        $this->as($this->staff());
        [$a, $b] = $this->free(2)->all();
        $p = $this->plan(['name' => 'Sebagian Siap']);
        $i1 = $this->svc->addAsset($p, $a);
        $i2 = $this->svc->addAsset($p, $b);
        $this->svc->setItemStatus($i1, 'prepared');
        $this->svc->setItemStatus($i1->fresh(), 'ready');
        $emp = Employee::count();
        $page = fn () => Livewire::test(ViewArrivalPlan::class, ['record' => $p->getKey()]);

        $page()->callAction('handover', ['email' => 'sebagian@inventaris.test', 'condition' => 'good'])->assertNotifiedFailure();          // ada yang belum siap
        $this->assertSame([$emp, 'prepared'], [Employee::count(), $p->fresh()->status]);
        $this->assertSame('available', $a->fresh()->status);

        $page()->callAction('handover', ['email' => Employee::first()->email, 'condition' => 'good', 'partial' => true])->assertNotifiedFailure();   // email sudah ada
        $page()->callAction('handover', ['email' => 'bukan-email', 'condition' => 'good', 'partial' => true])->assertHasActionErrors(['email']);
        $this->assertSame($emp, Employee::count());

        $page()->callAction('handover', ['email' => 'sebagian@inventaris.test', 'condition' => 'good', 'partial' => true])->assertHasNoActionErrors();
        $this->assertSame(['handed_over', 'handed_over', 'cancelled'], [$p->fresh()->status, $i1->fresh()->status, $i2->fresh()->status]);
        $this->assertSame('deployed', $a->fresh()->status);
        $this->assertSame('available', $b->fresh()->status);                                       // yang belum siap dilepas
        $this->assertNull($b->fresh()->reservationItem);
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_handover_without_ready_items_or_email_is_rejected(): void
    {
        $this->as($this->staff());
        $p = $this->plan();
        try { $this->svc->handover($p, ['email' => 'x@y.test']); $this->fail('tanpa barang'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('belum memiliki barang', $e->getMessage()); }
        $it = $this->svc->addAsset($p, $this->free());
        try { $this->svc->handover($p, ['email' => 'x@y.test', 'partial' => true]); $this->fail('tidak ada yang siap'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('berstatus Siap', $e->getMessage()); }
        $this->svc->setItemStatus($it, 'ready');
        try { $this->svc->handover($p, ['condition' => 'good']); $this->fail('tanpa email'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Email', $e->getMessage()); }
        $this->assertSame('ready', $p->fresh()->status);
    }

    public function test_handover_is_all_or_nothing_when_an_item_became_unavailable(): void
    {
        $this->as($this->staff());
        [$a, $b] = $this->free(2)->all();
        $p = $this->plan();
        $this->svc->addAsset($p, $a);
        $this->svc->addAsset($p, $b);
        $this->svc->addAccessory($p, $this->acc(), 1);
        $this->ready($p);
        $b->update(['status' => 'lost']);                                                           // berubah setelah ditandai siap
        $employees = Employee::count();
        $moves = $this->acc()->movements()->count();
        try { $this->svc->handover($p, ['email' => 'gagal@inventaris.test', 'condition' => 'good']); $this->fail('harus gagal'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('tidak tersedia', $e->getMessage()); }
        $this->assertSame($employees, Employee::count(), 'karyawan tidak dibuat');
        $this->assertSame('available', $a->fresh()->status, 'aset lain tidak ikut diserahkan');
        $this->assertSame(0, \App\Models\AssetAssignment::where('notes', 'like', "%{$p->plan_no}%")->count());
        $this->assertSame($moves, $this->acc()->movements()->count());
        $this->assertSame('ready', $p->fresh()->status);
        $this->assertSame(3, $p->items()->where('status', 'ready')->count());
    }

    // ---------------------------------------------------------------- ubah tanggal & batal
    public function test_reschedule_and_cancel_release_reservations(): void
    {
        $this->as($this->staff());
        $a = $this->free();
        $acc = $this->acc();
        $p = $this->plan();
        $this->svc->addAsset($p, $a);
        $this->svc->addAccessory($p, $acc, 2);

        $page = Livewire::test(ViewArrivalPlan::class, ['record' => $p->getKey()]);
        $new = now()->addDays(10)->toDateString();
        $page->callAction('reschedule', ['arrival_date' => $new])->assertHasNoActionErrors();
        $this->assertSame($new, $p->fresh()->arrival_date->toDateString());
        $page->callAction('reschedule', ['arrival_date' => now()->subDay()->toDateString()])->assertHasActionErrors(['arrival_date']);
        $this->assertSame($new, $p->fresh()->arrival_date->toDateString());

        $this->assertSame(2, $this->svc->reservedQty($acc));
        Livewire::test(ViewArrivalPlan::class, ['record' => $p->getKey()])->callAction('cancel', ['reason' => 'HRD membatalkan'])->assertHasNoActionErrors();
        $p->refresh();
        $this->assertSame(['cancelled', 'HRD membatalkan'], [$p->status, $p->cancel_reason]);
        $this->assertSame(['cancelled', 'cancelled'], $p->items()->pluck('status')->all());
        $this->assertNull($a->fresh()->reservationItem);                                            // aset bebas lagi
        $this->assertSame(0, $this->svc->reservedQty($acc));
        $p2 = $this->plan();
        $this->svc->addAsset($p2, $a);                                                              // bisa dicadangkan rencana lain
        try { $this->svc->addAsset($p, $this->free()); $this->fail('sudah dibatalkan'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('dibatalkan', $e->getMessage()); }
        try { $this->svc->cancel($p); $this->fail('dua kali'); } catch (\InvalidArgumentException) { $this->assertTrue(true); }
        Livewire::test(ViewArrivalPlan::class, ['record' => $p->getKey()])->assertActionHidden('cancel')->assertActionHidden('addAsset');
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_plan_details_can_be_edited_while_active(): void
    {
        $this->as($this->staff());
        $p = $this->plan(['name' => 'Nama Lama']);
        Livewire::test(EditArrivalPlan::class, ['record' => $p->getKey()])->fillForm(['name' => 'Nama Baru', 'position' => 'Surveyor Senior'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(['Nama Baru', 'Surveyor Senior'], [$p->fresh()->name, $p->fresh()->position]);
        $log = \Spatie\Activitylog\Models\Activity::where('subject_type', ArrivalPlan::class)->where('subject_id', $p->id)->where('event', 'updated')->latest('id')->first();
        $this->assertNotNull($log, 'perubahan tercatat di audit log');
        $this->svc->cancel($p);
        $this->get(ArrivalPlanResource::getUrl('edit', ['record' => $p]))->assertForbidden();
    }

    // ---------------------------------------------------------------- pengingat, dashboard, laporan
    public function test_h7_and_h1_notifications_for_plans_that_are_not_ready(): void
    {
        $this->as($this->staff());
        $mk = fn (string $n, int $days) => $this->plan(['name' => $n, 'arrival_date' => now()->addDays($days)->toDateString()]);
        $h7 = $mk('Tiba Tujuh', 7);
        $h1 = $mk('Tiba Satu', 1);
        $h3 = $mk('Tiba Tiga', 3);
        $okReady = $mk('Tiba Satu Siap', 1);
        $this->svc->addAsset($h7, $this->free());
        $this->svc->addAsset($okReady, Asset::where('status', 'available')->whereNull('employee_id')->where('ownership', 'owned')->whereDoesntHave('reservationItem')->orderByDesc('id')->firstOrFail());
        $this->ready($okReady);
        $overdue = $mk('Lewat Tanggal', 0);
        ArrivalPlan::whereKey($overdue->id)->update(['arrival_date' => now()->subDays(2)->toDateString()]);
        $done = $mk('Sudah Batal', 1);
        $this->svc->cancel($done);

        $this->artisan('inventory:notify', ['kind' => 'daily'])->assertSuccessful();
        $titles = $this->staff()->notifications()->pluck('data')->map(fn ($d) => $d['title'] ?? '')->all();
        $this->assertNotEmpty(preg_grep('/Kedatangan Tiba Tujuh 7 hari lagi, belum siap/', $titles));
        $this->assertNotEmpty(preg_grep('/Kedatangan Tiba Satu 1 hari lagi, belum siap/', $titles));
        $this->assertNotEmpty(preg_grep('/Kedatangan Lewat Tanggal lewat tanggal, belum diserahkan/', $titles));
        $this->assertEmpty(preg_grep('/Tiba Tiga/', $titles), 'H-3 tidak memicu notifikasi');
        $this->assertEmpty(preg_grep('/Tiba Satu Siap/', $titles), 'rencana yang sudah siap tidak diingatkan H-7/H-1');
        $this->assertEmpty(preg_grep('/Sudah Batal/', $titles), 'rencana dibatalkan tidak diingatkan');
        $this->assertEmpty(preg_grep('/Kedatangan/', collect($this->plain()->notifications)->pluck('data')->map(fn ($d) => $d['title'] ?? '')->all()), 'karyawan biasa tidak menerima');
        $body = collect($this->staff()->notifications)->first(fn ($n) => str_contains($n->data['title'] ?? '', 'Tiba Tujuh'))->data['body'];
        $this->assertStringContainsString($h7->plan_no, $body);
        $this->assertStringContainsString('0 dari 1 barang siap', $body);
    }

    public function test_demo_data_covers_every_plan_status_and_stays_consistent(): void
    {
        $by = ArrivalPlan::all()->groupBy('status');
        foreach (['planned', 'prepared', 'ready', 'handed_over', 'cancelled'] as $s) $this->assertTrue($by->has($s), "demo harus punya rencana berstatus {$s}");
        $done = ArrivalPlan::where('status', 'handed_over')->firstOrFail();
        $this->assertNotNull($done->employee_id);
        $this->assertSame('active', $done->employee->status);
        $this->assertGreaterThan(0, $done->items()->where('type', 'asset')->where('status', 'handed_over')->whereNotNull('asset_assignment_id')->count());
        $cancelled = ArrivalPlan::where('status', 'cancelled')->firstOrFail();
        $this->assertSame(0, $cancelled->items()->whereIn('status', ArrivalPlanItem::RESERVING)->count());
        $reserved = ArrivalPlanItem::whereIn('status', ArrivalPlanItem::RESERVING)->where('type', 'asset')->pluck('asset_id');
        $this->assertSame($reserved->count(), $reserved->unique()->count(), 'satu aset hanya dicadangkan sekali');
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_dashboard_report_and_list_show_the_plans(): void
    {
        $this->as($this->admin());
        $d = Livewire::test(\App\Filament\Pages\Dashboard::class)->instance()->data();
        $this->assertGreaterThanOrEqual(3, $d['arrivals']->count());
        $this->assertTrue($d['arrivals']->every(fn ($p) => $p->isActive() && $p->days_left <= 14));
        $this->assertTrue($d['arrivalsAtRisk']->every(fn ($p) => in_array($p->status, ['planned', 'prepared'], true) && $p->days_left <= 7));
        $this->assertGreaterThanOrEqual(1, $d['arrivalsAtRisk']->count());
        $this->get('/admin')->assertOk()->assertSee('Kedatangan karyawan onsite')->assertSee('Rencana Kedatangan')->assertSee('belum siap');

        [$title, $head, $rows] = ReportCatalog::build('arrivals', []);
        $this->assertSame('Rencana Kedatangan Karyawan', $title);
        $this->assertCount(ArrivalPlan::count(), $rows);
        foreach ($rows as $r) $this->assertCount(count($head), $r);
        $this->assertTrue(collect($rows)->contains(fn ($r) => str_contains((string) $r[10], '[Siap]')));
        $this->assertLessThan(count($rows), count(ReportCatalog::build('arrivals', ['from' => now()->addDays(8)->toDateString()])[2]));
        $this->get('/reports/arrivals.xlsx')->assertOk();
        $this->get('/reports/arrivals.pdf')->assertOk();

        $all = ArrivalPlan::count();
        Livewire::test(ListArrivalPlans::class)->assertCountTableRecords(ArrivalPlan::active()->count())                    // bawaan: yang masih berjalan
            ->removeTableFilter('active')->assertCountTableRecords($all);
        Livewire::test(ListArrivalPlans::class)->filterTable('status', 'cancelled')->removeTableFilter('active')->assertCountTableRecords(ArrivalPlan::where('status', 'cancelled')->count());
        Livewire::test(ListArrivalPlans::class)->filterTable('not_ready', true)->assertCountTableRecords(ArrivalPlan::whereIn('status', ['planned', 'prepared'])->count());
        Livewire::test(ListArrivalPlans::class)->filterTable('this_week', true)->assertCountTableRecords(ArrivalPlan::active()->whereDate('arrival_date', '<=', now()->addDays(7)->toDateString())->count());
        $sub = Livewire::test(ListArrivalPlans::class)->instance()->getSubheading();
        $this->assertStringContainsString(ArrivalPlan::active()->count() . ' rencana berjalan', $sub);
    }

    public function test_permissions_and_navigation(): void
    {
        $this->assertSame('Transaksi', (string) ArrivalPlanResource::getNavigationGroup());
        $this->assertSame('Rencana Kedatangan', ArrivalPlanResource::getNavigationLabel());
        $this->assertSame('/admin/rencana-kedatangan', parse_url(ArrivalPlanResource::getUrl(), PHP_URL_PATH));
        $p = ArrivalPlan::active()->firstOrFail();

        $this->as($this->staff())->get('/admin/rencana-kedatangan')->assertOk()->assertSee('Rencana Kedatangan');
        $this->get('/admin/rencana-kedatangan/create')->assertOk();
        Livewire::test(ViewArrivalPlan::class, ['record' => $p->getKey()])->assertActionVisible('addAsset')->assertActionVisible('addAccessory')->assertActionVisible('cancel');

        $this->as($this->mgmt())->get('/admin/rencana-kedatangan')->assertOk();
        $this->get(ArrivalPlanResource::getUrl('view', ['record' => $p]))->assertOk();
        $this->get('/admin/rencana-kedatangan/create')->assertForbidden();
        Livewire::test(ViewArrivalPlan::class, ['record' => $p->getKey()])->assertActionHidden('addAsset')->assertActionHidden('handover')->assertActionHidden('cancel');

        $this->as($this->plain())->get('/admin/rencana-kedatangan')->assertForbidden();
        $this->get(ArrivalPlanResource::getUrl('view', ['record' => $p]))->assertForbidden();
    }

    public function test_handover_of_accessories_makes_one_bast_for_the_new_employee(): void
    {
        $this->as($this->staff());
        $acc = $this->acc();
        $other = Accessory::where('id', '!=', $acc->id)->where('qty_available', '>', 3)->firstOrFail();
        $p = $this->plan(['name' => 'Aksesori BAST']);
        $i1 = $this->svc->addAccessory($p, $acc, 1);
        $i2 = $this->svc->addAccessory($p, $other, 2);
        $this->ready($p);
        $before = \App\Models\AccessoryHandover::count();
        $availAcc = $acc->fresh()->qty_available; $availOther = $other->fresh()->qty_available;

        $this->svc->handover($p, ['email' => 'bast-plan@inventaris.test', 'condition' => 'good']);

        $this->assertSame($before + 1, \App\Models\AccessoryHandover::count());                 // satu BAST untuk semua aksesori
        $h = \App\Models\AccessoryHandover::latest('id')->firstOrFail();
        $this->assertSame('checkout', $h->type);
        $this->assertSame($p->fresh()->employee_id, $h->employee_id);
        $this->assertCount(2, $h->items);
        $this->assertStringContainsString($p->plan_no, (string) $h->notes);
        $this->assertSame($h->items->firstWhere('accessory_id', $acc->id)->stock_movement_id, $i1->fresh()->stock_movement_id);
        $this->assertSame($availAcc - 1, $acc->fresh()->qty_available);
        $this->assertSame($availOther - 2, $other->fresh()->qty_available);
        $this->assertNotEmpty(\App\Support\AccessoryBastDocument::build($h)['rows']);
        $this->assertSame('handed_over', $i2->fresh()->status);
    }
}
