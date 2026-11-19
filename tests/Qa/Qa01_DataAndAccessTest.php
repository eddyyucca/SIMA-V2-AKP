<?php

namespace Tests\Qa;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ScanPage;
use App\Models\Accessory;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Category;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\Department;
use App\Models\Disposal;
use App\Models\Employee;
use App\Models\License;
use App\Models\LicenseSeat;
use App\Models\Location;
use App\Models\Repair;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\Vendor;
use Livewire\Livewire;
use Tests\QaTestCase;

class Qa01_DataAndAccessTest extends QaTestCase
{
    public function test_demo_data_is_complete(): void
    {
        $this->assertGreaterThanOrEqual(35, Asset::count());
        $this->assertGreaterThanOrEqual(15, Employee::count());
        $this->assertGreaterThanOrEqual(5, Location::count());
        $this->assertGreaterThanOrEqual(5, Vendor::count());
        $this->assertGreaterThanOrEqual(6, Department::count());
        $this->assertGreaterThanOrEqual(10, Category::count());
        $this->assertGreaterThanOrEqual(8, Accessory::count());
        $this->assertGreaterThanOrEqual(8, Consumable::count());
        $this->assertGreaterThanOrEqual(6, Component::count());
        $this->assertGreaterThanOrEqual(8, License::count());
        $this->assertGreaterThanOrEqual(20, AssetAssignment::count());
        $this->assertGreaterThan(50, StockMovement::count());
        $this->assertGreaterThan(20, LicenseSeat::count());
        $this->assertGreaterThanOrEqual(5, Repair::count());
        $this->assertSame(3, Disposal::count());
        $this->assertSame(2, StockOpname::count());
    }

    public function test_every_asset_has_photo_and_documents_on_disk(): void
    {
        foreach (Asset::all() as $a) {
            $this->assertNotNull($a->photo_path, "{$a->asset_tag} tanpa foto");
            $this->assertTrue(\Storage::disk('public')->exists($a->photo_path), "{$a->asset_tag}: foto hilang");
            $this->assertNotEmpty($a->documents, "{$a->asset_tag} tanpa dokumen");
            foreach ($a->documents as $doc) $this->assertTrue(\Storage::disk('public')->exists($doc), "{$a->asset_tag}: {$doc} hilang");
            $this->assertNotNull($a->serial_number);
            if ($a->ownership === 'owned') $this->assertNotNull($a->po_pr_no);       // aset sewa memakai nomor kontrak
        }
    }

    public function test_every_handover_has_numbered_bast_pdf_and_signatures(): void
    {
        foreach (AssetAssignment::all() as $r) {
            $this->assertMatchesRegularExpression('#^IT/BAST/\d{4}/\d{2}/\d{4}$#', $r->bast_number);
            $this->assertTrue(\Storage::disk('local')->exists($r->bast_pdf_path), "BAST {$r->bast_number} hilang");
            $this->assertStringStartsWith('%PDF', \Storage::disk('local')->get($r->bast_pdf_path));
            if ($r->type !== 'checkin' || $r->signature_receiver_path) {
                $this->assertTrue(\Storage::disk('local')->exists($r->signature_receiver_path ?? 'x') || $r->signature_receiver_path === null);
            }
        }
    }

