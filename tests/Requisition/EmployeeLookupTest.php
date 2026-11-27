<?php

namespace Tests\Requisition;

use App\Models\Accessory;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ITRequest;
use App\Models\RequestItemLink;
use Tests\RequisitionTestCase;

/** Formulir request terhubung ke database: ketik nama, pilih karyawan, NIK / department / jabatan terisi; saran barang pada ITR. */
class EmployeeLookupTest extends RequisitionTestCase
{
    private function dept(string $name = 'Finance'): Department { return Department::where('name', $name)->first() ?? Department::firstOrFail(); }

    public function test_search_requires_login_and_works_for_every_role_including_plain_users(): void
    {
        $this->getJson('/requisition/lookup/employees?q=an')->assertStatus(401);
        $this->makeRequester(['name' => 'Cari Aku Lookup']);
        foreach (['admin', 'super_admin'] as $role) {
            $this->loginAs($this->makeUser($role))->getJson('/requisition/lookup/employees?q=Cari Aku')->assertOk()->assertJsonPath('data.0.name', 'Cari Aku Lookup');
        }
        // akun User tidak bisa mencari karyawan lain
        $this->loginAs($this->makeUser('user'))->getJson('/requisition/lookup/employees?q=Cari Aku')->assertOk()->assertJsonPath('data', []);
    }

    public function test_search_returns_the_fields_needed_to_fill_the_form_and_nothing_sensitive(): void
    {
        $d = $this->dept('Finance');
        $e = $this->makeRequester(['name' => 'Rahma Lookup', 'nik' => '55501', 'position' => 'Akuntan', 'department_id' => $d->id, 'email' => 'rahma.secret@x.test', 'phone' => '0812']);
        $r = $this->loginAs($this->makeUser('admin'))->getJson('/requisition/lookup/employees?q=Rahma Lookup')->assertOk();
        $this->assertSame(['id' => $e->id, 'name' => 'Rahma Lookup', 'nik' => '55501', 'position' => 'Akuntan', 'department' => $d->name, 'section' => null], $r->json('data.0'));
        $this->assertStringNotContainsString('rahma.secret', $r->getContent());          // tidak ada email / data pribadi lain
        $this->assertStringNotContainsString('0812', $r->getContent());
    }

    public function test_search_matches_name_anywhere_and_nik_prefix_only_active_employees_limited_to_eight(): void
    {
        $this->makeRequester(['name' => 'Wijaya Satu Zq', 'nik' => '77001']);
        $this->makeRequester(['name' => 'Aji Wijaya Dua Zq', 'nik' => '77002']);
        $this->makeRequester(['name' => 'Wijaya Resign Zq', 'nik' => '77003', 'status' => 'resigned']);
        $u = $this->loginAs($this->makeUser('admin'));
        $names = fn ($q) => collect($u->getJson('/requisition/lookup/employees?q=' . urlencode($q))->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['Wijaya Satu Zq', 'Aji Wijaya Dua Zq'], $names('Wijaya'));             // awalan nama lebih dulu, lalu yang lain; resign tidak muncul
        $this->assertSame(['Wijaya Satu Zq'], $names('77001'));                                    // cari lewat NIK
        $this->assertSame([], $names('770019999'));
        $this->assertSame([], $names('w'));                                                         // minimal 2 huruf
        $this->assertSame([], $names(''));
        foreach (range(1, 12) as $i) $this->makeRequester(['name' => "Banyak Orang Zz$i"]);
        $this->assertCount(8, $names('Banyak Orang Zz'));                                           // maksimal 8 saran
    }

