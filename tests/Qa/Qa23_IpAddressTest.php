<?php

namespace Tests\Qa;

use App\Filament\Resources\IpAddresses\Pages\ManageIpAddresses;
use App\Models\IpAddress;
use App\Models\Location;
use App\Reports\ReportCatalog;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\QaTestCase;

class Qa23_IpAddressTest extends QaTestCase
{
    private function data(array $o = []): array
    {
        return $o + ['device_name' => 'Switch Uji', 'ip_address' => '192.168.77.5', 'username' => 'ujiadmin', 'password' => 'Rahasia-Uji-123', 'location_id' => Location::first()->id];
    }

    public function test_demo_data_and_page_for_it_staff(): void
    {
        $this->assertGreaterThanOrEqual(8, IpAddress::count());
        foreach ([$this->admin(), $this->staff()] as $u) $this->as($u)->get('/admin/ip-address')->assertOk()->assertSee('List IP Device');
    }

    public function test_create_saves_all_fields_and_encrypts_the_password(): void
    {
        $this->as($this->staff());
        Livewire::test(ManageIpAddresses::class)->callAction('create', $this->data())->assertHasNoActionErrors();
        $r = IpAddress::where('ip_address', '192.168.77.5')->firstOrFail();
        $this->assertSame(['Switch Uji', 'ujiadmin', 'Rahasia-Uji-123'], [$r->device_name, $r->username, $r->password]);
        $this->assertNotNull($r->location_id);
        $raw = DB::table('ip_addresses')->where('id', $r->id)->value('password');
        $this->assertStringNotContainsString('Rahasia-Uji-123', $raw, 'password terenkripsi di database');
        $this->assertNotNull($r->password_changed_at);
        $this->assertArrayNotHasKey('password', $r->toArray(), 'password tidak ikut serialisasi');
    }

    public function test_validation(): void
    {
        $this->as($this->staff());
        $page = fn () => Livewire::test(ManageIpAddresses::class);
        $page()->callAction('create', $this->data(['device_name' => '']))->assertHasActionErrors(['device_name' => 'required']);
        $page()->callAction('create', $this->data(['ip_address' => '']))->assertHasActionErrors(['ip_address' => 'required']);
        foreach (['999.1.1.1', 'bukan-ip', '192.168.1', '192.168.1.1/24'] as $bad) {
            $page()->callAction('create', $this->data(['ip_address' => $bad]))->assertHasActionErrors(['ip_address']);
        }
        $page()->callAction('create', $this->data(['ip_address' => '2001:db8::1', 'device_name' => 'Server IPv6']))->assertHasNoActionErrors();
        $dup = IpAddress::first();
        $page()->callAction('create', $this->data(['ip_address' => $dup->ip_address]))->assertHasActionErrors(['ip_address']);
        // username, password, lokasi opsional
        $page()->callAction('create', ['device_name' => 'Tanpa Kredensial', 'ip_address' => '192.168.77.9'])->assertHasNoActionErrors();
        $this->assertNull(IpAddress::where('ip_address', '192.168.77.9')->value('password'));
        $this->assertDatabaseMissing('ip_addresses', ['ip_address' => '192.168.1']);
    }

    public function test_edit_keeps_own_ip_and_changing_password_is_tracked_without_leaking_it(): void
    {
        $this->as($this->staff());
        $r = IpAddress::create($this->data());
        $before = $r->fresh()->password_changed_at;
        $this->travel(2)->minutes();
        Livewire::test(ManageIpAddresses::class)->callAction(TestAction::make('edit')->table($r), $this->data(['device_name' => 'Switch Ubah', 'password' => 'Baru-Rahasia-456']))->assertHasNoActionErrors();
        $r->refresh();
        $this->assertSame(['Switch Ubah', 'Baru-Rahasia-456'], [$r->device_name, $r->password]);
        $this->assertTrue($r->password_changed_at->gt($before), 'waktu ganti password tercatat');
        foreach (Activity::where('subject_type', IpAddress::class)->where('subject_id', $r->id)->get() as $log) {
            $this->assertStringNotContainsString('Rahasia', json_encode($log->properties), 'password tidak boleh masuk audit log');
            $this->assertStringNotContainsString('Rahasia', (string) $log->description);
        }
        $this->assertTrue(Activity::where('subject_id', $r->id)->where('subject_type', IpAddress::class)->exists());
    }