    public function test_cross_module_integrity_audit_passes(): void
    {
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public static function adminPages(): array
    {
        return array_map(fn ($p) => [$p], ['/admin', '/admin/assets', '/admin/assets/create', '/admin/assets/1', '/admin/assets/1/edit', '/admin/accessories', '/admin/consumables',
            '/admin/components', '/admin/licenses', '/admin/categories', '/admin/departments', '/admin/locations', '/admin/vendors', '/admin/employees',
            '/admin/repairs', '/admin/disposals', '/admin/stock-opnames', '/admin/asset-assignments', '/admin/riwayat-stok-accessories', '/admin/riwayat-stok-consumable', '/admin/riwayat-stok-komponen', '/admin/scan',
            '/admin/offboarding', '/admin/barang-saya', '/admin/laporan', '/admin/import-aset', '/admin/aset-sewa', '/admin/aset-sewa/create', '/admin/brands', '/admin/asset-models', '/admin/ip-address']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('adminPages')]
    public function test_admin_and_staff_can_open_every_page(string $path): void
    {
        $this->as($this->admin())->get($path)->assertOk();
        $this->as($this->staff())->get($path)->assertOk();
    }

    public function test_management_is_read_only(): void
    {
        $m = $this->mgmt();
        foreach (['/admin', '/admin/assets', '/admin/assets/1', '/admin/laporan', '/admin/disposals', '/admin/stock-opnames', '/admin/licenses'] as $p) {
            $this->as($m)->get($p)->assertOk();
        }
        foreach (['/admin/assets/create', '/admin/assets/1/edit', '/admin/offboarding', '/admin/import-aset'] as $p) {
            $this->as($m)->get($p)->assertForbidden();
        }
    }

    public function test_employee_role_is_limited_to_own_items(): void
    {
        $u = $this->plain();
        $this->as($u)->get('/admin/barang-saya')->assertOk()->assertSee('Andi Saputra');
        foreach (['/admin/assets', '/admin/licenses', '/admin/laporan', '/admin/employees', '/admin/offboarding', '/admin/categories', '/admin/riwayat-stok-accessories', '/admin/riwayat-stok-consumable', '/admin/riwayat-stok-komponen'] as $p) {
            $this->as($u)->get($p)->assertForbidden();
        }
        // karyawan biasa tidak memakai dashboard IT Asset: langsung ke kartu System Requisition (daftar asetnya ada di sana; "Barang Saya" tetap bisa dibuka)
        $this->as($u)->get('/admin')->assertRedirect(route('requests.create'));
        $this->as($u)->get('/admin/barang-saya')->assertOk();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        auth()->logout();
        $this->get('/admin/assets')->assertRedirect();
        $this->get('/reports/assets.xlsx')->assertRedirect();
        $this->get('/bast/1')->assertRedirect();
    }

    public function test_permission_matrix(): void
    {
        $perm = fn ($u, $p) => $u->can($p);
        $this->assertTrue($perm($this->admin(), 'delete data'));
        foreach (['staff' => $this->staff(), 'mgmt' => $this->mgmt(), 'plain' => $this->plain()] as $label => $u) {
            $this->assertFalse($perm($u, 'delete data'), "$label tidak boleh hapus data");
        }
        $this->assertTrue($perm($this->mgmt(), 'approve disposal'));
        $this->assertFalse($perm($this->staff(), 'approve disposal'));
        $this->assertTrue($perm($this->staff(), 'manage inventory'));
        $this->assertFalse($perm($this->mgmt(), 'manage inventory'));
        $this->assertFalse($perm($this->staff(), 'manage master'));
        $this->assertTrue($perm($this->admin(), 'manage master'));
    }

    public function test_dashboard_numbers_match_database(): void
    {
        $this->as($this->admin());
        $d = Livewire::test(Dashboard::class)->instance()->data();
        $this->assertSame(Asset::active()->count(), $d['total']);
        $this->assertSame(Asset::where('status', 'deployed')->count(), $d['deployed']);
        $this->assertSame(Asset::where('status', 'available')->count(), $d['available']);
        $this->assertSame(Asset::where('status', 'repair')->count(), $d['repair']);
        $this->assertSame(Asset::where('status', 'lost')->count(), $d['lost']);
        $this->assertGreaterThan(0, $d['lowStock']->count(), 'demo harus punya stok menipis');
        $this->assertGreaterThan(0, $d['licenses']->count());
        $this->assertGreaterThan(0, $d['warranty']->count());
        $this->assertGreaterThan(0, $d['lateRepairs']->count());
        $this->assertGreaterThan(0, $d['resignedHolding']->count());
        $this->assertGreaterThan(0, $d['overSeats']->count());
        $this->assertSame(array_sum($d['byCategory']->all()), $d['total']);
        $this->assertSame(array_sum($d['byLocation']->all()), $d['total']);
        $this->get('/admin')->assertOk()->assertSee('Perlu perhatian')->assertSee('Aktivitas terbaru');
    }

    public function test_scan_page_finds_by_tag_or_serial_and_rejects_unknown(): void
    {
        $this->as($this->staff());
        $a = Asset::first();
        $card = Livewire::test(ScanPage::class)->call('onScan', $a->asset_tag)->assertNoRedirect()->get('card');
        $this->assertSame([$a->id, \App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $a])], [$card['id'], $card['url']]);   // kartu + tautan ke detail
        $this->assertSame($a->id, Livewire::test(ScanPage::class)->call('onScan', $a->serial_number)->get('card')['id']);
        Livewire::test(ScanPage::class)->call('onScan', 'TIDAK-ADA-123')->assertNoRedirect()->assertNotified();
    }
}