    public function test_search_treats_wildcards_literally_and_ignores_oversized_input(): void
    {
        $this->makeRequester(['name' => 'Normal Lookup Wc']);
        $u = $this->loginAs($this->makeUser('admin'));
        $this->assertSame([], $u->getJson('/requisition/lookup/employees?q=' . urlencode('%%'))->assertOk()->json('data'));     // % bukan pola "semua"
        $this->assertSame([], $u->getJson('/requisition/lookup/employees?q=' . urlencode('N_rmal'))->assertOk()->json('data'));  // _ bukan "satu huruf apa saja"
        $this->assertSame([], $u->getJson('/requisition/lookup/employees?q=' . urlencode("x' OR 1=1 --"))->assertOk()->json('data'));
        $u->getJson('/requisition/lookup/employees?q=' . urlencode(str_repeat('a', 5000)))->assertOk();
        $this->assertCount(1, $u->getJson('/requisition/lookup/employees?q=' . urlencode('Normal Lookup'))->json('data'));
    }

    public function test_search_is_rate_limited(): void
    {
        $u = $this->loginAs($this->makeUser('user'));
        $last = null;
        foreach (range(1, 125) as $i) $last = $u->getJson('/requisition/lookup/employees?q=ab')->getStatusCode();
        $this->assertSame(429, $last);
    }

    public function test_item_suggestions_cover_accessories_consumables_and_components_with_stock(): void
    {
        Accessory::create(['name' => 'Mouse Zxq Satu', 'brand_model' => 'Logitech ZX', 'qty_total' => 7, 'min_qty' => 0]);
        Consumable::create(['name' => 'Tinta Zxq Dua', 'unit' => 'rim', 'qty' => 2.5, 'min_qty' => 0]);
        Component::create(['name' => 'RAM Zxq Tiga', 'specs' => 'DDR4 16GB', 'qty_total' => 0, 'min_qty' => 0]);
        $this->getJson('/requisition/lookup/items?q=Zxq')->assertStatus(401);                       // tanpa login ditolak
        $rows = collect($this->loginAs($this->makeUser('user'))->getJson('/requisition/lookup/items?q=Zxq')->assertOk()->json('data'))->keyBy('name');
        $this->assertSame(['accessory:' . Accessory::where('name', 'Mouse Zxq Satu')->value('id'), 'Accessories', 'Logitech ZX', 7], [$rows['Mouse Zxq Satu']['ref'], $rows['Mouse Zxq Satu']['kind'], $rows['Mouse Zxq Satu']['detail'], $rows['Mouse Zxq Satu']['stock']]);
        $this->assertSame([2.5, 'rim', 'Consumable'], [$rows['Tinta Zxq Dua']['stock'], $rows['Tinta Zxq Dua']['unit'], $rows['Tinta Zxq Dua']['kind']]);
        $this->assertSame([0, 'Komponen'], [$rows['RAM Zxq Tiga']['stock'], $rows['RAM Zxq Tiga']['kind']]);                   // stok habis tetap ditampilkan
    }

    public function test_itr_items_picked_from_suggestions_become_links_for_the_it_team(): void
    {
        $u = $this->makeUser('user');
        $mouse = Accessory::create(['name' => 'Mouse Link Test', 'qty_total' => 5, 'min_qty' => 0]);
        $tinta = Consumable::create(['name' => 'Tinta Link Test', 'unit' => 'pcs', 'qty' => 9, 'min_qty' => 0]);
        $this->loginAs($u)->postJson('/requisition/requests', [
            'type' => 'ITR', 'employee_id' => $this->requesterId(), 'title' => 'Perlengkapan', 'description' => 'Uji tautan',
            'items' => [
                ['name' => 'Mouse Link Test', 'qty' => 2, 'ref' => 'accessory:' . $mouse->id],
                ['name' => 'Mouse Link Test', 'qty' => 1, 'ref' => 'accessory:' . $mouse->id],        // barang sama: digabung
                ['name' => 'Tinta Link Test', 'qty' => 3, 'ref' => 'consumable:' . $tinta->id],
                ['name' => 'Barang Ketik Bebas', 'qty' => 1],                                         // tanpa saran: tetap sah
                ['name' => 'Referensi Hantu', 'qty' => 1, 'ref' => 'accessory:999999'],               // tidak ada: diabaikan
            ],
        ])->assertOk();
        $r = ITRequest::latest('id')->firstOrFail();
        $this->assertCount(5, $r->items);
        $links = $r->links()->orderBy('id')->get();
        $this->assertSame([['accessory', $mouse->id, 3.0], ['consumable', $tinta->id, 3.0]], $links->map(fn ($l) => [$l->kind, $l->item_id, (float) $l->qty])->all());
        $this->assertSame($u->id, $links->first()->created_by);
        // ref tidak valid ditolak
        foreach (['asset:1', 'foo:1', 'accessory:abc', 'accessory:'] as $bad) {
            $this->loginAs($u)->postJson('/requisition/requests', ['type' => 'ITR', 'employee_id' => $this->requesterId(), 'title' => 'x', 'description' => 'x', 'items' => [['name' => 'x', 'qty' => 1, 'ref' => $bad]]])
                ->assertStatus(422)->assertJsonValidationErrors('items.0.ref');
        }
        // hanya ITR yang membuat tautan
        $this->loginAs($u)->postJson('/requisition/requests', ['type' => 'WO', 'employee_id' => $this->requesterId(), 'department' => Department::value('name'), 'description' => 'x', 'items' => [['name' => 'Mouse Link Test', 'qty' => 1, 'ref' => 'accessory:' . $mouse->id]]])->assertOk();
        $this->assertSame(0, ITRequest::where('type', 'WO')->latest('id')->first()->links()->count());
    }