    public function test_reveal_shows_credentials_and_is_logged(): void
    {
        $this->as($this->staff());
        $r = IpAddress::create($this->data());
        $html = view('filament.ip-credentials', ['record' => $r])->render();
        foreach (['Switch Uji', '192.168.77.5', 'ujiadmin', 'Rahasia-Uji-123'] as $needle) $this->assertStringContainsString($needle, $html);
        Livewire::test(ManageIpAddresses::class)->assertTableActionVisible('reveal', $r)
            ->mountTableAction('reveal', $r);
        $this->assertTrue(Activity::where('description', 'Melihat kredensial perangkat')->where('subject_id', $r->id)->where('causer_id', $this->staff()->id)->exists());
        $empty = IpAddress::create(['device_name' => 'Kosong', 'ip_address' => '192.168.77.20']);
        Livewire::test(ManageIpAddresses::class)->assertTableActionHidden('reveal', $empty);
    }

    public function test_table_masks_password_searches_and_filters_by_location(): void
    {
        $this->as($this->staff());
        $loc = Location::where('name', 'Cabang Makassar')->firstOrFail();
        $mine = IpAddress::where('location_id', $loc->id)->firstOrFail();
        $other = IpAddress::where('location_id', '!=', $loc->id)->firstOrFail();
        $t = Livewire::test(ManageIpAddresses::class)
            ->assertTableColumnExists('device_name')->assertTableColumnExists('ip_address')->assertTableColumnExists('username')->assertTableColumnExists('password')->assertTableColumnExists('location.name')
            ->assertTableColumnStateSet('password', '••••••••', $mine);
        $t->assertDontSee($mine->password);
        $t->searchTable($mine->ip_address)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$other]);
        Livewire::test(ManageIpAddresses::class)->searchTable($mine->device_name)->assertCanSeeTableRecords([$mine]);
        Livewire::test(ManageIpAddresses::class)->searchTable($mine->username)->assertCanSeeTableRecords([$mine]);
        Livewire::test(ManageIpAddresses::class)->filterTable('location_id', $loc->id)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$other]);
    }

    public function test_access_is_limited_to_it_staff_and_admin(): void
    {
        $r = IpAddress::first();
        foreach ([$this->mgmt(), $this->plain()] as $u) {
            $this->as($u)->get('/admin/ip-address')->assertForbidden();
            $this->assertFalse($u->can('viewAny', IpAddress::class));
            $this->assertFalse($u->can('view', $r));
            $this->assertFalse($u->can('update', $r));
        }
        $this->assertTrue($this->staff()->can('update', $r));
        $this->assertFalse($this->staff()->can('delete', $r), 'hapus hanya Admin');
        $this->assertTrue($this->admin()->can('delete', $r));
        $this->assertFalse($this->admin()->can('forceDelete', $r));
        auth()->logout();
        $this->get('/admin/ip-address')->assertRedirect();
    }

    public function test_delete_is_soft_and_restorable_by_admin(): void
    {
        $this->as($this->admin());
        $r = IpAddress::create($this->data());
        Livewire::test(ManageIpAddresses::class)->callAction(TestAction::make('delete')->table($r))->assertHasNoActionErrors();
        $this->assertSoftDeleted('ip_addresses', ['id' => $r->id]);
        // IP yang sudah dihapus boleh dipakai lagi oleh perangkat baru
        Livewire::test(ManageIpAddresses::class)->callAction('create', $this->data(['device_name' => 'Pengganti']))->assertHasNoActionErrors();
        $this->assertSame(1, IpAddress::where('ip_address', '192.168.77.5')->count());
        Livewire::test(ManageIpAddresses::class)->removeTableFilter('trashed')->filterTable('trashed', true)->assertCanSeeTableRecords([$r]);
    }

    public function test_report_excludes_passwords_and_exports(): void
    {
        $this->as($this->admin());
        IpAddress::create($this->data());
        [$title, $head, $rows] = ReportCatalog::build('ip_addresses', []);
        $this->assertSame('List IP Device', $title);
        $this->assertSame(['Nama Perangkat', 'IP Address', 'Username', 'Lokasi', 'Catatan'], $head);
        $this->assertSame(IpAddress::count(), count($rows));
        $this->assertStringNotContainsString('Rahasia-Uji-123', json_encode($rows));
        foreach ($rows as $r) $this->assertCount(count($head), $r);
        $this->get('/reports/ip_addresses.xlsx')->assertOk();
        $this->get('/reports/ip_addresses.pdf')->assertOk();
        $one = Location::where('name', 'Cabang Palu')->value('id');
        $this->assertLessThan(count($rows), count(ReportCatalog::build('ip_addresses', ['location_id' => $one])[2]));
    }
}
