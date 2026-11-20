<?php

namespace Tests\Qa;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetAgentToken;
use App\Services\SimaImport\SimaImporter;
use App\Services\SimaImport\SimaReconciler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\QaTestCase;

/**
 * Impor SIMA -> aplikasi baru, diuji terhadap fixture legacy (skema asli Snipe-IT + kustom AKP, data tiruan) yang dimuat ke
 * SQLite sebagai koneksi "legacy". Fixture dibuat ulang dengan tests/Fixtures/sima_legacy_seed.php lalu diekspor.
 */
class Qa61_SimaImportTest extends QaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('legacy');
        $data = json_decode(file_get_contents(base_path('tests/Fixtures/sima_legacy.json')), true);
        $L = Schema::connection('legacy');
        foreach ($data as $table => $d) {
            $L->create($table, function ($t) use ($d) {
                foreach ($d['columns'] as [$name, $kind]) {
                    match ($kind) { 'integer' => $t->integer($name)->nullable(), 'float' => $t->double($name)->nullable(), default => $t->text($name)->nullable() };
                }
            });
            foreach (array_chunk($d['rows'], 100) as $chunk) DB::connection('legacy')->table($table)->insert($chunk);
        }
    }

    private function import(array $only = []): SimaImporter
    {
        $imp = app(SimaImporter::class);
        Model::withoutEvents(fn () => $imp->run($only));
        return $imp;
    }

    public function test_import_creates_everything_and_reconciliation_is_clean(): void
    {
        $imp = $this->import();

        $rows = app(SimaReconciler::class)->run($imp);
        $bad = collect($rows)->reject(fn ($r) => $r['ok'])->map(fn ($r) => "{$r['check']}: {$r['legacy']} vs {$r['new']}")->all();
        $this->assertSame([], $bad, 'Rekonsiliasi selisih: ' . implode('; ', $bad));
        $this->assertSame(29, DB::table('legacy_map')->where('entity', 'asset')->count());
    }

    public function test_import_is_idempotent(): void
    {
        $this->import();
        $counts = fn () => [DB::table('assets')->count(), DB::table('employees')->count(), DB::table('asset_assignments')->count(), DB::table('stock_movements')->count(),
            DB::table('accessories')->count(), DB::table('licenses')->count(), DB::table('license_seats')->count(), DB::table('asset_agent_tokens')->count(), DB::table('asset_tag_aliases')->count()];
        $before = $counts();
        $second = $this->import();
        $this->assertSame($before, $counts());
        $this->assertArrayNotHasKey('assignments_created', $second->stats);
        $this->assertArrayNotHasKey('employees_created', $second->stats);
    }

    public function test_tags_serials_and_aliases(): void
    {
        $this->import();
        // tag yang sudah berformat baru dipertahankan; yang lama dibuat ulang + alias supaya QR lama tetap terbaca
        $this->assertTrue(Asset::where('asset_tag', 'AKP-IT-NB-0001')->exists());
        $legacy = DB::table('asset_tag_aliases')->where('tag', 'LAP-006')->first();
        $this->assertNotNull($legacy);
        $this->assertMatchesRegularExpression('/^AKP-IT-NB-\d{4}$/', Asset::find($legacy->asset_id)->asset_tag);
        $this->assertSame('LAP-006', Asset::find($legacy->asset_id)->legacy_tag);
        // pindai label LAMA (QR/URL pendek) tetap menemukan asetnya
        $this->assertSame($legacy->asset_id, Asset::findByCode('LAP-006')?->id);
        $this->assertSame($legacy->asset_id, Asset::findByCode('https://x.test/s/LAP-006')?->id);
        // serial duplikat (beda huruf besar/kecil) tidak ikut; tercatat di catatan, bukan hilang diam-diam
        $this->assertSame(1, Asset::whereRaw("lower(serial_number) = 'dup-1'")->count());
        $this->assertSame(1, Asset::where('notes', 'like', '%duplikat%')->count());
        $this->assertSame(Asset::count(), Asset::distinct('asset_tag')->count('asset_tag'));
    }

    public function test_nonstandard_status_is_traceable_and_unrepairable_is_major_damage(): void
    {
        DB::connection('legacy')->table('status_labels')->insert(['id' => 90, 'name' => 'Non-Repairable', 'deployable' => 0, 'pending' => 0, 'archived' => 0]);
        DB::connection('legacy')->table('assets')->where('id', 12)->update(['status_id' => 90]);
        $this->import();
        $a = Asset::find(DB::table('legacy_map')->where('entity', 'asset')->where('legacy_id', 12)->value('new_id'));
        $this->assertSame('repair', $a->status);                       // bukan disposed: itu butuh persetujuan
        $this->assertSame('major_damage', $a->condition);
        $this->assertStringContainsString('Status di SIMA: Non-Repairable', $a->notes);
    }

    public function test_custom_field_values_are_kept_in_notes_and_missing_columns_are_fine(): void
    {
        DB::connection('legacy')->table('assets')->where('id', 3)->update(['_snipeit_mac_address_1' => 'AA:BB:CC:DD:EE:FF']);
        $this->import();
        $a = Asset::find(DB::table('legacy_map')->where('entity', 'asset')->where('legacy_id', 3)->value('new_id'));
        $this->assertStringContainsString('AA:BB:CC:DD:EE:FF', (string) $a->notes);
    }

    public function test_import_works_when_legacy_has_no_custom_field_columns(): void
    {
        Schema::connection('legacy')->table('assets', fn ($t) => $t->dropColumn('_snipeit_mac_address_1'));
        $imp = $this->import();
        $this->assertSame(29, $imp->stats['assets_created']);
    }

    public function test_nik_embedded_in_the_name_is_recovered(): void
    {
        DB::connection('legacy')->table('users')->where('username', 'tanpa.nik')->update(['display_name' => '(TANPA NIK) - #32601923']);
        $this->import();
        $this->assertSame(1, DB::table('employees')->where('nik', '32601923')->count());
    }

    public function test_duplicate_accounts_with_the_same_nik_are_merged_and_reconciliation_stays_clean(): void
    {
        $L = DB::connection('legacy');
        $u = (array) $L->table('users')->where('username', '3260001')->first();
        $dupId = 900;
        $L->table('users')->insert(['id' => $dupId, 'username' => 'andi.ganda', 'email' => 'andi.ganda@akp.test'] + $u);
        $L->table('assets')->where('id', 20)->update(['assigned_type' => 'App\\Models\\User', 'assigned_to' => $dupId]);
        $imp = $this->import();

        $this->assertSame(1, DB::table('employees')->where('nik', '3260001')->count());
        $bad = collect(app(SimaReconciler::class)->run($imp))->reject(fn ($r) => $r['ok'])->pluck('check')->all();
        $this->assertSame([], $bad);
    }

    public function test_old_sima_qr_urls_and_old_tags_still_open_the_asset(): void
    {
        $this->import();
        $legacyId = 6;
        $newId = DB::table('legacy_map')->where('entity', 'asset')->where('legacy_id', $legacyId)->value('new_id');
        $view = \App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $newId]);

        $this->get("/hardware/{$legacyId}")->assertRedirect($view);                       // QR lama: /hardware/<id SIMA>
        $this->get('/hardware/bytag/LAP-006')->assertRedirect(\App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => Asset::findByCode('LAP-006')]));   // tag lama via alias
        $this->get('/hardware/999999')->assertRedirect('/admin');                          // tak dikenal: tidak 404 membingungkan
    }

    public function test_activated_flag_is_login_ability_not_employment_and_old_wrong_status_is_repaired(): void
    {
        $L = DB::connection('legacy');
        $L->table('users')->where('username', '3260002')->update(['activated' => 0]);   // seperti 99% pengguna SIMA1
        $this->import();
        $id = DB::table('employees')->where('nik', '3260002')->value('id');
        $this->assertSame('active', DB::table('employees')->where('id', $id)->value('status'));

        // data hasil impor versi lama (salah): resigned padahal aktif -> dipulihkan; yang sudah dikelola HRIS tidak disentuh
        DB::table('employees')->where('id', $id)->update(['status' => 'resigned']);
        $other = DB::table('employees')->where('nik', '3260001')->value('id');
        DB::table('employees')->where('id', $other)->update(['status' => 'resigned', 'hris_synced_at' => now()]);
        $this->import();
        $this->assertSame('active', DB::table('employees')->where('id', $id)->value('status'));
        $this->assertSame('resigned', DB::table('employees')->where('id', $other)->value('status'));
    }

    public function test_holders_history_and_balancing_rows(): void
    {
        $this->import();
        $kar = fn (string $nik) => DB::table('employees')->where('nik', $nik)->value('id');

        // transfer Andi -> Siti: tiga baris riwayat, pemegang akhir Siti
        $a6 = DB::table('legacy_map')->where('entity', 'asset')->where('legacy_id', 6)->value('new_id');
        $types = DB::table('asset_assignments')->where('asset_id', $a6)->orderBy('assigned_at')->orderBy('id')->pluck('type')->all();
        $this->assertSame(['checkout', 'checkin', 'checkout'], $types);
        $this->assertSame($kar('3260002'), (int) Asset::find($a6)->employee_id);

        // nomor BAST lama dipakai; yang tidak punya diberi nomor LEGACY unik
        $this->assertTrue(DB::table('asset_assignments')->where('bast_number', 'IT/BAST/2024/06/0001')->exists());
        $this->assertTrue(DB::table('asset_assignments')->where('bast_number', 'like', AssetAssignment::LEGACY_NUMBER_PREFIX . 'SIMA-%')->exists());
        $this->assertSame(DB::table('asset_assignments')->count(), DB::table('asset_assignments')->distinct('bast_number')->count('bast_number'));

        // dipegang tapi tanpa riwayat -> baris penyesuaian, bukan dibiarkan tak konsisten
        $this->assertGreaterThanOrEqual(1, DB::table('asset_assignments')->where('notes', 'like', 'Penyesuaian saldo%')->count());
        // dikeluarkan ke lokasi -> tersedia di lokasi itu, bukan hilang
        $site = DB::table('locations')->where('name', 'Site A')->value('id');
        $this->assertSame('available', Asset::where('location_id', $site)->where('notes', 'like', '%dikeluarkan ke lokasi%')->value('status'));
    }

    public function test_statuses_unknown_status_is_reported_and_resigned_employee_flag(): void
    {
        $imp = $this->import();
        $this->assertSame(['status aneh cabang' => 1], $imp->unknownStatuses);
        $this->assertSame(1, Asset::onlyTrashed()->count());
        $this->assertSame('resigned', DB::table('employees')->where('nik', '3260006')->value('status'));
        // pengguna tanpa NIK yang masih dirujuk dibuat + dilaporkan; yang tidak dirujuk dilewati
        $this->assertSame(['Tanpa NIK Uji'], DB::table('employees')->whereNull('nik')->whereIn('id', DB::table('legacy_map')->where('entity', 'employee')->pluck('new_id'))->pluck('name')->all());
        $this->assertCount(1, $imp->unmatched);
        $this->assertSame(1, $imp->stats['users_skipped_no_nik_unreferenced']);
        $this->assertSame(2, DB::table('departments')->whereIn('name', ['IT', 'Finance'])->count());   // 'it' dan 'IT' digabung
    }

    public function test_stock_balances_and_licenses(): void
    {
        $this->import();
        $acc = DB::table('accessories')->where('id', DB::table('legacy_map')->where('entity', 'accessory')->value('new_id'))->first();
        $this->assertSame([10, 7], [(int) $acc->qty_total, (int) $acc->qty_available]);   // 3 dipegang karyawan
        $movements = DB::table('stock_movements')->where('stockable_type', \App\Models\Accessory::class)->where('stockable_id', $acc->id);
        $this->assertSame(10 - 3, (int) (clone $movements)->where('type', 'in')->sum('qty') - (int) (clone $movements)->where('type', 'out')->sum('qty'));
        $comp = DB::table('components')->where('id', DB::table('legacy_map')->where('entity', 'component')->value('new_id'))->first();
        $this->assertSame([6, 4], [(int) $comp->qty_total, (int) $comp->qty_available]);
        $this->assertSame(1, DB::table('stock_movements')->where('type', 'install')->where('stockable_id', $comp->id)->count());
        $this->assertSame(['perpetual', 'subscription'], DB::table('licenses')->whereIn('id', DB::table('legacy_map')->where('entity', 'license')->pluck('new_id'))->orderBy('id')->pluck('type')->all());
        $this->assertSame(2, DB::table('license_seats')->whereIn('license_id', DB::table('legacy_map')->where('entity', 'license')->pluck('new_id'))->count());   // seat kosong tidak ikut
    }

    public function test_reimport_resyncs_untouched_stock_but_never_overwrites_changes_made_here(): void
    {
        $this->import();
        $L = DB::connection('legacy');
        $nid = fn (string $e) => DB::table('legacy_map')->where('entity', $e)->value('new_id');
        $row = fn (string $table, string $e) => DB::table($table)->where('id', $nid($e))->first();

        // SIMA1 masih beroperasi: +5 mouse, consumable terpakai sampai 3, 1 RAM dipasang lagi
        $L->table('accessories')->update(['qty' => 15]);
        $L->table('consumables')->update(['qty' => 3]);
        $L->table('components_assets')->where('component_id', DB::table('legacy_map')->where('entity', 'component')->value('legacy_id'))->update(['assigned_qty' => 3]);
        $imp = $this->import();

        $acc = $row('accessories', 'accessory'); $comp = $row('components', 'component');
        $this->assertSame([15, 12], [(int) $acc->qty_total, (int) $acc->qty_available]);
        $this->assertSame(3, (int) $row('consumables', 'consumable')->qty);
        $this->assertSame([6, 3], [(int) $comp->qty_total, (int) $comp->qty_available]);
        $this->assertGreaterThanOrEqual(3, $imp->stats['stock_resynced']);
        // saldo = jumlah gerakan (riwayat tetap konsisten dengan angka)
        $this->assertSame(15, (int) DB::table('stock_movements')->where('stockable_type', \App\Models\Accessory::class)->where('stockable_id', $acc->id)->whereIn('type', ['in', 'adjust'])->sum('qty'));

        // perubahan dari aplikasi ini TIDAK ditimpa oleh impor ulang berikutnya
        $conId = $nid('consumable');
        DB::table('stock_movements')->insert(['stockable_type' => \App\Models\Consumable::class, 'stockable_id' => $conId, 'type' => 'out', 'qty' => 1, 'reason' => 'Dipakai di sistem baru', 'moved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('consumables')->where('id', $conId)->update(['qty' => 2]);
        $L->table('consumables')->update(['qty' => 9]);
        $imp2 = $this->import();
        $this->assertSame(2, (int) $row('consumables', 'consumable')->qty);
        $this->assertSame(1, $imp2->stats['stock_resync_skipped']);
    }

    public function test_legacy_agent_tokens_keep_working_and_revoked_stay_revoked(): void
    {
        $this->import();
        $plain = fn (int $n) => "agent-token-$n-" . str_repeat('x', 20);

        $this->assertNotNull(AssetAgentToken::findActiveMatching($plain(1)));   // bcrypt + lookup asli SIMA, tidak dibuat ulang
        $this->assertNull(AssetAgentToken::findActiveMatching($plain(5)));      // dicabut tetap dicabut
        $this->assertNull(AssetAgentToken::findActiveMatching('salah'));

        $this->withToken($plain(1))->postJson('/api/agent/v1/heartbeat', ['hostname' => 'LAPTOP-1'])->assertOk()->assertJsonPath('status', 'ok');
        // perintah tertunda ikut; riwayat perintah lama tidak
        $this->assertSame(1, DB::table('asset_agent_commands')->count());
        $this->assertSame(['status' => 'online'], ['status' => Asset::find(DB::table('legacy_map')->where('entity', 'asset')->where('legacy_id', 1)->value('new_id'))->agent_status]);
    }

    public function test_dry_run_command_changes_nothing(): void
    {
        $before = DB::table('assets')->count();
        $this->artisan('sima:import', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame($before, DB::table('assets')->count());
        $this->assertSame(0, DB::table('legacy_map')->count());
    }

    public function test_consumable_usage_history_is_imported_and_the_balance_stays_equal_to_sima(): void
    {
        $L = Schema::connection('legacy');
        $L->create('consumable_transactions', fn ($t) => [$t->integer('id'), $t->text('document_number')->nullable(), $t->text('type')->nullable(), $t->text('transaction_date')->nullable(), $t->integer('supplier_id')->nullable(),
            $t->text('reference')->nullable(), $t->text('target_type')->nullable(), $t->integer('target_id')->nullable(), $t->text('notes')->nullable(), $t->integer('created_by')->nullable(), $t->text('created_at')->nullable(), $t->text('updated_at')->nullable()]);
        $L->create('consumable_transaction_items', fn ($t) => [$t->integer('id'), $t->integer('consumable_transaction_id'), $t->integer('consumable_id'), $t->integer('qty'), $t->double('unit_cost')->nullable(), $t->text('notes')->nullable(), $t->text('created_at')->nullable(), $t->text('updated_at')->nullable()]);
        $L->create('consumables_users', fn ($t) => [$t->integer('id'), $t->integer('created_by')->nullable(), $t->integer('consumable_id')->nullable(), $t->integer('assigned_to')->nullable(), $t->text('created_at')->nullable(), $t->text('updated_at')->nullable(), $t->text('note')->nullable()]);
        $user = DB::connection('legacy')->table('assets')->whereNotNull('assigned_to')->orderBy('id')->value('assigned_to');   // pengguna yang pasti diimpor sebagai karyawan
        $D = DB::connection('legacy');
        $D->table('consumable_transactions')->insert([
            ['id' => 1, 'document_number' => '002/IT/BPB/HO/AKP/X/2026', 'type' => 'out', 'transaction_date' => '2026-10-06', 'reference' => null, 'target_type' => 'user', 'target_id' => $user, 'notes' => 'Untuk survey', 'created_at' => '2026-10-06 14:00:00'],
            ['id' => 2, 'document_number' => '003/IT/BPM/HO/AKP/X/2026', 'type' => 'in', 'transaction_date' => '2026-10-08', 'reference' => 'PO-77', 'target_type' => null, 'target_id' => null, 'notes' => null, 'created_at' => '2026-10-08 10:00:00'],
        ]);
        $D->table('consumable_transaction_items')->insert([['id' => 1, 'consumable_transaction_id' => 1, 'consumable_id' => 1, 'qty' => 2, 'notes' => null], ['id' => 2, 'consumable_transaction_id' => 2, 'consumable_id' => 1, 'qty' => 1, 'notes' => null]]);
        $D->table('consumables_users')->insert([   // 3 baris: 2 sudah dicakup transaksi keluar, 1 pengeluaran langsung
            ['id' => 1, 'consumable_id' => 1, 'assigned_to' => $user, 'created_at' => '2026-10-06 14:00:00', 'note' => null],
            ['id' => 2, 'consumable_id' => 1, 'assigned_to' => $user, 'created_at' => '2026-10-06 14:00:00', 'note' => null],
            ['id' => 3, 'consumable_id' => 1, 'assigned_to' => $user, 'created_at' => '2026-11-01 09:00:00', 'note' => 'ambil langsung'],
        ]);

        $imp = $this->import();
        $cid = (int) DB::table('legacy_map')->where('entity', 'consumable')->where('legacy_id', 1)->value('new_id');
        $mv = fn () => DB::table('stock_movements')->where('stockable_type', \App\Models\Consumable::class)->where('stockable_id', $cid);

        $this->assertSame(3, $imp->stats['consumable_history']);
        $out = (clone $mv())->where('type', 'out')->orderBy('id')->get();
        $this->assertCount(2, $out);                                               // keluar transaksi (2) + keluar langsung (1) = 2 baris
        $this->assertSame([2, 1], $out->pluck('qty')->map(fn ($q) => (int) $q)->all());
        $this->assertStringContainsString('002/IT/BPB/HO/AKP/X/2026', $out[0]->reason);
        $this->assertNotNull($out[0]->employee_id);
        $this->assertSame('ambil langsung', $out[1]->notes);
        $this->assertSame(1, (int) (clone $mv())->where('type', 'in')->where('reason', 'like', 'Masuk 003%')->value('qty'));

        // saldo: jumlah seluruh pergerakan = saldo SIMA (5)
        $sum = (clone $mv())->get()->sum(fn ($m) => $m->type === 'out' ? -$m->qty : $m->qty);
        $this->assertSame(5, (int) $sum);
        $this->assertSame(5, (int) DB::table('consumables')->where('id', $cid)->value('qty'));

        // impor ulang tidak menggandakan apa pun
        $before = DB::table('stock_movements')->count();
        $again = $this->import();
        $this->assertSame($before, DB::table('stock_movements')->count());
        $this->assertArrayNotHasKey('consumable_history', $again->stats);
    }

    public function test_consumable_history_that_exceeds_the_sima_balance_is_recorded_as_an_adjustment(): void
    {
        $L = Schema::connection('legacy');
        $L->create('consumable_transactions', fn ($t) => [$t->integer('id'), $t->text('document_number')->nullable(), $t->text('type')->nullable(), $t->text('transaction_date')->nullable(), $t->integer('supplier_id')->nullable(),
            $t->text('reference')->nullable(), $t->text('target_type')->nullable(), $t->integer('target_id')->nullable(), $t->text('notes')->nullable(), $t->integer('created_by')->nullable(), $t->text('created_at')->nullable(), $t->text('updated_at')->nullable()]);
        $L->create('consumable_transaction_items', fn ($t) => [$t->integer('id'), $t->integer('consumable_transaction_id'), $t->integer('consumable_id'), $t->integer('qty'), $t->double('unit_cost')->nullable(), $t->text('notes')->nullable(), $t->text('created_at')->nullable(), $t->text('updated_at')->nullable()]);
        DB::connection('legacy')->table('consumable_transactions')->insert([['id' => 1, 'document_number' => 'IN-1', 'type' => 'in', 'transaction_date' => '2026-10-08', 'reference' => null, 'target_type' => null, 'target_id' => null, 'notes' => null, 'created_at' => '2026-10-08 10:00:00']]);
        DB::connection('legacy')->table('consumable_transaction_items')->insert([['id' => 1, 'consumable_transaction_id' => 1, 'consumable_id' => 1, 'qty' => 9, 'notes' => null]]);   // masuk 9, saldo SIMA hanya 5

        $imp = $this->import();
        $cid = (int) DB::table('legacy_map')->where('entity', 'consumable')->where('legacy_id', 1)->value('new_id');
        $sum = DB::table('stock_movements')->where('stockable_type', \App\Models\Consumable::class)->where('stockable_id', $cid)->get()->sum(fn ($m) => $m->type === 'out' ? -$m->qty : $m->qty);
        $this->assertSame(5, (int) $sum, 'jumlah pergerakan tetap sama dengan saldo SIMA');
        $this->assertTrue(DB::table('stock_movements')->where('stockable_id', $cid)->where('type', 'adjust')->where('qty', -4)->exists());
        $this->assertNotEmpty(array_filter($imp->warnings, fn ($w) => str_contains($w, 'melebihi saldo')));
    }

    public function test_component_bpk_transactions_enrich_existing_install_movements_without_adding_rows(): void
    {
        $L = Schema::connection('legacy');
        $L->create('component_transactions', fn ($t) => [$t->integer('id'), $t->text('document_number')->nullable(), $t->text('type')->nullable(), $t->text('transaction_date')->nullable(), $t->integer('supplier_id')->nullable(),
            $t->text('reference')->nullable(), $t->text('target_type')->nullable(), $t->integer('target_id')->nullable(), $t->text('notes')->nullable(), $t->integer('created_by')->nullable(), $t->text('created_at')->nullable(), $t->text('updated_at')->nullable()]);
        $L->create('component_transaction_items', fn ($t) => [$t->integer('id'), $t->integer('component_transaction_id'), $t->integer('component_id'), $t->integer('qty'), $t->double('unit_cost')->nullable(), $t->text('notes')->nullable(), $t->text('created_at')->nullable(), $t->text('updated_at')->nullable()]);
        $ci = DB::connection('legacy')->table('components_assets')->orderBy('id')->first();
        $this->assertNotNull($ci, 'fixture harus punya komponen terpasang');
        DB::connection('legacy')->table('component_transactions')->insert([['id' => 1, 'document_number' => '001/IT/BPK/HO/AKP/X/2026', 'type' => 'out', 'transaction_date' => '2026-10-20', 'supplier_id' => null,
            'reference' => 'AKP-ITR2610016', 'target_type' => 'asset', 'target_id' => $ci->asset_id, 'notes' => 'tombol rusak', 'created_by' => null, 'created_at' => '2026-10-20 15:01:21', 'updated_at' => null]]);
        DB::connection('legacy')->table('component_transaction_items')->insert([['id' => 1, 'component_transaction_id' => 1, 'component_id' => $ci->component_id, 'qty' => 1, 'unit_cost' => null, 'notes' => 'SN-123', 'created_at' => null, 'updated_at' => null]]);

        $imp = $this->import();
        $this->assertSame(1, $imp->stats['component_history_enriched']);
        $mv = DB::table('stock_movements')->where('reason', 'like', 'Terpasang 001/IT/BPK/HO/AKP/X/2026%')->first();
        $this->assertNotNull($mv);
        $this->assertSame('install', $mv->type);
        $this->assertStringContainsString('AKP-ITR2610016', $mv->notes);
        $this->assertStringStartsWith('2026-10-20', (string) $mv->moved_at);

        $installs = DB::table('stock_movements')->where('type', 'install')->count();
        $this->import();
        $this->assertSame($installs, DB::table('stock_movements')->where('type', 'install')->count(), 'tidak menambah baris pasang');
    }
}