    public function test_edit_keeps_the_stored_snapshot_unless_another_employee_is_picked(): void
    {
        $admin = $this->makeUser('admin');
        $orig = $this->makeRequester(['name' => 'Budi Wijaya A', 'nik' => '4001', 'position' => 'Akuntan', 'department_id' => $this->dept('Finance')->id]);
        $this->loginAs($this->makeUser('admin'))->postJson('/requisition/requests', ['type' => 'ITR', 'employee_id' => $orig->id, 'title' => 'T', 'description' => 'D', 'items' => [['name' => 'Laptop', 'qty' => 1]]])->assertOk();
        $r = ITRequest::latest('id')->firstOrFail();

        // data karyawan berubah di database; edit biasa (employee_id sama) TIDAK menimpa catatan request
        $orig->update(['position' => 'Manajer', 'nik' => '4999']);
        $this->loginAs($admin)->patchJson("/requisition/requests/{$r->id}", ['employee_id' => $orig->id, 'employee_name' => 'Budi Wijaya A', 'employee_nik' => '4001', 'department' => 'Finance', 'position' => 'Akuntan', 'title' => 'T2', 'description' => 'D'])->assertOk();
        $r->refresh();
        $this->assertSame(['4001', 'Akuntan', 'T2', $orig->id], [$r->nik, $r->position, $r->title, $r->employee_id]);      // nama DB non-Title Case tidak ditolak

        // memilih karyawan lain: data pemohon diambil dari database karyawan baru
        $new = $this->makeRequester(['name' => 'Karyawan Baru Edit', 'nik' => '5005', 'position' => 'Operator', 'department_id' => $this->dept('GA')->id]);
        $this->loginAs($admin)->patchJson("/requisition/requests/{$r->id}", ['employee_id' => $new->id, 'employee_name' => 'Palsu', 'employee_nik' => '1', 'position' => 'Direktur', 'title' => 'T3', 'description' => 'D'])->assertOk();
        $r->refresh();
        $this->assertSame([$new->id, 'Karyawan Baru Edit', '5005', 'Operator', $this->dept('GA')->name], [$r->employee_id, $r->requester_name, $r->nik, $r->position, $r->department]);

        // karyawan resign tidak bisa dipilih; request lama tanpa tautan tetap bisa diedit seperti biasa
        $res = $this->makeRequester(['name' => 'Karyawan Resign Edit', 'status' => 'resigned']);
        $this->loginAs($admin)->patchJson("/requisition/requests/{$r->id}", ['employee_id' => $res->id, 'title' => 'T4', 'description' => 'D'])->assertStatus(422)->assertJsonValidationErrors('employee_id');
        $legacy = ITRequest::factory()->create(['type' => 'ITR', 'user_id' => $admin->id, 'status' => 'Pending', 'employee_id' => null, 'requester_name' => 'Nama Lama', 'nik' => '12', 'position' => 'Staff', 'department' => 'Finance', 'title' => 'L', 'description' => 'D']);
        $this->loginAs($admin)->patchJson("/requisition/requests/{$legacy->id}", ['employee_name' => 'Nama Lama Baru', 'employee_nik' => '12', 'department' => 'Finance', 'position' => 'Staff', 'title' => 'L2', 'description' => 'D'])->assertOk();
        $this->loginAs($admin)->patchJson("/requisition/requests/{$legacy->id}", ['employee_name' => 'huruf kecil semua', 'employee_nik' => '12', 'position' => 'Staff', 'title' => 'L3', 'description' => 'D'])->assertStatus(422)->assertJsonValidationErrors('employee_name');   // aturan lama tetap untuk yang belum tertaut
    }

    public function test_a_linked_employee_who_resigned_later_can_still_be_edited(): void
    {
        $admin = $this->makeUser('admin');
        $e = $this->makeRequester(['name' => 'Akan Resign']);
        $this->loginAs($this->makeUser('admin'))->postJson('/requisition/requests', ['type' => 'ITR', 'employee_id' => $e->id, 'title' => 'T', 'description' => 'D', 'items' => [['name' => 'Laptop', 'qty' => 1]]])->assertOk();
        $r = ITRequest::latest('id')->firstOrFail();
        $e->update(['status' => 'resigned']);
        $this->loginAs($admin)->patchJson("/requisition/requests/{$r->id}", ['employee_id' => $e->id, 'employee_name' => 'Akan Resign', 'employee_nik' => '123456', 'position' => 'Staff IT', 'title' => 'T9', 'description' => 'D'])->assertOk();
        $this->assertSame('T9', $r->fresh()->title);
    }

    public function test_forms_use_the_picker_department_lists_come_from_the_database_and_pages_load_the_script(): void
    {
        $d = Department::create(['name' => 'Departemen Baru Lookup']);
        $html = $this->loginAs($this->makeUser('user'))->get('/requisition/requests/create')->assertOk()->getContent();
        // 6 formulir buat + 1 formulir edit memakai kotak cari; skrip & alamat pencarian dimuat
        $this->assertSame(7, substr_count($html, 'data-employee-picker'));
        $this->assertSame(6, substr_count($html, 'data-ep-required="1"'));                      // formulir baru wajib memilih; formulir edit tidak
        $this->assertStringContainsString('js/employee-picker.js', $html);
        $this->assertStringContainsString('requisition\/lookup\/employees', $html);                 // alamat pencarian (JSON meng-escape garis miring)
        $this->assertStringContainsString('Departemen Baru Lookup', $html);                       // department dari tabel, bukan daftar tertulis
        $this->assertStringNotContainsString('pattern="[A-Z][a-z]+( [A-Z][a-z]+)*"', $html);      // aturan Title Case di browser dihapus (nama dari database)
        $this->assertStringNotContainsString('Contoh: Dendi Ardiansyah', $html);
        // tidak diisi dari akun login
        $this->assertStringNotContainsString('SR_ME', $html);
        foreach (['/requisition' => $this->makeUser('admin'), '/requisition/peminjaman' => $this->makeUser('admin')] as $url => $user) {
            $page = $this->loginAs($user)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('js/employee-picker.js', $page, $url);
            $this->assertStringContainsString('data-employee-picker', $page, $url);
            $this->assertStringContainsString('Departemen Baru Lookup', $page, $url);
        }
        $this->assertContains('Departemen Baru Lookup', Department::names());
        $this->assertSame(Department::names(), collect(Department::names())->sort()->values()->all());       // urut abjad
    }

    public function test_script_file_is_served_and_valid(): void
    {
        $js = file_get_contents(public_path('js/employee-picker.js'));
        foreach (['data-employee-picker', 'SR_LOOKUP', 'stopImmediatePropagation', 'EmployeePicker', 'ArrowDown', 'syncItemRefs'] as $needle) $this->assertStringContainsString($needle, $js);
    }
}
